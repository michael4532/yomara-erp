<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use Carbon\Carbon;
use Illuminate\Mail\Transport\ArrayTransport;
use Tests\TestCase;

/**
 * Accueil sur smartphone ou borne sans imprimante : le billet part par e-mail à l'inscription, et le visiteur qui revient
 * un autre jour (numéro tapé) reçoit un e-mail de bienvenue du jour, avec le même numéro et le programme du jour.
 */
class KioskWelcomeBackTest extends TestCase
{
    protected ArrayTransport $mails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mails = new ArrayTransport;
        Smtp::$transportFactory = fn () => $this->mails;
    }

    protected function tearDown(): void
    {
        Smtp::$transportFactory = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mailsTo(string $email): array
    {
        return collect($this->mails->messages()->values())->map->getOriginalMessage()->filter(fn ($m) => $m->getTo()[0]->getAddress() === $email)->values()->all();
    }

    public function test_clear_test_registrations_before_opening(): void
    {
        Carbon::setTestNow('2026-11-19 10:00:00');
        [, , $token] = $this->makeCompany('Salon Test');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = function (string $entity, array $data, ?string $id = null) use ($api) {
            $r = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data]]])->assertOk()->json('results.0');
            $this->assertSame('applied', $r['status'], json_encode($r));

            return $r['record'];
        };
        $ev = $this->uuid();
        $push('ev_events', ['name' => 'SELAB', 'start_date' => '2026-11-20', 'end_date' => '2026-11-22', 'status' => 'open'], $ev);
        $type = $push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Visiteur', 'price' => 0]);
        $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'Test 1', 'buyer_phone' => '0707070707', 'items' => [['type_id' => $type['id'], 'quantity' => 1]], 'status' => 'paid', 'payment_method' => 'free', 'kiosk' => 'Borne 1',
            'tickets' => [['id' => $this->uuid(), 'type_id' => $type['id'], 'code' => '010001', 'token' => str_repeat('c', 32)]]]);
        $push('ev_checkins', ['event_id' => $ev, 'code' => '010001', 'scanned_at' => '2026-11-19T10:00:00+00:00', 'gate' => 'Borne 1']);
        $r = $api()->postJson("/api/billetterie/events/{$ev}/clear-tests")->assertOk()->json('data');
        $this->assertSame(1, $r['orders']);
        $this->assertSame(1, $r['tickets']);
        $this->assertSame(1, $r['checkins']);
        $this->assertSame(0, $api()->getJson("/api/billetterie/events/{$ev}/attendance")->json('data.totals.registrations'));
        // la borne repart à 01-0001 : le numéro est de nouveau libre
        $again = $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'Visiteur', 'buyer_phone' => '0505050505', 'items' => [['type_id' => $type['id'], 'quantity' => 1]], 'status' => 'paid', 'payment_method' => 'free', 'kiosk' => 'Borne 1',
            'tickets' => [['id' => $this->uuid(), 'type_id' => $type['id'], 'code' => '010001', 'token' => str_repeat('d', 32)]]]);
        $this->assertSame('010001', \App\Modules\Billetterie\Models\Ticket::where('order_id', $again['id'])->value('code'));
        // une fois l'événement commencé : refusé
        Carbon::setTestNow('2026-11-20 09:00:00');
        $api()->postJson("/api/billetterie/events/{$ev}/clear-tests")->assertStatus(422);
    }

    public function test_ticket_by_email_then_welcome_email_each_new_day(): void
    {
        Carbon::setTestNow('2026-12-01 08:00:00');
        [, , $token] = $this->makeCompany('Salon Agri');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.s.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'e@s.ci', 'password' => 'x', 'from_email' => 'e@s.ci', 'from_name' => 'Salon',
        ]])->assertOk();
        $push = function (string $entity, array $data, ?string $id = null) use ($api) {
            $r = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data]]])->assertOk()->json('results.0');
            $this->assertSame('applied', $r['status'], json_encode($r));

            return $r['record'];
        };
        $ev = $this->uuid();
        $e = $push('ev_events', ['name' => 'SELAB 2026', 'city' => 'Abidjan', 'start_date' => '2026-12-01', 'end_date' => '2026-12-03', 'status' => 'open',
            'registration' => ['ask_email' => 'optional', 'day_messages' => [
                '2026-12-01' => 'Ouverture officielle à 10 h.',
                '2026-12-02' => 'Concours de bétail à 11 h, hall B.',
                'n\'importe quoi' => 'ignoré',
            ]]], $ev);
        $this->assertSame(['2026-12-01', '2026-12-02'], array_keys($e['registration']['day_messages']));
        $this->assertTrue($e['registration']['welcome_back_email']);
        $type = $push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Visiteur', 'price' => 0]);

        // Jour 1 : inscription sur un téléphone sans imprimante → billet par e-mail (numéro, QR code, programme du jour)
        $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'Awa Koné', 'buyer_phone' => '0707070701', 'buyer_email' => 'awa@ex.ci',
            'items' => [['type_id' => $type['id'], 'quantity' => 1]], 'status' => 'paid', 'payment_method' => 'free', 'kiosk' => 'Téléphone 1', 'registered_at' => '2026-12-01T09:10:00+00:00',
            'tickets' => [['id' => $this->uuid(), 'type_id' => $type['id'], 'code' => '010001', 'token' => str_repeat('b', 32)]]]);
        $push('ev_checkins', ['event_id' => $ev, 'code' => '010001', 'scanned_at' => '2026-12-01T09:10:00+00:00', 'gate' => 'Téléphone 1']);
        $m = $this->mailsTo('awa@ex.ci');
        $this->assertCount(1, $m); // le jour de l'inscription, seulement la confirmation
        $this->assertStringContainsString('Inscription confirmée', $m[0]->getSubject());
        $this->assertStringContainsString('Ouverture officielle à 10 h.', $m[0]->getHtmlBody());
        $this->assertStringContainsString('010001', $m[0]->getHtmlBody());
        // QR code en image PNG intégrée (les messageries n'affichent pas le SVG)
        $this->assertStringContainsString('src="cid:', $m[0]->getHtmlBody());
        $this->assertStringNotContainsString('image/svg', $m[0]->getHtmlBody());

        // Jour 2 : elle tape son numéro → e-mail « Bienvenue pour le jour 2 », même numéro, programme du jour 2
        Carbon::setTestNow('2026-12-02 09:05:00');
        $push('ev_checkins', ['event_id' => $ev, 'code' => '010001', 'scanned_at' => '2026-12-02T09:00:00+00:00', 'gate' => 'Téléphone 2']);
        $m = $this->mailsTo('awa@ex.ci');
        $this->assertCount(2, $m);
        $this->assertSame('Bienvenue pour le jour 2 — SELAB 2026', $m[1]->getSubject());
        $html = $m[1]->getHtmlBody();
        $this->assertStringContainsString('010001', $html);
        $this->assertStringContainsString('Concours de bétail à 11 h, hall B.', $html);
        $this->assertStringContainsString('Awa Koné', $html);
        $this->assertStringContainsString('src="cid:', $html);

        // Revenue une seconde fois le même jour : pas de second e-mail
        $push('ev_checkins', ['event_id' => $ev, 'code' => '010001', 'scanned_at' => '2026-12-02T14:00:00+00:00', 'gate' => 'Téléphone 1']);
        $this->assertCount(2, $this->mailsTo('awa@ex.ci'));

        // E-mail de bienvenue désactivé par l'organisateur : rien le jour 3
        $push('ev_events', ['registration' => array_merge($e['registration'], ['welcome_back_email' => false])], $ev);
        Carbon::setTestNow('2026-12-03 09:05:00');
        $push('ev_checkins', ['event_id' => $ev, 'code' => '010001', 'scanned_at' => '2026-12-03T09:00:00+00:00', 'gate' => 'Téléphone 1']);
        $this->assertCount(2, $this->mailsTo('awa@ex.ci'));

        // Les entrées du jour 2 restent comptées dans la fréquentation
        $att = $api()->getJson("/api/billetterie/events/{$ev}/attendance")->assertOk()->json('data');
        $this->assertSame(1, collect($att['days'])->firstWhere('date', '2026-12-02')['entries']);
    }
}
