<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Modules\MarchesPublics\MarchesHooks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Appels d'offres et marchés : dossier de soumission, pièces administratives, cautions, marché, décomptes, facture, alertes. */
class MarchesTest extends TestCase
{
    public function test_tender_to_contract_statements_invoice_and_alerts(): void
    {
        [$company, $admin, $token] = $this->makeCompany('BTP Marchés SARL');
        $api = fn () => $this->api($token);
        $client = $this->uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'partners', 'op' => 'upsert', 'record_id' => $client, 'data' => ['name' => 'Mairie de Cocody']]]])->assertOk();

        $api()->getJson('/api/marches_publics/schema')->assertOk();
        $this->assertNotNull(Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Chargé des marchés')->first());
        // pièces administratives permanentes : l'attestation fiscale expirera avant le dépôt
        $tax = $api()->postJson('/api/marches_publics/records/documents', ['title' => 'Attestation de régularité fiscale', 'kind' => 'tax', 'expires_on' => now()->addDays(5)->toDateString(), 'file_url' => 'https://ged/fisc.pdf'])
            ->assertCreated()->assertJsonPath('data.alert', 'Expire dans 5 j')->json('data');
        $api()->postJson('/api/marches_publics/records/documents', ['title' => 'Attestation CNPS', 'kind' => 'cnps', 'expires_on' => now()->addMonths(3)->toDateString(), 'file_url' => 'https://ged/cnps.pdf'])
            ->assertCreated()->assertJsonPath('data.alert', 'Valide');

        $t = $api()->postJson('/api/marches_publics/records/tenders', ['title' => 'Réhabilitation du marché municipal', 'buyer' => 'Mairie de Cocody', 'partner_id' => $client,
            'sector' => 'works', 'submission_at' => now()->addDays(10)->setTime(10, 0)->toDateTimeString(), 'bid_bond_amount' => 2000000, 'our_bid' => 95000000])
            ->assertCreated()->assertJsonPath('data.status', 'watch')->assertJsonPath('data.countdown', 'Dépôt dans 10 j')->json('data');
        $this->assertSame(0, DB::table('mp_pieces')->count());
        // on soumissionne : liste des pièces créée, pièces administratives reprises
        $t = $api()->putJson('/api/marches_publics/records/tenders/'.$t['id'], ['status' => 'go'])->assertOk()->json('data');
        $this->assertSame(count(MarchesHooks::STANDARD_PIECES), $t['pieces_total']);
        $this->assertSame(1, $t['pieces_ready']); // CNPS valide ; fiscale expirée au dépôt
        $pieces = collect($api()->getJson('/api/marches_publics/records/pieces?tender_id='.$t['id'])->assertOk()->json('data'));
        $this->assertStringStartsWith('Pièce expirée à la date de dépôt', $pieces->firstWhere('title', 'Attestation de régularité fiscale')['alert']);
        $this->assertSame('https://ged/cnps.pdf', $pieces->firstWhere('title', 'Attestation CNPS')['file_url']);
        $api()->postJson('/api/marches_publics/tenders/'.$t['id'].'/checklist')->assertOk()->assertJsonPath('data.created', 0);
        $api()->putJson('/api/marches_publics/records/pieces/'.$pieces->firstWhere('title', 'Lettre de soumission signée')['id'], ['status' => 'ready'])->assertOk();
        $this->assertSame('2/'.count(MarchesHooks::STANDARD_PIECES).' prêtes', $api()->getJson('/api/marches_publics/records/tenders/'.$t['id'])->json('data.pieces_progress'));

        // caution de soumission
        $bond = $api()->postJson('/api/marches_publics/records/bonds', ['kind' => 'bid', 'tender_id' => $t['id'], 'bank' => 'SGCI', 'amount' => 2000000, 'status' => 'issued', 'expires_on' => now()->addMonths(4)->toDateString()])
            ->assertCreated()->assertJsonPath('data.alert', null)->json('data');

        // rappels : dépôt à J-7, pièce administrative qui expire
        $this->travel(4)->days();
        $n = app(MarchesHooks::class)->alerts($company);
        $this->assertGreaterThanOrEqual(2, $n);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Dépôt dans%')->exists());
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'Pièce administrative à renouveler')->exists());
        $this->assertSame(0, app(MarchesHooks::class)->alerts($company)); // pas de doublon
        $this->travelBack();

        // attribué : marché créé depuis l'offre, la caution de soumission peut être levée
        $api()->putJson('/api/marches_publics/records/tenders/'.$t['id'], ['status' => 'won', 'winning_amount' => 93500000])->assertOk();
        $k = DB::table('mp_contracts')->where('tender_id', $t['id'])->first();
        $this->assertEquals(93500000, $k->amount);
        $this->assertSame('Résultat connu : demander la mainlevée', $api()->getJson('/api/marches_publics/records/bonds/'.$bond['id'])->json('data.alert'));
        $api()->postJson('/api/marches_publics/tenders/'.$t['id'].'/award')->assertOk()->assertJsonPath('data.id', $k->id);
        $this->assertSame(1, DB::table('mp_contracts')->count());

        $k = $api()->putJson('/api/marches_publics/records/contracts/'.$k->id, ['amount' => 100000000, 'advance_percent' => 15, 'retention_percent' => 5, 'status' => 'in_progress',
            'start_order_on' => now()->subDays(100)->toDateString(), 'duration_days' => 90, 'penalty_per_day' => 1])->assertOk()->json('data');
        $this->assertSame(now()->subDays(10)->toDateString(), $k['planned_end']);
        $this->assertStringStartsWith('En retard de 10 j — pénalités possibles : 1 000 000', $k['alert']);

        // décomptes : retenue 5 %, avance 15 % remboursée au prorata, plafonnée
        $s1 = $api()->postJson('/api/marches_publics/records/statements', ['contract_id' => $k['id'], 'amount' => 40000000, 'progress_percent' => 40])
            ->assertCreated()->assertJsonPath('data.number', 'Décompte n° 1')->json('data');
        $this->assertEquals(2000000, $s1['retention']);
        $this->assertEquals(6000000, $s1['advance_repayment']);
        $this->assertEquals(32000000, $s1['net']);
        $s2 = $api()->postJson('/api/marches_publics/records/statements', ['contract_id' => $k['id'], 'amount' => 70000000])->assertCreated()->json('data');
        $this->assertEquals(9000000, $s2['advance_repayment']); // 15 M d'avance au total
        $this->assertSame('100 % exécuté', $api()->getJson('/api/marches_publics/records/contracts/'.$k['id'])->json('data.progress'));

        // facture : brouillon au service ventes, client du marché, lignes de retenue et d'avance
        $s1 = $api()->postJson('/api/marches_publics/statements/'.$s1['id'].'/invoice')->assertOk()->assertJsonPath('data.status', 'submitted')->json('data');
        $inv = DB::table('trade_documents')->where('id', $s1['invoice_id'])->first();
        $this->assertSame('draft', $inv->status);
        $this->assertSame($client, $inv->partner_id);
        $this->assertEquals(32000000 * 1.18, (float) $inv->total);
        $api()->postJson('/api/marches_publics/statements/'.$s1['id'].'/invoice')->assertStatus(422);
        $api()->putJson('/api/marches_publics/records/statements/'.$s1['id'], ['amount' => 1])->assertStatus(422);
        $api()->deleteJson('/api/marches_publics/records/statements/'.$s1['id'])->assertStatus(422);
        $api()->deleteJson('/api/marches_publics/records/contracts/'.$k['id'])->assertStatus(422);

        // réception provisoire : étape suivante automatique ; caution de bonne exécution à lever
        $api()->postJson('/api/marches_publics/records/bonds', ['kind' => 'performance', 'contract_id' => $k['id'], 'amount' => 5000000, 'status' => 'issued', 'expires_on' => now()->addYear()->toDateString()])
            ->assertCreated()->assertJsonPath('data.alert', null);
        $api()->putJson('/api/marches_publics/records/contracts/'.$k['id'], ['provisional_acceptance_on' => now()->toDateString()])->assertOk()->assertJsonPath('data.status', 'provisional');
        $this->assertSame('Réception prononcée : demander la mainlevée', DB::table('mp_bonds')->where('kind', 'performance')->exists()
            ? $api()->getJson('/api/marches_publics/records/bonds?kind=performance')->json('data.0.alert') : null);

        $d = $api()->getJson('/api/marches_publics/dashboard')->assertOk()->json('data');
        $this->assertSame(100, $d['win_rate']);
        $this->assertEquals(5500000, $d['retention']);
        $this->assertSame(1, $d['contracts']);

        // le commercial ne voit pas les marchés
        $u = \App\Models\User::create(['company_id' => $company->id, 'name' => 'Koffi', 'email' => 'koffi@mp.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id')]);
        $this->api($u->createToken('t')->plainTextToken)->getJson('/api/marches_publics/records/tenders')->assertForbidden();
        // chargé des marchés : accès complet
        $m = \App\Models\User::create(['company_id' => $company->id, 'name' => 'Awa', 'email' => 'awa@mp.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Chargé des marchés')->value('id')]);
        $this->api($m->createToken('t')->plainTextToken)->postJson('/api/marches_publics/records/tenders', ['title' => 'Fourniture de mobilier'])->assertCreated();
    }
}
