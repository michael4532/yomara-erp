<?php

namespace Tests\Feature;

use App\Modules\ImportExport\Sheet;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TresorerieTest extends TestCase
{
    public function test_accounts_balances_operations_transfer_statement_import_matching_forecast_and_accounting(): void
    {
        [, , $token] = $this->makeCompany('Cabinet Kouadio');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['tr_operations']);

        // Comptes par défaut : caisse, banque, Mobile Money
        $sum = collect($api()->getJson('/api/tresorerie/summary')->assertOk()->json('data'))->keyBy('kind');
        $this->assertSame(['cash', 'bank', 'mobile_money'], $sum->keys()->all());
        $acc = collect($api()->getJson('/api/tresorerie/accounts')->json('data'))->keyBy('kind');
        $this->assertSame([null, null, null], [$acc['bank']['gl_code'], $acc['cash']['gl_code'], $acc['mobile_money']['gl_code']]); // comptes du plan paramétré
        $api()->putJson("/api/tresorerie/accounts/{$acc['bank']['id']}", ['name' => 'SGCI Plateau', 'opening_balance' => 1000000, 'opening_date' => now()->subMonth()->toDateString()])->assertOk();
        $boa = $api()->postJson('/api/tresorerie/accounts', ['name' => 'BOA Marcory', 'kind' => 'bank', 'methods' => []])->assertCreated()->json('data');
        $this->assertSame('5211', $boa['gl_code']);

        // Factures et encaissements : virement → banque par défaut, espèces → caisse
        $client = $api()->postJson('/api/core/partners', ['name' => 'Société Ivoirienne de Négoce', 'type' => 'customer'])->json('data.id');
        $inv = function (float $amount) use ($api, $client) {
            $d = $api()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $client, 'date' => now()->subDays(5)->toDateString(), 'due_date' => now()->addDays(10)->toDateString(),
                'lines' => [['description' => 'Honoraires', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0]]])->json('data');

            return $api()->postJson("/api/ventes/documents/{$d['id']}/validate")->assertOk()->json('data');
        };
        $f1 = $inv(500000);
        $f2 = $inv(250000);
        $f3 = $inv(180000);
        $pay = $api()->postJson('/api/ventes/payments', ['document_id' => $f1['id'], 'date' => now()->subDays(3)->toDateString(), 'amount' => 500000, 'method' => 'bank_transfer', 'reference' => 'VIR SIN'])->assertCreated()->json('data');
        $this->assertSame($acc['bank']['id'], $pay['treasury_account_id']);
        $api()->postJson('/api/ventes/payments', ['document_id' => $f2['id'], 'date' => now()->subDays(2)->toDateString(), 'amount' => 50000, 'method' => 'cash'])->assertCreated()->assertJsonPath('data.treasury_account_id', $acc['cash']['id']);

        // Dépenses diverses et virement interne banque → caisse
        $op = fn (array $o) => $api()->postJson('/api/tresorerie/operations', $o + ['date' => now()->subDays(2)->toDateString()]);
        $loyer = $op(['account_id' => $acc['bank']['id'], 'amount' => -350000, 'label' => 'Loyer octobre', 'category' => 'rent'])->assertCreated()->json('data');
        $op(['account_id' => $acc['bank']['id'], 'amount' => -100000, 'label' => 'Retrait', 'category' => 'transfer'])->assertStatus(422);
        $tid = $this->uuid();
        $op(['account_id' => $acc['bank']['id'], 'amount' => -200000, 'label' => 'Alimentation caisse', 'category' => 'transfer', 'transfer_id' => $tid])->assertCreated();
        $op(['account_id' => $acc['cash']['id'], 'amount' => 200000, 'label' => 'Alimentation caisse', 'category' => 'transfer', 'transfer_id' => $tid])->assertCreated();
        $sum = collect($api()->getJson('/api/tresorerie/summary')->json('data'))->keyBy('id');
        $this->assertEquals(1000000 + 500000 - 350000 - 200000, $sum[$acc['bank']['id']]['balance']);
        $this->assertEquals(250000, $sum[$acc['cash']['id']]['balance']);

        // Comptabilité : loyer 622 / 521, virement via 585 (soldé), encaissement en banque 521
        $rows = collect($api()->getJson('/api/compta/balance?to='.now()->addDay()->toDateString())->json('data.rows'))->keyBy('code');
        $this->assertEquals(350000, (float) $rows['622']['balance']);
        $this->assertEquals(0.0, (float) ($rows['585']['balance'] ?? 0));
        // Solde d'ouverture passé en à-nouveaux (521 / 471) : la balance concorde avec le solde du compte
        $this->assertEquals(1000000 + 500000 - 350000 - 200000, (float) $rows['521']['balance']);
        $this->assertEquals($sum[$acc['bank']['id']]['balance'], (float) $rows['521']['balance']);
        $this->assertEquals(-1000000, (float) $rows['471']['balance']);

        // Relevé bancaire (avec lignes d'en-tête de la banque, débit / crédit séparés)
        $path = sys_get_temp_dir().'/'.uniqid().'_releve.xlsx';
        Sheet::write($path, ['SGCI — Relevé de compte', '', '', '', ''], [
            ['Compte 01234 56789', '', '', '', ''],
            ['Date opération', 'Libellé', 'Débit', 'Crédit', 'Solde'],
            [now()->subDays(2)->format('d/m/Y'), 'VIR RECU STE IVOIRIENNE NEGOCE FAC '.$f1['number'], '', '500 000', '1 500 000'],
            [now()->subDays(2)->format('d/m/Y'), 'PRLV LOYER OCTOBRE', '350 000', '', '1 150 000'],
            [now()->subDays(1)->format('d/m/Y'), 'RETRAIT GUICHET', '200 000', '', '950 000'],
            [now()->subDays(1)->format('d/m/Y'), 'FRAIS TENUE DE COMPTE', '5 900', '', '944 100'],
            [now()->format('d/m/Y'), 'VIR RECU SIN SOLDE', '', '180 000', '1 124 100'],
        ]);
        $a = $api()->post("/api/tresorerie/accounts/{$acc['bank']['id']}/statement/analyze", ['file' => new UploadedFile($path, 'releve.xlsx', null, null, true)])->assertOk()->json('data');
        $this->assertEquals(['date' => 0, 'label' => 1, 'debit' => 2, 'credit' => 3, 'balance' => 4], $a['mapping']);
        $imp = fn () => $api()->postJson("/api/tresorerie/accounts/{$acc['bank']['id']}/statement/import", ['file' => $a['file'], 'filename' => 'releve.xlsx', 'mapping' => $a['mapping']])->assertOk()->json('data');
        $r = $imp();
        $this->assertSame([5, 0, 3], [$r['imported'], $r['duplicates'], $r['matched']]); // encaissement, loyer, virement interne
        $this->assertSame([0, 5], [$imp()['imported'], $imp()['duplicates']]); // relevé déjà importé

        $lines = collect($api()->getJson("/api/tresorerie/accounts/{$acc['bank']['id']}/statement/lines?status=all")->json('data.lines'))->keyBy('label');
        $this->assertSame('payment', $lines['VIR RECU STE IVOIRIENNE NEGOCE FAC '.$f1['number']]['match_type']);
        // Frais bancaires : opération créée depuis la ligne
        $api()->postJson('/api/tresorerie/statement-lines/'.$lines['FRAIS TENUE DE COMPTE']['id'], ['action' => 'operation', 'category' => 'bank_fees'])->assertOk()->assertJsonPath('data.status', 'matched');
        // Virement du client pour la 3e facture : encaissement créé et rapproché
        $c = $api()->getJson('/api/tresorerie/statement-lines/'.$lines['VIR RECU SIN SOLDE']['id'].'/candidates')->assertOk()->json('data');
        $this->assertSame($f3['id'], $c['documents'][0]['id']);
        $api()->postJson('/api/tresorerie/statement-lines/'.$lines['VIR RECU SIN SOLDE']['id'], ['action' => 'payment', 'document_id' => $f3['id']])->assertOk();
        $this->assertSame('paid', $api()->getJson("/api/ventes/documents/{$f3['id']}")->json('data.payment_status'));
        $sum = collect($api()->getJson('/api/tresorerie/summary')->json('data'))->keyBy('id');
        $this->assertSame(0, $sum[$acc['bank']['id']]['open_lines']);
        $this->assertEquals(1124100, $sum[$acc['bank']['id']]['statement_balance']);
        $this->assertEquals(1124100, $sum[$acc['bank']['id']]['balance']); // livre = banque

        // Opération rapprochée : montant verrouillé ; annulation du rapprochement puis modification
        $api()->putJson("/api/tresorerie/operations/{$loyer['id']}", ['amount' => -300000])->assertStatus(422);
        $api()->putJson("/api/tresorerie/operations/{$loyer['id']}", ['label' => 'Loyer octobre (bureau)'])->assertOk();
        $api()->deleteJson("/api/tresorerie/operations/{$loyer['id']}")->assertStatus(422);
        $api()->postJson('/api/tresorerie/statement-lines/'.$lines['PRLV LOYER OCTOBRE']['id'], ['action' => 'unmatch'])->assertOk();
        $api()->putJson("/api/tresorerie/operations/{$loyer['id']}", ['amount' => -300000])->assertOk();

        // Prévision : reste de la 2e facture (200 000) attendu, loyer mensuel prévu
        $api()->postJson('/api/tresorerie/forecasts', ['label' => 'Loyer', 'amount' => -350000, 'date' => now()->addMonth()->startOfMonth()->toDateString(), 'repeat' => 'monthly'])->assertCreated();
        $fc = $api()->getJson('/api/tresorerie/forecast?weeks=12')->assertOk()->json('data');
        $this->assertCount(12, $fc['weeks']);
        $this->assertEquals(200000, collect($fc['weeks'])->sum('in'));
        $this->assertLessThanOrEqual(-700000, collect($fc['weeks'])->sum('out'));

        // Tableau de bord
        $w = collect($api()->getJson('/api/dashboard')->json('data'))->keyBy('key');
        $this->assertArrayHasKey('tr_cash', $w->all());
    }
}
