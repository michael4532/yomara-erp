<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Modules\Evenements\Models\Booking;
use App\Modules\Hebergement\Models\HostedService;
use App\Modules\Hebergement\Models\Renewal;
use App\Modules\Ventes\Models\SalesDocument;
use Tests\TestCase;

class EvenementsHebergementTest extends TestCase
{
    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Agence Événements');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        $client = fn (string $name, array $extra = []) => $api()->postJson('/api/core/partners', ['name' => $name, 'type' => 'customer'] + $extra)->json('data.id');

        return compact('company', 'admin', 'api', 'push', 'client');
    }

    private function ok(array $r): array
    {
        $this->assertSame('applied', $r['status'], json_encode($r));

        return $r['record'];
    }

    private function pay(callable $push, string $docId, float $amount): void
    {
        $this->ok($push('sales_payments', ['document_id' => $docId, 'date' => now()->toDateString(), 'amount' => $amount, 'method' => 'bank_transfer']));
    }

    public function test_event_spaces_bookings_deposit_balance_public_page_and_stats(): void
    {
        ['api' => $api, 'push' => $push, 'client' => $client] = $this->env();
        $ev = $this->uuid();
        $this->ok($push('ev_events', ['name' => 'SELAB 2027', 'venue' => 'Parc des expositions', 'city' => 'Abidjan', 'start_date' => now()->addDays(40)->toDateString(),
            'end_date' => now()->addDays(43)->toDateString(), 'status' => 'open', 'tax_rate' => 18, 'deposit_percent' => 30, 'balance_days' => 20, 'option_days' => 5,
            'plan_cols' => 20, 'plan_rows' => 10, 'categories' => [['name' => 'Standard 9 m²', 'color' => '#1F6FEB', 'price' => 300000, 'area' => 9], ['name' => 'Angle 18 m²', 'price' => 650000, 'area' => 18]]], $ev));
        $this->assertSame('rejected', $push('ev_events', ['name' => 'X', 'start_date' => '2027-02-10', 'end_date' => '2027-02-01'])['status']);

        // Plan : rangée générée avec le prix de la catégorie, chevauchement et dépassement refusés
        $row = $api()->postJson("/api/evenements/events/$ev/spaces/generate", ['prefix' => 'A', 'count' => 5, 'x' => 1, 'y' => 1, 'w' => 3, 'h' => 3, 'gap' => 1, 'category' => 'Standard 9 m²'])->assertCreated()->json('data');
        $this->assertSame(['A01', 'A02', 'A03', 'A04', 'A05'], array_column($row, 'code'));
        $this->assertEquals([300000, 9], [$row[0]['price'], $row[0]['area']]);
        $this->assertSame(17, $row[4]['x']);
        $this->assertStringContainsString('chevauche A01', $push('ev_spaces', ['event_id' => $ev, 'code' => 'B1', 'x' => 2, 'y' => 2, 'w' => 2, 'h' => 2])['message']);
        $this->assertStringContainsString('dépasse du plan', $push('ev_spaces', ['event_id' => $ev, 'code' => 'B1', 'x' => 19, 'y' => 6, 'w' => 2, 'h' => 2])['message']);
        $this->assertStringContainsString('déjà utilisé', $push('ev_spaces', ['event_id' => $ev, 'code' => 'a01', 'x' => 1, 'y' => 6])['message']);
        $angle = $this->ok($push('ev_spaces', ['event_id' => $ev, 'code' => 'C1', 'category' => 'Angle 18 m²', 'x' => 1, 'y' => 6, 'w' => 6, 'h' => 3]))['id'];
        $scene = $this->ok($push('ev_spaces', ['event_id' => $ev, 'code' => 'SCENE', 'x' => 10, 'y' => 6, 'w' => 6, 'h' => 3, 'blocked' => true]))['id'];
        $gold = $this->ok($push('ev_packages', ['event_id' => $ev, 'kind' => 'sponsor', 'name' => 'Sponsor Or', 'price' => 2000000, 'quantity' => 1, 'benefits' => ['Logo sur l\'affiche', ' ', '4 badges']]));
        $this->assertSame(['Logo sur l\'affiche', '4 badges'], $gold['benefits']);
        $elec = $this->ok($push('ev_packages', ['event_id' => $ev, 'name' => 'Électricité 3 kW', 'price' => 50000]))['id'];

        $tantie = $client('Laiterie du Nord');
        $ferme = $client('Ferme Kouassi');
        // Option : date limite par défaut, montants
        $b1 = $this->uuid();
        $r = $this->ok($push('ev_bookings', ['event_id' => $ev, 'partner_id' => $tantie, 'space_ids' => [$row[0]['id'], $row[1]['id']], 'items' => [['package_id' => $elec, 'quantity' => 2]]], $b1));
        $this->assertSame('option', $r['status']);
        $this->assertSame(now()->addDays(5)->toDateString(), $r['option_until']);
        $this->assertEquals([700000, 126000, 826000, 18], [$r['total_ht'], $r['tax'], $r['total'], $r['area']]);
        $this->assertStringStartsWith('RES-', $r['number']);
        $this->assertSame('Laiterie du Nord', $r['exhibitor_name']);
        // Double réservation, emplacement bloqué, sponsor complet
        $this->assertStringContainsString('A02 est déjà réservé', $push('ev_bookings', ['event_id' => $ev, 'partner_id' => $ferme, 'space_ids' => [$row[1]['id']]])['message']);
        $this->assertStringContainsString('SCENE n\'est pas à la vente', $push('ev_bookings', ['event_id' => $ev, 'partner_id' => $ferme, 'space_ids' => [$scene]])['message']);
        $b2 = $this->uuid();
        $this->ok($push('ev_bookings', ['event_id' => $ev, 'partner_id' => $ferme, 'space_ids' => [$angle], 'items' => [['package_id' => $gold['id'], 'quantity' => 1]], 'discount_percent' => 10], $b2));
        $this->assertStringContainsString('Sponsor Or » : complet', $push('ev_bookings', ['event_id' => $ev, 'partner_id' => $tantie, 'items' => [['package_id' => $gold['id'], 'quantity' => 1]]])['message']);
        $this->assertSame('rejected', $push('ev_bookings', ['event_id' => $ev, 'partner_id' => $tantie])['status']); // rien choisi
        $this->assertStringContainsString('ne peut pas descendre', $push('ev_packages', ['quantity' => 0], $gold['id'])['message']);

        // Confirmation : acompte de 30 % facturé, validé
        $r = $this->ok($push('ev_bookings', ['status' => 'confirmed'], $b1));
        $dep = SalesDocument::find(Booking::find($b1)->deposit_invoice_id);
        $this->assertSame('validated', $dep->status);
        $this->assertEquals([210000, 247800], [(float) $dep->subtotal, (float) $dep->total]);
        $this->assertSame('RES-', substr($dep->reference, 0, 4));
        $this->assertSame('unpaid', Booking::find($b1)->toSyncArray()['payment_status']);
        // Modification après acompte : client figé ; ajout d'un emplacement accepté
        $this->assertStringContainsString('ne peut plus changer', $push('ev_bookings', ['partner_id' => $ferme], $b1)['message']);
        $this->ok($push('ev_bookings', ['space_ids' => [$row[0]['id'], $row[1]['id'], $row[2]['id']]], $b1));
        // Paiement de l'acompte : réservation à jour
        $this->pay($push, $dep->id, 247800);
        $this->assertSame('partial', Booking::find($b1)->toSyncArray()['payment_status']);
        // Annulation refusée après encaissement
        $this->assertStringContainsString('a reçu des paiements', $push('ev_bookings', ['status' => 'cancelled'], $b1)['message']);

        // Solde : tâche quotidienne à J-20 ; détail moins l'acompte
        $this->artisan('erp:events')->assertSuccessful();
        $this->assertNull(Booking::find($b1)->balance_invoice_id); // trop tôt
        $this->travel(21)->days();
        $this->artisan('erp:events')->assertSuccessful();
        $bal = SalesDocument::with('lines')->find(Booking::find($b1)->balance_invoice_id);
        $this->assertNotNull($bal);
        $this->assertEquals(1000000 - 210000, (float) $bal->subtotal); // 3 stands + 2 × électricité − acompte
        $this->assertCount(5, $bal->lines);
        $this->assertStringContainsString('Acompte déjà facturé ('.$dep->number.')', $bal->lines->last()->description);
        $this->assertStringContainsString('Option expirée', AppNotification::where('module', 'evenements')->pluck('title')->implode('|')); // b2 expirée
        $this->assertSame('expired', Booking::find($b2)->status);
        // Entièrement facturée : plus de changement d'emplacements
        $this->assertStringContainsString('entièrement facturée', $push('ev_bookings', ['space_ids' => [$row[0]['id']]], $b1)['message']);
        $this->pay($push, $bal->id, (float) $bal->total);
        $s = Booking::find($b1)->toSyncArray();
        $this->assertEquals(['paid', 1180000], [$s['payment_status'], $s['paid']]);
        // Accueil de l'exposant
        $api()->postJson("/api/evenements/bookings/$b1/checkin")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertNotNull(Booking::find($b1)->checked_in_at);

        // Option expirée réactivée (l'angle est libre) ; le sponsor Or redevient vendable
        $this->ok($push('ev_bookings', ['status' => 'option'], $b2));
        $this->assertSame(now()->addDays(5)->toDateString(), Booking::find($b2)->option_until->toDateString());

        // Page publique : fermée tant que non publiée, puis demande d'un exposant
        $token = \App\Modules\Evenements\Models\Event::find($ev)->public_token;
        $this->get('/salon/'.$token)->assertNotFound();
        $this->ok($push('ev_events', ['public_enabled' => true], $ev));
        $this->get('/salon/'.$token)->assertOk()->assertSee('SELAB 2027')->assertSee('A04')->assertDontSee('value="'.$row[0]['id'].'"', false);
        $this->post('/salon/'.$token, ['company' => 'Coopérative Avicole', 'name' => 'M. Traoré', 'phone' => '+225 07 08 09 10 11', 'spaces' => [$row[3]['id']], 'packages' => [$elec => 1]])
            ->assertRedirect('/salon/'.$token);
        $web = Booking::where('source', 'online')->first();
        $this->assertSame(['option', 'Coopérative Avicole'], [$web->status, $web->exhibitor_name]);
        $this->assertSame(50000.0, (float) $web->items[0]['unit_price']);
        $this->assertStringContainsString('Nouvelle demande de stand en ligne', AppNotification::where('module', 'evenements')->pluck('title')->implode('|'));
        $this->post('/salon/'.$token, ['company' => 'Autre', 'name' => 'X', 'phone' => '0102030405', 'spaces' => [$row[3]['id']]])->assertSessionHas('error');
        $this->post('/salon/'.$token, ['company' => 'Robot', 'name' => 'X', 'phone' => '0102030405', 'spaces' => [$row[4]['id']], 'website' => 'spam'])->assertInvalid('website');

        // Statistiques
        $st = $api()->getJson("/api/evenements/events/$ev/stats")->assertOk()->json('data');
        $this->assertSame([6, 1, 3, 2, 1], [$st['spaces'], $st['blocked'], $st['confirmed_spaces'], $st['option_spaces'], $st['free_spaces']]);
        $this->assertEquals(83.3, $st['occupancy']);
        $this->assertEquals([1000000, 1180000, 1180000, 1], [$st['revenue_confirmed'], $st['invoiced'], $st['paid'], $st['checked_in']]);
        $this->assertSame(1, collect($st['packages'])->firstWhere('name', 'Sponsor Or')['sold']);
        $dash = collect($api()->getJson('/api/dashboard')->json('data'))->keyBy('key');
        $this->assertEquals(83.3, $dash['ev_occupancy']['value']);

        // Suppression : emplacement réservé et événement avec réservations refusés
        $del = fn ($entity, $id) => $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => $entity, 'op' => 'delete', 'record_id' => $id]]])->json('results.0');
        $this->assertStringContainsString('figure dans la réservation', $del('ev_spaces', $row[0]['id'])['message']);
        $this->assertSame('rejected', $del('ev_events', $ev)['status']);
        // Annulation d'une option sans facture : emplacements libérés
        $this->ok($push('ev_bookings', ['status' => 'cancelled', 'cancel_reason' => 'Désistement'], $b2));
        $this->assertSame(2, $api()->getJson("/api/evenements/events/$ev/stats")->json('data.free_spaces'));
        // Synchronisation
        $pull = $api()->getJson('/api/sync/pull?cursor=0')->json('changes');
        $this->assertCount(3, $pull['ev_bookings']['upserts']);
        $this->assertCount(7, $pull['ev_spaces']['upserts']);
    }

    public function test_cancel_confirmed_booking_cancels_unpaid_invoice_and_full_invoice_without_deposit(): void
    {
        ['push' => $push, 'client' => $client] = $this->env();
        $ev = $this->uuid();
        $this->ok($push('ev_events', ['name' => 'Forum digital', 'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(10)->toDateString(), 'status' => 'open', 'deposit_percent' => 0], $ev));
        $sp = $this->ok($push('ev_spaces', ['event_id' => $ev, 'code' => 'T1', 'price' => 100000, 'x' => 0, 'y' => 0]))['id'];
        $b = $this->uuid();
        $this->ok($push('ev_bookings', ['event_id' => $ev, 'partner_id' => $client('Startup'), 'space_ids' => [$sp], 'status' => 'confirmed'], $b));
        $bk = Booking::find($b);
        $this->assertNull($bk->deposit_invoice_id);
        $this->assertEquals(118000, (float) SalesDocument::find($bk->balance_invoice_id)->total);
        $this->ok($push('ev_bookings', ['status' => 'cancelled'], $b));
        $this->assertSame('cancelled', SalesDocument::find($bk->balance_invoice_id)->status);
        $this->assertStringContainsString('ne peut pas passer', $push('ev_bookings', ['status' => 'confirmed'], $b)['message']);
        // Ventes closes
        $this->ok($push('ev_events', ['status' => 'closed'], $ev));
        $this->assertStringContainsString('sont closes', $push('ev_bookings', ['event_id' => $ev, 'partner_id' => $client('Tard'), 'space_ids' => [$sp]])['message']);
    }

    public function test_hosting_renewals_grouped_invoice_payment_extends_provider_todo_reminders(): void
    {
        ['company' => $company, 'api' => $api, 'push' => $push, 'client' => $client] = $this->env();
        $c1 = $client('Boutique Mode Ivoire', ['email' => 'mode@client.ci']);
        $c2 = $client('Cabinet Médical');
        $mk = fn (array $d) => $this->ok($push('hb_services', $d + ['tax_rate' => 18]));
        $dom = $mk(['partner_id' => $c1, 'type' => 'domain', 'name' => 'https://www.Mode-Ivoire.CI/', 'provider' => 'NIC CI', 'cost_price' => 15000, 'sale_price' => 25000,
            'period_months' => 12, 'start_date' => now()->subYear()->toDateString(), 'expiry_date' => now()->addDays(20)->toDateString()]);
        $this->assertSame('mode-ivoire.ci', $dom['name']);
        $this->assertSame('expiring', $dom['state']);
        $this->assertStringStartsWith('SRV-', $dom['number']);
        $this->assertStringContainsString('déjà suivi', $push('hb_services', ['partner_id' => $c2, 'type' => 'domain', 'name' => 'mode-ivoire.ci', 'expiry_date' => '2030-01-01'])['message']);
        $host = $mk(['partner_id' => $c1, 'type' => 'hosting', 'name' => 'Hébergement mode-ivoire.ci', 'plan' => 'Pro 10 Go', 'provider' => 'o2switch', 'cost_price' => 40000, 'sale_price' => 90000,
            'period_months' => 12, 'expiry_date' => now()->addDays(25)->toDateString()]);
        $maint = $mk(['partner_id' => $c2, 'type' => 'maintenance', 'name' => 'Maintenance site cabinet', 'cost_price' => 0, 'sale_price' => 30000, 'period_months' => 1,
            'renew_days_before' => 5, 'expiry_date' => now()->addDays(60)->toDateString()]);
        $ssl = $mk(['partner_id' => $c2, 'type' => 'ssl', 'name' => 'cabinet.ci', 'sale_price' => 20000, 'expiry_date' => now()->subDays(3)->toDateString(), 'auto_renew' => false]);

        // Tâche quotidienne : une facture par client regroupant les services à renouveler
        $this->artisan('erp:hosting')->assertSuccessful();
        $rens = Renewal::where('status', 'invoiced')->get();
        $this->assertCount(2, $rens); // domaine + hébergement (maintenance trop tôt, SSL sans renouvellement auto)
        $this->assertCount(1, $rens->pluck('document_id')->unique());
        $doc = SalesDocument::with('lines')->find($rens[0]->document_id);
        $this->assertEquals([115000, 135700], [(float) $doc->subtotal, (float) $doc->total]);
        $this->assertStringContainsString('Renouvellement 1 an : Nom de domaine mode-ivoire.ci', $doc->lines->pluck('description')->implode('|'));
        $this->assertSame(now()->addDays(20)->toDateString(), $doc->due_date->toDateString());
        $s = HostedService::find($dom['id'])->toSyncArray();
        $this->assertSame($doc->number, $s['pending']['number']);
        $this->assertSame(now()->addDays(21)->toDateString(), $s['pending']['period_start']);
        // Pas de doublon le lendemain
        $this->artisan('erp:hosting')->assertSuccessful();
        $this->assertSame(1, SalesDocument::count());
        $this->assertStringContainsString('Échéance dépassée : cabinet.ci', AppNotification::where('module', 'hebergement')->pluck('title')->implode('|'));
        $this->assertSame(1, AppNotification::where('module', 'hebergement')->where('title', 'like', 'Échéance dépassée%')->count());
        // Renouvellement manuel refusé tant que la facture attend
        $api()->postJson('/api/hebergement/renew', ['ids' => [$dom['id']]])->assertStatus(422);

        // Paiement : échéances prolongées d'un an, à renouveler chez le fournisseur
        $this->pay($push, $doc->id, 135700);
        $d = HostedService::find($dom['id']);
        $this->assertSame(now()->addDays(20)->addYear()->toDateString(), $d->expiry_date->toDateString());
        $this->assertSame('ok', $d->state());
        $sync = $d->toSyncArray();
        $this->assertNull($sync['pending']);
        $this->assertNotNull($sync['provider_todo']);
        $this->assertStringContainsString('Payé : renouvelez mode-ivoire.ci', AppNotification::where('module', 'hebergement')->pluck('title')->implode('|'));
        $this->assertSame(2, $api()->getJson('/api/hebergement/stats')->json('data.provider_todo'));
        $api()->postJson("/api/hebergement/services/{$dom['id']}/provider-done")->assertOk()->assertJsonPath('data.provider_todo', null);
        $api()->postJson("/api/hebergement/services/{$dom['id']}/provider-done")->assertStatus(422);

        // Facture annulée : renouvellement annulé, refacturable
        $r = $api()->postJson('/api/hebergement/renew', ['ids' => [$maint['id']]])->assertCreated()->json('data');
        $this->assertEquals(35400, $r['total']);
        $this->ok($push('sales_documents', ['id' => $r['id'], 'status' => 'cancelled'], $r['id']));
        $this->assertSame('cancelled', Renewal::where('document_id', $r['id'])->value('status'));
        $this->assertNull(HostedService::find($maint['id'])->pendingRenewal());
        // Factures de clients différents refusées ensemble
        $api()->postJson('/api/hebergement/renew', ['ids' => [$maint['id'], $host['id']]])->assertStatus(422);

        // Prolongation manuelle du SSL expiré
        $api()->postJson("/api/hebergement/services/{$ssl['id']}/extend", ['until' => now()->subDays(5)->toDateString()])->assertStatus(422);
        $api()->postJson("/api/hebergement/services/{$ssl['id']}/extend")->assertOk()->assertJsonPath('data.state', 'ok');
        $this->assertSame(now()->subDays(3)->addYear()->toDateString(), HostedService::find($ssl['id'])->expiry_date->toDateString());
        $hist = $api()->getJson("/api/hebergement/services/{$ssl['id']}/history")->json('data');
        $this->assertSame('manual', $hist[0]['status']);

        // Indicateurs : revenu mensuel et marge
        $st = $api()->getJson('/api/hebergement/stats')->json('data');
        $this->assertEquals(round(25000 / 12 + 90000 / 12 + 30000 + 20000 / 12), $st['mrr']);
        $this->assertSame(4, $st['active']);
        $this->assertEquals(115000, $st['renewed_year']);
        // Suppression refusée avec historique ; résiliation
        $del = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'hb_services', 'op' => 'delete', 'record_id' => $dom['id']]]])->json('results.0');
        $this->assertStringContainsString('résiliez-le', $del['message']);
        $this->ok($push('hb_services', ['status' => 'cancelled'], $dom['id']));
        // Réglages des rappels
        $api()->putJson('/api/hebergement/settings', ['reminder_days' => [15, 3], 'auto_send' => false])->assertOk()->assertJsonPath('data.reminder_days', [15, 3]);
        // Échéance dépassée affichée « expired » ; module coupé hors formule
        $this->assertSame('expired', HostedService::make(['status' => 'active', 'expiry_date' => now()->subDay()->toDateString()])->state());
    }
}
