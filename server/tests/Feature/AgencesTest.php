<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Multi-agences : agences, rattachement des documents, caisses et stocks, accès limité, tableau de bord comparatif. */
class AgencesTest extends TestCase
{
    public function test_branches_scope_documents_stock_cash_and_access(): void
    {
        [$company, , $admin] = $this->makeCompany('Distribution Kanga');
        $api = fn (?string $branch = null) => $branch ? $this->api($admin)->withHeaders(['X-Branch-Id' => $branch]) : $this->api($admin);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();

        // Deux agences, chacune avec son dépôt et sa caisse
        $plateau = $api()->postJson('/api/agences', ['name' => 'Agence Plateau', 'code' => 'PLT', 'city' => 'Abidjan', 'create_warehouse' => true, 'create_cash' => true, 'is_head_office' => true])->assertOk()->json('data');
        $yop = $api()->postJson('/api/agences', ['name' => 'Agence Yopougon', 'code' => 'YOP', 'city' => 'Abidjan', 'create_warehouse' => true, 'create_cash' => true])->assertOk()->json('data');
        $this->assertNotNull($plateau['warehouse_id']);
        $this->assertSame('Caisse Agence Plateau', $plateau['cash_account_name']);
        $this->assertSame($yop['id'], DB::table('warehouses')->where('id', $yop['warehouse_id'])->value('branch_id'));
        $this->assertCount(2, $api()->getJson('/api/bootstrap')->json('branches'));

        // Vendeuse rattachée à l'agence du Plateau seulement
        $roles = collect($api()->getJson('/api/roles')->json('data'))->keyBy('name');
        $u = $api()->postJson('/api/users', ['name' => 'Awa', 'email' => 'awa@kanga.ci', 'password' => 'caisse123', 'role_id' => $roles['Caissier']['id'], 'branch_ids' => [$plateau['id']]])->assertCreated()->json('data');
        $this->assertSame([$plateau['id']], $u['branch_ids']);
        $awa = User::find($u['id'])->createToken('t')->plainTextToken;
        $boot = $this->api($awa)->getJson('/api/bootstrap')->json();
        $this->assertSame([$plateau['id']], $boot['user']['branch_ids']);
        $this->assertSame(['Agence Plateau'], collect($boot['branches'])->pluck('name')->all());

        // Stock dans les deux dépôts
        $prod = $api()->postJson('/api/core/products', ['name' => 'Riz 25 kg', 'sale_price' => 15000, 'tax_rate' => 0, 'purchase_price' => 12000])->json('data.id');
        foreach ([$plateau, $yop] as $b) {
            $api()->postJson('/api/stock/moves', ['product_id' => $prod, 'warehouse_id' => $b['warehouse_id'], 'quantity' => 100, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();
        }
        $sale = function (string $token, int $qty, ?string $branch = null, array $extra = []) use ($prod) {
            $doc = $this->uuid();
            $h = $branch ? ['X-Branch-Id' => $branch] : [];
            $res = $this->api($token)->withHeaders($h)->postJson('/api/sync/push', ['mutations' => [
                ['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $doc, 'data' => $extra + ['type' => 'receipt', 'date' => now()->toDateString(), 'status' => 'validated',
                    'lines' => [['product_id' => $prod, 'description' => 'Riz 25 kg', 'quantity' => $qty, 'unit_price' => 15000, 'tax_rate' => 0]]]],
                ['id' => $this->uuid(), 'entity' => 'sales_payments', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => ['document_id' => $doc, 'date' => now()->toDateString(), 'amount' => $qty * 15000, 'method' => 'cash']],
            ]])->assertOk()->json('results');

            return [$doc, $res];
        };
        // L'administrateur vend à Yopougon (agence de travail), Awa au Plateau (sa seule agence)
        [$d1] = $sale($admin, 4, $yop['id']);
        [$d2, $r2] = $sale($awa, 2);
        $this->assertSame("applied", $r2[0]["status"], json_encode($r2));
        $this->assertSame($yop['id'], DB::table('trade_documents')->where('id', $d1)->value('branch_id'));
        $this->assertSame($plateau['id'], DB::table('trade_documents')->where('id', $d2)->value('branch_id'));
        // Le stock sort du dépôt de l'agence, l'encaissement va dans la caisse de l'agence
        $this->assertEquals(-4, DB::table('stock_moves')->where('document_id', $d1)->where('warehouse_id', $yop['warehouse_id'])->sum('quantity'));
        $this->assertEquals(-2, DB::table('stock_moves')->where('document_id', $d2)->where('warehouse_id', $plateau['warehouse_id'])->sum('quantity'));
        $this->assertSame($plateau['cash_account_id'], DB::table('payments')->where('document_id', $d2)->value('treasury_account_id'));

        // Awa ne peut pas vendre pour Yopougon
        [, $res] = $sale($awa, 1, null, ['branch_id' => $yop['id']]);
        $this->assertSame('rejected', $res[0]['status'], json_encode($res));

        // Synchronisation : Awa ne reçoit que les ventes, dépôts et caisses de son agence
        $pull = $this->api($awa)->getJson('/api/sync/pull?cursor=0&limit=2000')->json('changes');
        $docs = collect($pull['sales_documents']['upserts'] ?? [])->pluck('id')->all();
        $this->assertContains($d2, $docs);
        $this->assertNotContains($d1, $docs);
        $this->assertNotContains($yop['warehouse_id'], collect($pull['warehouses']['upserts'] ?? [])->pluck('id')->all());
        $this->assertSame([$plateau['id']], collect($pull['branches']['upserts'] ?? [])->pluck('id')->all());
        // Niveaux de stock : seulement son dépôt
        $levels = collect($this->api($awa)->getJson('/api/stock/levels')->json('data'));
        $this->assertSame([$plateau['warehouse_id']], $levels->pluck('warehouse_id')->unique()->values()->all());
        $this->assertEquals(98, $levels->firstWhere('warehouse_id', $plateau['warehouse_id'])['quantity']);

        // Transfert du Plateau vers Yopougon (dépôt d'une autre agence) : autorisé pour l'administrateur
        $api()->postJson('/api/stock/transfer', ['product_id' => $prod, 'from_warehouse_id' => $plateau['warehouse_id'], 'to_warehouse_id' => $yop['warehouse_id'], 'quantity' => 10])->assertCreated();

        // Tableau de bord comparatif
        $s = $api()->getJson('/api/agences/summary?from='.now()->startOfYear()->toDateString().'&to='.now()->toDateString())->assertOk()->json('data');
        $rows = collect($s['rows'])->keyBy('name');
        $this->assertEquals([60000, 30000], [$rows['Agence Yopougon']['sales'], $rows['Agence Plateau']['sales']]);
        $this->assertEquals([12000, 6000], [$rows['Agence Yopougon']['margin'], $rows['Agence Plateau']['margin']]);
        $this->assertEquals(30000, $rows['Agence Plateau']['cash']);
        $this->assertEquals(106 * 12000, $rows['Agence Yopougon']['stock_value']); // 100 − 4 + 10
        $this->assertEquals(90000, $s['totals']['sales']);
        $this->api($awa)->getJson('/api/agences/summary')->assertForbidden();

        // Rapports : axe « Agence » ; tableau de bord filtré par agence
        $this->assertArrayHasKey('branch', \App\Modules\Rapports\Sources::all()['ventes']['dimensions']);
        $api()->getJson('/api/dashboard?branch_id='.$plateau['id'])->assertOk();

        // Une agence avec des documents ne se supprime pas
        $api()->deleteJson('/api/agences/'.$plateau['id'])->assertStatus(422);
    }
}
