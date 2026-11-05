<?php

namespace Tests\Feature;

use App\Modules\Immobilier\Models\Rent;
use App\Modules\Ventes\Models\SalesDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Gestion locative : biens, lots, baux (prorata, avance, caution, révision), appels de loyer, encaissements, impayés, relevé de gérance, fin de bail. */
class ImmobilierTest extends TestCase
{
    public function test_properties_leases_rent_calls_payments_statement_and_end_of_lease(): void
    {
        $this->travelTo(Carbon::parse('2026-01-10 09:00'));
        [$company, , $token] = $this->makeCompany('Agence Immo Plus');
        $api = fn () => $this->api($token);

        // Bien d'un propriétaire (créé au passage), honoraires de gérance 8 %
        $p = $api()->postJson('/api/immobilier/properties', ['name' => 'Résidence Les Palmiers', 'kind' => 'immeuble', 'district' => 'Cocody Angré', 'city' => 'Abidjan',
            'owner_name' => 'M. Kouassi Bernard', 'owner_phone' => '0707112233', 'fee_rate' => 8])->assertCreated()->json('data');
        $this->assertNotNull($p['owner_id']);
        $a1 = $api()->postJson('/api/immobilier/units', ['property_id' => $p['id'], 'reference' => 'A1', 'kind' => 'appartement', 'rooms' => 3, 'rent' => 150000, 'charges' => 10000])->assertCreated()->json('data.id');
        $b2 = $api()->postJson('/api/immobilier/units', ['property_id' => $p['id'], 'reference' => 'Magasin B2', 'kind' => 'magasin', 'rent' => 250000])->assertCreated()->json('data.id');

        // Bail au 15 janvier : premier mois au prorata, 2 mois d'avance et caution réglés à la signature
        $l = $api()->postJson('/api/immobilier/leases', ['unit_id' => $a1, 'tenant_name' => 'Koné Aminata', 'tenant_phone' => '0505667788', 'start_date' => '2026-01-15',
            'rent' => 150000, 'charges' => 10000, 'deposit_amount' => 300000, 'advance_months' => 2, 'due_day' => 5, 'revision_rate' => 5,
            'entry_payment' => ['method' => 'cash']])->assertCreated()->json('data');
        $this->assertEquals(['BAIL-2026-00001', 2, 300000.0, 0.0, 'occupied'], [$l['number'], $l['rents_count'], (float) $l['deposit_paid'], (float) $l['overdue'], DB::table('im_units')->where('id', $a1)->value('status')]);
        $rents = Rent::withoutGlobalScopes()->orderBy('period_start')->get();
        $this->assertEquals([[82258.0, 5484.0, '2026-01-31'], [150000.0, 10000.0, '2026-02-28']], $rents->map(fn ($r) => [$r->rent, $r->charges, $r->period_end->toDateString()])->all());
        $inv = SalesDocument::withoutGlobalScopes()->with('lines')->find($rents[0]->invoice_id);
        $this->assertEquals(['invoice', 'validated', 'paid', 'Loyer A1 — du 15/01/2026 au 31/01/2026'], [$inv->type, $inv->status, $inv->payment_status, $inv->lines[0]->description]);
        $api()->postJson('/api/immobilier/leases', ['unit_id' => $a1, 'tenant_name' => 'X', 'start_date' => '2026-02-01', 'rent' => 1])->assertStatus(422);

        // Bail commercial trimestriel commencé en 2025, révision de 10 % au 1er janvier : rattrapage des appels
        $this->travelTo(Carbon::parse('2026-03-28 08:00'));
        $lb = $api()->postJson('/api/immobilier/leases', ['unit_id' => $b2, 'tenant_name' => 'Pharmacie du Carrefour', 'start_date' => '2025-07-01', 'rent' => 250000, 'months' => 3,
            'usage' => 'professionnel', 'revision_rate' => 10, 'next_revision_date' => '2026-01-01', 'deposit_amount' => 750000])->assertCreated()->json('data');
        $gen = $api()->postJson('/api/immobilier/rents/generate')->assertOk()->json('data');
        $this->assertEquals(['rents' => 6, 'errors' => 0], $gen); // A1 : mars + avril ; B2 : T3 et T4 2025, T1 et T2 2026 (appelé 5 jours avant)
        $b = Rent::withoutGlobalScopes()->where('lease_id', $lb['id'])->orderBy('period_start')->get();
        $this->assertEquals([['2025-07-01', 750000.0], ['2025-10-01', 750000.0], ['2026-01-01', 825000.0], ['2026-04-01', 825000.0]], $b->map(fn ($r) => [$r->period_start->toDateString(), $r->rent])->all());
        $this->assertEquals(275000.0, (float) DB::table('im_leases')->where('id', $lb['id'])->value('rent'));
        $this->assertEquals(['rents' => 0, 'errors' => 0], $api()->postJson('/api/immobilier/rents/generate')->json('data'));

        // Encaissement partiel de mars par Mobile Money, avril impayé
        $march = Rent::withoutGlobalScopes()->where('lease_id', $l['id'])->where('period_start', '2026-03-01')->first();
        $r = $api()->postJson("/api/immobilier/rents/{$march->id}/pay", ['amount' => 100000, 'method' => 'mobile_money', 'reference' => 'OM-5566', 'date' => '2026-03-20'])->assertOk()->json('data');
        $this->assertEquals(['partial', 60000.0, 'mars 2026'], [$r['status'], (float) $r['balance'], $r['label']]);
        $this->travelTo(Carbon::parse('2026-04-10 10:00'));
        $over = collect($api()->getJson('/api/immobilier/rents?status=overdue')->assertOk()->json('data'));
        $this->assertEquals(['mars 2026' => 60000.0, 'avril 2026' => 160000.0], $over->where('lease_id', $l['id'])->mapWithKeys(fn ($x) => [$x['label'] => (float) $x['balance']])->all());
        $this->assertSame(5, $over->firstWhere('label', 'avril 2026')['days_late']);
        $api()->postJson("/api/immobilier/leases/{$l['id']}/remind")->assertStatus(422)->assertJsonFragment(['message' => 'Message non envoyé : configurez WhatsApp, SMS ou l\'e-mail dans les canaux de messagerie, ou utilisez le lien WhatsApp.']);
        $lease = $api()->getJson("/api/immobilier/leases/{$l['id']}")->assertOk()->json('data');
        $this->assertEquals([220000.0, 2], [(float) $lease['overdue'], $lease['overdue_count']]);
        $this->assertStringStartsWith('https://wa.me/2250505667788?text=', $lease['whatsapp_url']);

        // Quittance (loyer payé) et avis d'échéance (impayé)
        $this->assertStringStartsWith('%PDF', $api()->get("/api/immobilier/rents/{$rents[1]->id}/pdf")->assertOk()->getContent());
        $this->assertStringStartsWith('%PDF', $api()->get("/api/immobilier/rents/{$march->id}/pdf")->assertOk()->getContent());

        // Tableau de bord
        $d = $api()->getJson('/api/immobilier/dashboard?month=2026-04')->assertOk()->json('data');
        $this->assertEquals([2, 2, 100.0, 1, 2], [$d['units'], $d['occupied'], $d['occupancy'], $d['properties'], $d['overdue_tenants']]);
        $this->assertEquals(220000 + 825000 + 825000 + 750000 + 750000, $d['overdue_total']); // B2 n'a rien payé (T2 2026 compris)
        $this->assertEquals(160000 + 275000 * 3, $d['billed']);

        // Dépense à la charge du propriétaire et relevé de gérance de mars
        $api()->postJson('/api/immobilier/expenses', ['property_id' => $p['id'], 'unit_id' => $a1, 'date' => '2026-03-12', 'label' => 'Réparation plomberie A1', 'category' => 'travaux', 'amount' => 25000])->assertCreated();
        $api()->postJson('/api/immobilier/expenses', ['property_id' => $p['id'], 'date' => '2026-03-15', 'label' => 'Frais de l\'agence', 'amount' => 5000, 'owner_charge' => false])->assertCreated();
        $q = ['owner_id' => $p['owner_id'], 'from' => '2026-03-01', 'to' => '2026-03-31'];
        $s = $api()->getJson('/api/immobilier/statement?'.http_build_query($q))->assertOk()->json('data');
        $this->assertEquals(['collected' => 100000, 'fees' => 8000, 'expenses' => 25000, 'net' => 67000], array_intersect_key($s['totals'], array_flip(['collected', 'fees', 'expenses', 'net'])));
        $this->assertSame('M. Kouassi Bernard', $s['owner']['name']);
        $this->assertStringStartsWith('%PDF', $api()->get('/api/immobilier/statement/pdf?'.http_build_query($q))->assertOk()->getContent());
        $api()->postJson('/api/immobilier/payouts', ['owner_id' => $p['owner_id'], 'period_from' => '2026-03-01', 'period_to' => '2026-03-31', 'method' => 'bank_transfer', 'reference' => 'VIR-0326'])
            ->assertCreated()->assertJsonPath('data.amount', 67000);
        $s = $api()->getJson('/api/immobilier/statement?'.http_build_query($q))->json('data');
        $this->assertEquals([67000, 0], [$s['paid_out'], $s['to_pay']]);
        $owners = collect($api()->getJson('/api/immobilier/owners')->assertOk()->json('data'));
        $this->assertSame(2, $owners->firstWhere('id', $p['owner_id'])['units']);

        // Fin du bail : caution restituée moins les retenues, lot libéré
        $e = $api()->postJson("/api/immobilier/leases/{$l['id']}/end", ['date' => '2026-04-30', 'reason' => 'Départ du locataire', 'deposit_retained' => 50000, 'deposit_note' => 'Peinture'])->assertOk()->json('data');
        $this->assertEquals(['ended', 250000.0, 50000.0], [$e['status'], (float) $e['deposit_returned'], (float) $e['deposit_retained']]);
        $this->assertSame('vacant', DB::table('im_units')->where('id', $a1)->value('status'));
        $api()->deleteJson("/api/immobilier/units/{$a1}")->assertStatus(422);
        $this->assertCount(1, $api()->getJson('/api/immobilier/leases')->assertOk()->json('data'));
    }

    public function test_permissions(): void
    {
        [, , $token] = $this->makeCompany('Gérance Yao');
        $role = $this->api($token)->postJson('/api/roles', ['name' => 'Consultation', 'permissions' => ['immobilier.view']])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/users', ['name' => 'Lecteur', 'email' => 'lecteur@yao.ci', 'password' => 'lecture123', 'role_id' => $role])->assertCreated();
        $t = \App\Models\User::where('email', 'lecteur@yao.ci')->first()->createToken('t')->plainTextToken;
        $this->api($t)->getJson('/api/immobilier/dashboard')->assertOk();
        $this->api($t)->postJson('/api/immobilier/properties', ['name' => 'X'])->assertForbidden();
        $this->api($t)->getJson('/api/immobilier/statement')->assertForbidden();
    }
}
