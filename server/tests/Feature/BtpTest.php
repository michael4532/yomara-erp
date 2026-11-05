<?php

namespace Tests\Feature;

use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** BTP : chantier, bordereau chiffré, devis, marché, avance, situations (remboursement d'avance, retenue de garantie), réception, libération, journal. */
class BtpTest extends TestCase
{
    public function test_site_estimate_contract_advance_situations_retention_and_log(): void
    {
        [$company, , $token] = $this->makeCompany('Bâtir CI');
        $api = fn () => $this->api($token);

        $s = $api()->postJson('/api/btp/sites', ['name' => 'Villa duplex Bingerville', 'client_name' => 'M. Aka Jean-Baptiste', 'client_phone' => '0707080910', 'address' => 'Bingerville, cité Ebrié',
            'advance_rate' => 10, 'retention_rate' => 5, 'tax_rate' => 18])->assertCreated()->json('data');
        $this->assertSame('study', $s['status']);
        $this->assertSame('Chantier — Villa duplex Bingerville', DB::table('prj_projects')->where('id', $s['project_id'])->value('name'));
        $id = $s['id'];

        // Bordereau : 3 ouvrages en 2 lots, déboursé sec et prix de vente
        $d = $api()->putJson("/api/btp/sites/$id/items", ['items' => [
            ['lot' => 'Gros œuvre', 'code' => '1.1', 'designation' => 'Béton armé de fondation', 'unit' => 'm3', 'quantity' => 20, 'unit_cost' => 85000, 'unit_price' => 120000],
            ['lot' => 'Gros œuvre', 'code' => '1.2', 'designation' => 'Maçonnerie d\'agglos de 15', 'unit' => 'm2', 'quantity' => 300, 'unit_cost' => 9000, 'unit_price' => 13000],
            ['lot' => 'Plomberie', 'code' => '2.1', 'designation' => 'Plomberie sanitaire complète', 'unit' => 'ff', 'quantity' => 1, 'unit_cost' => 1200000, 'unit_price' => 1800000],
        ]])->assertOk()->json('data');
        $this->assertEquals([8100000, 5600000, 2500000, 30.9, 1.446], [$d['estimate']['price'], $d['estimate']['cost'], $d['estimate']['margin'], $d['estimate']['margin_rate'], $d['estimate']['coefficient']]);
        $this->assertEquals(5600000.0, (float) DB::table('prj_projects')->where('id', $s['project_id'])->value('budget_amount'));
        $items = collect($d['items'])->keyBy('code');

        // Devis client, puis marché signé
        $d = $api()->postJson("/api/btp/sites/$id/quote")->assertOk()->json('data');
        $q = SalesDocument::withoutGlobalScopes()->with('lines')->find($d['quote_id']);
        $this->assertEquals(['quote', 9558000.0, 3, $s['project_id']], [$q->type, (float) $q->total, $q->lines->count(), $q->project_id]);
        $api()->postJson("/api/btp/sites/$id/situations", ['progress' => []])->assertStatus(422);
        $d = $api()->postJson("/api/btp/sites/$id/accept", ['reference' => 'MARCHE-2026-014'])->assertOk()->json('data');
        $this->assertEquals(['in_progress', 8100000.0], [$d['status'], (float) $d['contract_amount']]);
        $this->assertSame('active', DB::table('prj_projects')->where('id', $s['project_id'])->value('status'));

        // Avance de démarrage 10 %
        $d = $api()->postJson("/api/btp/sites/$id/advance")->assertOk()->json('data');
        $this->assertEquals(955800.0, (float) SalesDocument::withoutGlobalScopes()->find($d['advance_invoice_id'])->total);
        $api()->postJson("/api/btp/sites/$id/advance")->assertStatus(422);

        // Situation n° 1 : aperçu puis facture (remboursement de l'avance au prorata, retenue 5 % du TTC)
        $p1 = [$items['1.1']['id'] => 20, $items['1.2']['id'] => 150];
        $pv = $api()->postJson("/api/btp/sites/$id/situations/preview", ['progress' => $p1])->assertOk()->json('data');
        $this->assertEquals([1, 4350000, 435000, 704700, 230985, 4388715, 53.7], [$pv['number'], $pv['works_amount'], $pv['advance_repaid'], $pv['tax'], $pv['retention'], $pv['net'], $pv['progress_pct']]);
        $d = $api()->postJson("/api/btp/sites/$id/situations", ['progress' => $p1, 'date' => now()->toDateString()])->assertCreated()->json('data');
        $inv = SalesDocument::withoutGlobalScopes()->with('lines')->find($d['situations'][0]['invoice_id']);
        $this->assertEquals([4388715.0, 3, -1.0], [(float) $inv->total, $inv->lines->count(), (float) $inv->lines[2]->quantity]);
        $api()->postJson("/api/btp/sites/$id/situations", ['progress' => $p1])->assertStatus(422)->assertJsonFragment(['message' => 'Aucun avancement depuis la situation précédente.']);

        // Situation n° 2 : quantités cumulées (plafonnées au marché)
        $d = $api()->postJson("/api/btp/sites/$id/situations", ['progress' => [$items['1.2']['id'] => 999, $items['2.1']['id'] => 0.5]])->assertCreated()->json('data');
        $x = $d['situations'][1];
        $this->assertEquals([2, 7200000, 4350000, 2850000, 285000, 151335, 2875365], [$x['number'], $x['works_cumul'], $x['works_previous'], $x['works_amount'], $x['advance_repaid'], $x['retention'], $x['net']]);
        $this->assertEquals([88.9, 900000, 720000, 382320], [$d['progress'], $d['remaining'], $d['advance_repaid'], $d['retention_held']]);
        $this->assertEquals(955800 + 4388715 + 2875365, $d['invoiced']);
        $api()->putJson("/api/btp/sites/$id/items", ['items' => []])->assertStatus(422);

        // Décomptes PDF
        $this->assertStringStartsWith('%PDF', $api()->get('/api/btp/situations/'.$x['id'].'/pdf')->assertOk()->getContent());
        $this->assertStringStartsWith('%PDF', $api()->get("/api/btp/sites/$id/estimate.pdf?internal=1")->assertOk()->getContent());

        // Journal de chantier
        $d = $api()->postJson("/api/btp/sites/$id/logs", ['date' => now()->toDateString(), 'weather' => 'pluie', 'workers' => 12, 'work_done' => 'Coulage dalle haute RDC', 'incidents' => 'Retard livraison ciment'])->assertCreated()->json('data');
        $this->assertSame('Coulage dalle haute RDC', $d['logs'][0]['work_done']);

        // Réception, libération de la retenue de garantie
        $api()->postJson("/api/btp/sites/$id/release")->assertStatus(422);
        $api()->postJson("/api/btp/sites/$id/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $d = $api()->postJson("/api/btp/sites/$id/release")->assertOk()->json('data');
        $this->assertEquals(['closed', 382320.0, 0], [$d['status'], (float) SalesDocument::withoutGlobalScopes()->find($d['release_invoice_id'])->total, $d['retention_held']]);

        // Tableau de bord
        $api()->postJson('/api/btp/sites', ['name' => 'Réfection bureaux Plateau', 'client_name' => 'Banque Atlantique'])->assertCreated();
        $b = $api()->getJson('/api/btp/dashboard')->assertOk()->json('data');
        $this->assertEquals([2, 1, 0], [count($b['sites']), $b['studies'], $b['active']]);
    }

    public function test_site_foreman_can_only_log(): void
    {
        [, , $token] = $this->makeCompany('Bâtir Plus');
        $site = $this->api($token)->postJson('/api/btp/sites', ['name' => 'Immeuble R+3', 'client_name' => 'SCI Les Lauriers'])->json('data.id');
        $role = $this->api($token)->postJson('/api/roles', ['name' => 'Chef de chantier', 'permissions' => ['btp.view', 'btp.log']])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/users', ['name' => 'Chef', 'email' => 'chef@batir.ci', 'password' => 'chantier123', 'role_id' => $role])->assertCreated();
        $t = \App\Models\User::where('email', 'chef@batir.ci')->first()->createToken('t')->plainTextToken;
        $this->api($t)->postJson("/api/btp/sites/$site/logs", ['date' => now()->toDateString(), 'workers' => 8, 'work_done' => 'Ferraillage poteaux'])->assertCreated();
        $this->api($t)->putJson("/api/btp/sites/$site/items", ['items' => []])->assertForbidden();
        $this->api($t)->postJson("/api/btp/sites/$site/accept")->assertForbidden();
    }
}
