<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Modules\Billetterie\Models\Checkin;
use App\Modules\Billetterie\Models\Ticket;
use App\Modules\Billetterie\Models\TicketOrder;
use App\Modules\Evenements\Models\Booking;
use App\Modules\Evenements\Models\Event;
use App\Modules\Prestations\Models\Deliverable;
use App\Modules\Prestations\Models\Pack;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BilletteriePrestationsTest extends TestCase
{
    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Agence Services');
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

    private function pay(callable $push, string $docId): void
    {
        $this->ok($push('sales_payments', ['document_id' => $docId, 'date' => now()->toDateString(), 'amount' => (float) SalesDocument::find($docId)->total, 'method' => 'mobile_money']));
    }

    public function test_ticketing_box_office_online_scan_badges_stats(): void
    {
        ['api' => $api, 'push' => $push, 'client' => $client] = $this->env();
        $ev = $this->uuid();
        $this->ok($push('ev_events', ['name' => 'Festival Abidjan Jazz', 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(2)->toDateString(), 'status' => 'open',
            'tax_rate' => 18, 'ticketing_enabled' => true, 'capacity' => 6], $ev));
        $day = $this->ok($push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Pass 1 jour', 'price' => 5000, 'quantity' => 5, 'valid_from' => now()->toDateString(), 'valid_to' => now()->toDateString(), 'max_per_order' => 4]));
        $this->assertFalse($day['reentry']);
        $vip = $this->ok($push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Pass VIP 3 jours', 'kind' => 'vip', 'price' => 25000]));
        $this->assertSame(now()->addDays(2)->toDateString(), $vip['valid_to']);
        $badge = $this->ok($push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Badge exposant', 'kind' => 'exhibitor', 'price' => 0]));
        $this->assertTrue($badge['reentry']);
        $this->assertFalse($badge['online']);

        // Guichet hors ligne : billets imprimés par l'appareil, payés en espèces → facture + paiement
        $o1 = $this->uuid();
        $tk = [['id' => $this->uuid(), 'type_id' => $day['id'], 'code' => 'AB12-CD34', 'token' => str_repeat('a', 32), 'holder_name' => 'Awa'], ['id' => $this->uuid(), 'type_id' => $day['id'], 'code' => 'EF56-GH78', 'token' => str_repeat('b', 32)]];
        $r = $this->ok($push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'Guichet', 'items' => [['type_id' => $day['id'], 'quantity' => 2]], 'payment_method' => 'cash', 'tickets' => $tk], $o1));
        $this->assertEquals([10000, 'paid', 'box_office'], [$r['total'], $r['status'], $r['channel']]);
        $o = TicketOrder::find($o1);
        $inv = SalesDocument::find($o->invoice_id);
        $this->assertEquals([10000, 'paid'], [(float) $inv->total, $inv->payment_status]);
        $this->assertSame(['AB12-CD34', 'EF56-GH78'], Ticket::where('order_id', $o1)->orderBy('code')->pluck('code')->all());
        $this->assertSame('Awa', Ticket::where('code', 'AB12-CD34')->value('holder_name'));
        $this->assertSame('rejected', $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'X', 'items' => [['type_id' => $day['id'], 'quantity' => 1]], 'tickets' => [['id' => $this->uuid(), 'type_id' => $day['id'], 'code' => 'AB12-CD34']]])['status']);
        $this->assertStringContainsString('moyen de paiement', $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'X', 'items' => [['type_id' => $day['id'], 'quantity' => 1]]])['message']);
        // Quota du tarif et jauge
        $this->assertStringContainsString('Plus que 3', $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'X', 'items' => [['type_id' => $day['id'], 'quantity' => 4]], 'payment_method' => 'cash'])['message']);
        $this->assertStringContainsString('Jauge atteinte : 4', $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'X', 'items' => [['type_id' => $vip['id'], 'quantity' => 5]], 'payment_method' => 'cash'])['message']);

        // En ligne : page publique, commande en attente, facture payée → billets émis
        $event = Event::find($ev);
        $this->get('/billetterie/'.$event->public_token)->assertOk()->assertSee('Pass 1 jour')->assertSee('5 000')->assertDontSee('Badge exposant');
        $this->post('/billetterie/'.$event->public_token, ['name' => 'Kouassi Marc', 'phone' => '0707070707', 'email' => 'marc@test.ci', 'qty' => [$day['id'] => 5]])->assertSessionHas('error');
        $this->post('/billetterie/'.$event->public_token, ['name' => 'Kouassi Marc', 'phone' => '0707070707', 'email' => 'marc@test.ci', 'qty' => [$vip['id'] => 1, $day['id'] => 1]])->assertRedirect();
        $web = TicketOrder::where('channel', 'online')->first();
        $this->assertEquals(['pending', 30000], [$web->status, $web->total]);
        $this->assertSame(0, Ticket::where('order_id', $web->id)->count());
        $this->get($web->publicUrl())->assertOk()->assertSee('Payer maintenant')->assertSee('/pay/', false);
        $this->assertStringContainsString('Plus que 2', $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'Y', 'items' => [['type_id' => $day['id'], 'quantity' => 3]], 'payment_method' => 'cash'])['message']); // place réservée pendant le paiement
        $this->pay($push, $web->invoice_id);
        $web->refresh();
        $this->assertSame('paid', $web->status);
        $this->assertSame(2, Ticket::where('order_id', $web->id)->count());
        $vipTicket = Ticket::where('order_id', $web->id)->where('type_id', $vip['id'])->first();
        $this->get($web->publicUrl())->assertOk()->assertSee($vipTicket->code)->assertSee('<svg', false);
        $this->get('/billet/'.$vipTicket->token)->assertOk()->assertSee('Pass VIP 3 jours')->assertSee('Kouassi Marc');

        // Contrôle à l'entrée
        $scan = fn (string $code, ?string $evId = null) => $api()->postJson('/api/billetterie/scan', ['event_id' => $evId ?? $ev, 'code' => $code, 'gate' => 'Porte A'])->assertOk()->json('data');
        $s = $scan('ab12cd34');
        $this->assertEquals(['ok', 'Awa', 'Pass 1 jour'], [$s['checkin']['result'], $s['ticket']['holder_name'], $s['type']]);
        $s = $scan('AB12-CD34');
        $this->assertSame('duplicate', $s['checkin']['result']);
        $this->assertStringContainsString('Déjà entré aujourd\'hui', $s['checkin']['reason']);
        $this->assertSame('refused', $scan('ZZZZ-ZZZZ')['checkin']['result']);
        $this->assertSame('ok', $scan(url('/billet/'.$vipTicket->token))['checkin']['result']); // QR lu comme une URL
        // Billet d'un jour : refusé le lendemain ; VIP valable 3 jours
        $this->travel(1)->days();
        $this->assertStringContainsString('Valable du', $scan('EF56-GH78')['checkin']['reason']);
        $this->assertSame('ok', $scan($vipTicket->code)['checkin']['result']);
        $this->travelBack();
        // Passage enregistré hors ligne, réévalué par le serveur
        $c = $this->ok($push('ev_checkins', ['event_id' => $ev, 'code' => $vipTicket->token, 'scanned_at' => now()->toIso8601String(), 'gate' => 'Hors ligne']));
        $this->assertSame('duplicate', $c['result']);
        $this->assertSame(2, Ticket::find($vipTicket->id)->checkins_count);
        // Autre événement
        $ev2 = $this->ok($push('ev_events', ['name' => 'Autre', 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString()]))['id'];
        $this->assertSame('Billet d\'un autre événement', $scan('EF56-GH78', $ev2)['checkin']['reason']);

        // Badges exposants (réentrée libre)
        $this->ok($push('ev_events', ['deposit_percent' => 0], $ev));
        $sp = $this->ok($push('ev_spaces', ['event_id' => $ev, 'code' => 'S1', 'price' => 0, 'x' => 0, 'y' => 0]))['id'];
        $bk = $this->ok($push('ev_bookings', ['event_id' => $ev, 'partner_id' => $client('Label Musique'), 'space_ids' => [$sp]]))['id'];
        $api()->postJson("/api/billetterie/bookings/$bk/badges", ['names' => ['Moussa']])->assertStatus(422); // option
        $this->ok($push('ev_bookings', ['status' => 'confirmed'], $bk));
        $badges = $api()->postJson("/api/billetterie/bookings/$bk/badges", ['names' => ['Moussa', ' ', 'Fatou']])->assertCreated()->json('data');
        $this->assertCount(2, $badges);
        $this->assertEquals(['exhibitor', 'Label Musique', true], [$badges[0]['kind'], $badges[0]['company_name'], $badges[0]['reentry']]);
        $this->assertSame('ok', $scan($badges[0]['code'])['checkin']['result']);
        $this->assertSame('ok', $scan($badges[0]['code'])['checkin']['result']);

        // Annulation d'une commande : billets annulés et refusés à l'entrée
        $this->ok($push('ev_ticket_orders', ['status' => 'cancelled'], $o1));
        $this->assertSame('Billet annulé', $scan('EF56-GH78')['checkin']['reason']);

        // Statistiques et synchronisation
        $st = $api()->getJson("/api/billetterie/events/$ev/stats")->assertOk()->json('data');
        $this->assertEquals([2, 2, 30000, 30000], [$st['tickets'], $st['badges'], $st['revenue'], $st['online_revenue']]);
        $this->assertSame(4, $st['entries_today']);
        $this->assertSame(1, collect($st['by_type'])->firstWhere('name', 'Pass VIP 3 jours')['checked_in']);
        $pull = $api()->getJson('/api/sync/pull?cursor=0')->json('changes');
        $this->assertGreaterThanOrEqual(6, count($pull['ev_tickets']['upserts']));
        $this->assertSame(Checkin::count(), count($pull['ev_checkins']['upserts']));
        $api()->getJson('/api/billetterie/lookup?code='.$vipTicket->code)->assertOk()->assertJsonPath('data.holder_name', 'Kouassi Marc');
        // Billet gratuit en ligne : émis tout de suite
        $free = $this->ok($push('ev_ticket_types', ['event_id' => $ev2, 'name' => 'Entrée libre', 'price' => 0]));
        $this->ok($push('ev_events', ['ticketing_enabled' => true, 'status' => 'open'], $ev2));
        $this->post('/billetterie/'.Event::find($ev2)->public_token, ['name' => 'Inès', 'phone' => '0102030405', 'email' => 'ines@test.ci', 'qty' => [$free['id'] => 2]])->assertRedirect();
        $fo = TicketOrder::where('event_id', $ev2)->first();
        $this->assertEquals(['paid', 'free', 2], [$fo->status, $fo->payment_method, Ticket::where('order_id', $fo->id)->count()]);
        $this->assertNull($fo->invoice_id);
    }

    public function test_online_deposit_confirms_booking_on_payment_and_expiry_cancels_invoice(): void
    {
        ['push' => $push] = $this->env();
        $ev = $this->uuid();
        $this->ok($push('ev_events', ['name' => 'Foire de Bouaké', 'start_date' => now()->addDays(30)->toDateString(), 'end_date' => now()->addDays(32)->toDateString(), 'status' => 'open',
            'public_enabled' => true, 'online_deposit' => true, 'deposit_percent' => 50, 'option_days' => 3], $ev));
        $s1 = $this->ok($push('ev_spaces', ['event_id' => $ev, 'code' => 'A1', 'price' => 200000, 'x' => 0, 'y' => 0]))['id'];
        $s2 = $this->ok($push('ev_spaces', ['event_id' => $ev, 'code' => 'A2', 'price' => 200000, 'x' => 1, 'y' => 0]))['id'];
        $token = Event::find($ev)->public_token;
        $res = $this->post('/salon/'.$token, ['company' => 'Tissage Baoulé', 'name' => 'Mme Konan', 'phone' => '0505050505', 'spaces' => [$s1]]);
        $b = Booking::where('exhibitor_name', 'Tissage Baoulé')->first();
        $res->assertRedirect('/salon/'.$token.'/r/'.$b->public_token);
        $this->assertSame('option', $b->status);
        $dep = SalesDocument::find($b->deposit_invoice_id);
        $this->assertEquals(118000, (float) $dep->total);
        $this->get('/salon/'.$token.'/r/'.$b->public_token)->assertOk()->assertSee('Payer l\'acompte en ligne', false)->assertSee('118 000');
        $this->pay($push, $dep->id);
        $b->refresh();
        $this->assertSame('confirmed', $b->status);
        $this->assertSame($dep->id, $b->deposit_invoice_id);
        $this->assertStringContainsString('Acompte payé en ligne', AppNotification::where('module', 'evenements')->pluck('title')->implode('|'));
        $this->get('/salon/'.$token.'/r/'.$b->public_token)->assertSee('est confirmée');
        // Option non payée : expirée, acompte annulé
        $this->post('/salon/'.$token, ['company' => 'Retardataire', 'name' => 'M. X', 'phone' => '0606060606', 'spaces' => [$s2]]);
        $late = Booking::where('exhibitor_name', 'Retardataire')->first();
        $this->travel(5)->days();
        $this->artisan('erp:events')->assertSuccessful();
        $this->assertSame('expired', $late->fresh()->status);
        $this->assertSame('cancelled', SalesDocument::find($late->deposit_invoice_id)->status);
        // Reprise et confirmation manuelle : nouvel acompte
        $this->ok($push('ev_bookings', ['status' => 'option'], $late->id));
        $this->ok($push('ev_bookings', ['status' => 'confirmed'], $late->id));
        $this->assertNotSame($late->deposit_invoice_id, $late->fresh()->deposit_invoice_id);
        $this->assertSame('validated', SalesDocument::find($late->fresh()->deposit_invoice_id)->status);
    }

    public function test_hour_packs_alerts_overage_renewal_and_deliverables_validation(): void
    {
        Storage::fake('local');
        ['company' => $company, 'api' => $api, 'push' => $push, 'client' => $client] = $this->env();
        $cl = $client('Hôtel du Plateau', ['email' => 'dg@hotel.ci']);
        $pk = $this->uuid();
        $r = $this->ok($push('pr_packs', ['partner_id' => $cl, 'name' => 'Maintenance site et réseaux sociaux', 'hours' => 10, 'price' => 300000, 'tax_rate' => 18,
            'start_date' => now()->toDateString(), 'expiry_date' => now()->addMonths(3)->toDateString(), 'invoice' => true], $pk));
        $this->assertStringStartsWith('FOR-', $r['number']);
        $inv = SalesDocument::find(Pack::find($pk)->invoice_id);
        $this->assertEquals(354000, (float) $inv->total);
        $this->assertStringContainsString('Forfait 10 h', $inv->lines()->value('description'));
        $this->assertStringContainsString('ne change plus', $push('pr_packs', ['hours' => 12], $pk)['message']);
        // Temps saisi : alerte à 80 % puis 100 %, une seule fois chacune
        $e1 = $this->ok($push('pr_entries', ['pack_id' => $pk, 'date' => now()->toDateString(), 'hours' => 5, 'description' => 'Mise à jour WordPress']))['id'];
        $this->assertSame(0, AppNotification::where('module', 'prestations')->count());
        $this->ok($push('pr_entries', ['pack_id' => $pk, 'date' => now()->toDateString(), 'hours' => 3.5, 'description' => 'Visuels Facebook']));
        $this->assertSame(1, AppNotification::where('module', 'prestations')->where('title', 'like', 'Forfait consommé à 80 %%')->count());
        $this->ok($push('pr_entries', ['pack_id' => $pk, 'date' => now()->toDateString(), 'hours' => 2, 'description' => 'Correction formulaire']));
        $p = Pack::find($pk)->toSyncArray();
        $this->assertEquals([10.5, -0.5, 'exhausted', 0.5], [$p['consumed'], $p['remaining'], $p['status'], $p['overage_unbilled']]);
        $this->assertSame(1, AppNotification::where('module', 'prestations')->where('title', 'like', 'Forfait épuisé%')->count());
        $this->assertSame('rejected', $push('pr_entries', ['pack_id' => $pk, 'date' => now()->addDays(3)->toDateString(), 'hours' => 1, 'description' => 'Futur'])['status']);
        // Dépassement : taux horaire requis
        $api()->postJson("/api/prestations/packs/$pk/overage")->assertStatus(422)->assertJsonFragment(['message' => 'Indiquez le taux horaire de dépassement du forfait.']);
        $this->ok($push('pr_packs', ['hourly_rate' => 35000], $pk));
        $api()->postJson("/api/prestations/packs/$pk/overage")->assertOk()->assertJsonPath('data.subtotal', 17500)->assertJsonPath('pack.overage_unbilled', 0);
        $api()->postJson("/api/prestations/packs/$pk/overage")->assertStatus(422);
        // Suppression de temps : le forfait redevient actif
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'pr_entries', 'op' => 'delete', 'record_id' => $e1]]])->assertOk();
        $this->assertSame('active', Pack::find($pk)->status);
        // Renouvellement : nouveau forfait facturé, ancien clos
        $new = $api()->postJson("/api/prestations/packs/$pk/renew")->assertCreated()->json('data');
        $this->assertEquals([10, 'active', $pk], [$new['hours'], $new['status'], Pack::find($new['id'])->renewed_from]);
        $this->assertNotNull(Pack::find($new['id'])->invoice_id);
        $this->assertSame('exhausted', Pack::find($pk)->status);
        // Expiration : plus de saisie
        $this->travel(4)->months();
        $this->artisan('erp:prestations')->assertSuccessful();
        $this->assertSame('expired', Pack::find($new['id'])->status);
        $this->assertStringContainsString('expiré', $push('pr_entries', ['pack_id' => $new['id'], 'date' => now()->toDateString(), 'hours' => 1, 'description' => 'X'])['message']);
        $this->travelBack();

        // Livrable : fichier, envoi, demande de modifications, nouvelle version, validation
        $dl = $this->uuid();
        $this->ok($push('pr_deliverables', ['partner_id' => $cl, 'pack_id' => $pk, 'title' => 'Maquette page d\'accueil', 'description' => 'Proposition graphique', 'due_date' => now()->addDays(5)->toDateString()], $dl));
        $api()->post("/api/prestations/deliverables/$dl/file", ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422);
        $api()->post("/api/prestations/deliverables/$dl/file", ['file' => UploadedFile::fake()->create('maquette-v1.pdf', 200, 'application/pdf')])->assertOk()->assertJsonPath('data.file_name', 'maquette-v1.pdf');
        $d = Deliverable::find($dl);
        $this->get('/livrable/'.$d->token)->assertNotFound(); // pas encore envoyé
        $this->ok($push('pr_deliverables', ['status' => 'sent'], $dl));
        $this->assertNotNull(Deliverable::find($dl)->sent_at);
        $this->assertStringContainsString('attendez la décision', $push('pr_deliverables', ['status' => 'draft'], $dl)['message']);
        $this->get('/livrable/'.$d->token)->assertOk()->assertSee('Maquette page d&#039;accueil', false)->assertSee('maquette-v1.pdf');
        $this->get('/livrable/'.$d->token.'/fichier')->assertOk();
        $this->post('/livrable/'.$d->token, ['decision' => 'changes', 'name' => 'M. Diabaté'])->assertSessionHas('error', 'Décrivez les modifications souhaitées.');
        $this->post('/livrable/'.$d->token, ['decision' => 'changes', 'name' => 'M. Diabaté', 'comment' => 'Logo plus grand, couleurs de l\'hôtel'])->assertRedirect('/livrable/'.$d->token);
        $this->assertSame('changes', Deliverable::find($dl)->status);
        $this->assertStringContainsString('Modifications demandées : Maquette', AppNotification::where('module', 'prestations')->pluck('title')->implode('|'));
        $this->ok($push('pr_deliverables', ['new_version' => true], $dl));
        $this->assertEquals([2, 'draft'], [Deliverable::find($dl)->version_no, Deliverable::find($dl)->status]);
        $api()->post("/api/prestations/deliverables/$dl/file", ['file' => UploadedFile::fake()->create('maquette-v2.pdf', 200, 'application/pdf')])->assertOk();
        $this->ok($push('pr_deliverables', ['status' => 'sent'], $dl));
        $this->post('/livrable/'.$d->token, ['decision' => 'approve', 'name' => 'M. Diabaté'])->assertRedirect();
        $x = Deliverable::find($dl);
        $this->assertEquals(['approved', 'M. Diabaté'], [$x->status, $x->decided_by]);
        $this->assertSame(['sent', 'changes', 'sent', 'approved'], array_column($x->history, 'event'));
        $this->post('/livrable/'.$d->token, ['decision' => 'approve', 'name' => 'X'])->assertSessionHas('error');
        $this->assertStringContainsString('nouvelle version', $push('pr_deliverables', ['title' => 'Autre'], $dl)['message']);
        // Espace client
        $url = $api()->postJson("/api/portail/partners/$cl/invite", ['channel' => 'none'])->json('url');
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get(parse_url($url, PHP_URL_PATH).'/prestations')->assertOk()->assertSee('Maintenance site et réseaux sociaux')->assertSee('Validé')->assertSee('Visuels Facebook');
    }
}
