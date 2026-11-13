<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Modules\Billetterie\Models\Ticket;
use App\Modules\Billetterie\Models\TicketOrder;
use App\Modules\Evenements\Models\Event;
use Carbon\Carbon;
use Illuminate\Mail\Transport\ArrayTransport;
use Tests\TestCase;

/** Bornes d'accueil d'un salon : inscription sur place (même hors ligne), entrées par jour, provenance, rapport Excel. */
class KioskAttendanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Smtp::$transportFactory = fn () => new ArrayTransport;
    }

    protected function tearDown(): void
    {
        Smtp::$transportFactory = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_kiosk_registrations_entries_per_day_provenance_and_excel(): void
    {
        Carbon::setTestNow('2026-12-01 08:00:00');
        [$company, $admin, $token] = $this->makeCompany('Salon Agri');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = function (string $entity, array $data, ?string $id = null) use ($api) {
            $r = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data]]])->assertOk()->json('results.0');
            $this->assertSame('applied', $r['status'], json_encode($r));

            return $r['record'];
        };
        $ev = $this->uuid();
        $e = $push('ev_events', ['name' => 'SELAB 2026', 'venue' => 'Parc des expositions', 'city' => 'Abidjan', 'start_date' => '2026-12-01', 'end_date' => '2026-12-03', 'status' => 'open',
            'registration' => ['ask_email' => 'optional', 'ask_city' => 'required', 'ask_country' => 'optional', 'kiosk_welcome' => 'Bienvenue au SELAB', 'questions' => [
                ['id' => 'qprof', 'label' => 'Vous êtes', 'type' => 'select', 'options' => ['Éleveur', 'Étudiant', 'Professionnel']],
            ]]], $ev);
        $this->assertSame('required', $e['registration']['ask_city']);
        $this->assertSame('Bienvenue au SELAB', $e['registration']['kiosk_welcome']);
        $this->assertNull($e['registration']['kiosk_color']); // couleur par défaut
        $this->assertTrue($e['registration']['kiosk_logo']);
        $e2 = $push('ev_events', ['registration' => array_merge($e['registration'], ['kiosk_color' => '#0e6b4f', 'kiosk_logo' => false])], $ev);
        $this->assertSame('#0E6B4F', $e2['registration']['kiosk_color']);
        $this->assertFalse($e2['registration']['kiosk_logo']);
        $this->assertNull($push('ev_events', ['registration' => array_merge($e['registration'], ['kiosk_color' => 'red;}'])], $ev)['registration']['kiosk_color']);
        $type = $push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Visiteur', 'price' => 0]);

        // Inscription sur une borne : commande gratuite + billet préparé sur la borne + entrée immédiate
        $register = function (string $name, string $phone, ?string $email, string $city, string $kiosk, string $at, string $profile) use ($push, $ev, $type) {
            $order = $this->uuid();
            $ticket = $this->uuid();
            $code = strtoupper(substr(md5($name), 0, 4)).'-'.strtoupper(substr(md5($phone), 0, 4));
            $o = $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => $name, 'buyer_phone' => $phone, 'buyer_email' => $email, 'buyer_city' => $city, 'buyer_country' => 'Côte d\'Ivoire',
                'items' => [['type_id' => $type['id'], 'quantity' => 1]], 'status' => 'paid', 'payment_method' => 'free', 'kiosk' => $kiosk, 'registered_at' => $at,
                'answers' => [['id' => 'qprof', 'label' => 'Vous êtes', 'value' => $profile]], 'consent' => true,
                'tickets' => [['id' => $ticket, 'type_id' => $type['id'], 'code' => $code, 'token' => str_repeat('a', 8).bin2hex(random_bytes(12))]]], $order);
            $push('ev_checkins', ['event_id' => $ev, 'code' => $code, 'scanned_at' => $at, 'gate' => $kiosk]);

            return [$o, $code];
        };

        // Jour 1 : deux bornes
        [$o1, $c1] = $register('Awa Koné', '0707070701', 'awa@ex.ci', 'abidjan', 'Borne 1', '2026-12-01T09:10:00+00:00', 'Éleveur');
        $this->assertSame('kiosk', $o1['channel']);
        $this->assertSame('Borne 1', $o1['kiosk']);
        [, $c2] = $register('Yao Kouassi', '0707070702', null, 'Bouaké', 'Borne 2', '2026-12-01T10:20:00+00:00', 'Étudiant');
        // Borne restée hors ligne le jour 1 : synchronisée le lendemain, l'inscription reste comptée le jour 1
        Carbon::setTestNow('2026-12-02 08:30:00');
        $register('Fatou Bamba', '0707070703', 'fatou@ex.ci', 'ABIDJAN ', 'Borne 2', '2026-12-01T16:45:00+00:00', 'Éleveur');
        // Jour 2 : Awa revient (scan de son QR sur la borne), un nouveau visiteur
        $push('ev_checkins', ['event_id' => $ev, 'code' => $c1, 'scanned_at' => '2026-12-02T09:00:00+00:00', 'gate' => 'Borne 1']);
        $push('ev_checkins', ['event_id' => $ev, 'code' => $c1, 'scanned_at' => '2026-12-02T11:00:00+00:00', 'gate' => 'Borne 2']); // déjà entré aujourd'hui : pas compté deux fois
        $register('Jean Traoré', '0707070704', 'jean@ex.ci', 'Yamoussoukro', 'Borne 1', '2026-12-02T09:30:00+00:00', 'Professionnel');
        // Exposant (badge) : compté à part
        $badge = $push('ev_tickets', ['event_id' => $ev, 'kind' => 'exhibitor', 'holder_name' => 'Stand Ferme', 'code' => 'EXPO-0001', 'token' => str_repeat('b', 32)]);
        $push('ev_checkins', ['event_id' => $ev, 'code' => 'EXPO-0001', 'scanned_at' => '2026-12-02T07:30:00+00:00', 'gate' => 'Borne 1']);

        $a = $api()->getJson("/api/billetterie/events/$ev/attendance")->assertOk()->json('data');
        $t = $a['totals'];
        $this->assertSame(4, $t['registrations']);
        $this->assertSame(4, $t['kiosk_registrations']);
        $this->assertSame(4, $t['unique_visitors']);
        $this->assertSame(5, $t['entries']); // 3 le jour 1, 2 le jour 2
        $this->assertSame(1, $t['returning_visitors']);
        $this->assertSame(3, $t['with_email']);
        $days = collect($a['days'])->keyBy('date');
        $this->assertSame(['2026-12-01', '2026-12-02', '2026-12-03'], $days->keys()->all());
        $this->assertSame(3, $days['2026-12-01']['registrations']);
        $this->assertSame(3, $days['2026-12-01']['entries']);
        $this->assertSame(1, $days['2026-12-02']['registrations']);
        $this->assertSame(2, $days['2026-12-02']['entries']);
        $this->assertSame(1, $days['2026-12-02']['new_visitors']);
        $this->assertSame(1, $days['2026-12-02']['returning']);
        $this->assertSame(1, $days['2026-12-02']['badges']);
        $this->assertSame(1, $days['2026-12-02']['refused']); // second passage d'Awa le même jour
        $this->assertSame(0, $days['2026-12-03']['entries']);
        $k = collect($a['kiosks'])->keyBy('name');
        $this->assertSame(2, $k['Borne 1']['registrations']);
        $this->assertSame(2, $k['Borne 2']['registrations']);
        $this->assertSame(3, $k['Borne 1']['entries']); // Awa jour 1 et jour 2, Jean jour 2
        // Provenance : « abidjan », « ABIDJAN » regroupés
        $this->assertSame(['name' => 'Abidjan', 'count' => 2], $a['cities'][0]);
        $this->assertSame(4, $a['countries'][0]['count']);
        $this->assertSame('Éleveur', collect($a['answers'])->firstWhere('id', 'qprof')['distribution'][0]['value']);
        // Le billet porte le téléphone et l'e-mail : un visiteur qui revient est retrouvé sur toutes les bornes
        $this->assertSame('0707070701', Ticket::where('code', $c1)->first()->holder_phone);
        $this->assertSame('2026-12-01 16:45:00', TicketOrder::where('buyer_name', 'Fatou Bamba')->first()->registered_at->utc()->toDateTimeString());

        // Excel : 4 feuilles, liste des inscrits avec jours de présence
        $res = $api()->get("/api/billetterie/events/$ev/attendance/export")->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $this->assertSame(['Par jour', 'Par borne', 'Provenance', 'Inscrits'], $book->getSheetNames());
        $rows = $book->getSheetByName('Inscrits')->toArray();
        $this->assertContains('Vous êtes', $rows[0]);
        $awa = collect($rows)->first(fn ($r) => $r[4] === 'Awa Koné');
        $this->assertSame('awa@ex.ci', $awa[6]);
        $this->assertSame('01/12, 02/12', $awa[array_search('Jours de présence', $rows[0])]);
        $this->assertSame('Borne', $awa[2]);
        $parJour = $book->getSheetByName('Par jour')->toArray();
        $this->assertSame('TOTAL', end($parJour)[0]);

        // Opérateur de borne (contrôle d'entrée seulement) : statistiques oui, liste des coordonnées non
        $role = \App\Models\Role::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Opérateur borne', 'permissions' => ['billetterie.scan']]);
        $opToken = \App\Models\User::create(['company_id' => $company->id, 'name' => 'Borne', 'email' => 'borne@salon.ci', 'password' => 'secret123', 'role_id' => $role->id])->createToken('t')->plainTextToken;
        $this->api($opToken)->getJson("/api/billetterie/events/$ev/attendance")->assertOk();
        $this->api($opToken)->get("/api/billetterie/events/$ev/attendance/export")->assertForbidden();
    }

    public function test_same_kiosk_number_on_two_kiosks_never_loses_a_registration(): void
    {
        Carbon::setTestNow('2026-12-01 08:00:00');
        [, , $token] = $this->makeCompany('Salon C');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $ev = $this->uuid();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data]]])->json('results.0');
        $push('ev_events', ['name' => 'S', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02', 'status' => 'open'], $ev);
        $type = $push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Visiteur', 'price' => 0])['record'];
        $order = fn (string $name, string $kiosk) => $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => $name, 'buyer_phone' => '07'.random_int(10000000, 99999999), 'kiosk' => $kiosk,
            'items' => [['type_id' => $type['id'], 'quantity' => 1]], 'tickets' => [['id' => $this->uuid(), 'type_id' => $type['id'], 'code' => '010001', 'token' => \Illuminate\Support\Str::random(32)]]]);
        // Numéro de ticket court tapé par le visiteur les jours suivants : « 01-0001 » = « 010001 »
        $this->assertSame('applied', $order('Awa', 'Borne Nord')['status']);
        $this->assertSame('010001', Ticket::where('holder_name', 'Awa')->first()->code);
        // Deuxième borne réglée par erreur sur le même numéro : l'inscription passe, avec un autre code
        $this->assertSame('applied', $order('Yao', 'Borne Sud')['status']);
        $this->assertNotSame('010001', Ticket::where('holder_name', 'Yao')->first()->code);
        $this->assertSame(2, TicketOrder::count());
        // Le numéro court est accepté au contrôle d'entrée, avec ou sans tiret
        $this->assertSame('ok', $push('ev_checkins', ['event_id' => $ev, 'code' => '01-0001', 'gate' => 'Borne Nord'])['record']['result']);
    }

    public function test_registered_at_in_the_future_or_absent_falls_back_to_now(): void
    {
        Carbon::setTestNow('2026-12-01 08:00:00');
        [, , $token] = $this->makeCompany('Salon B');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $ev = $this->uuid();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data]]])->json('results.0.record');
        $push('ev_events', ['name' => 'S', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'status' => 'open'], $ev);
        $type = $push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Visiteur', 'price' => 0]);
        $o = $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'X', 'buyer_phone' => '01', 'items' => [['type_id' => $type['id'], 'quantity' => 1]], 'kiosk' => 'B', 'registered_at' => '2027-05-01T10:00:00Z']);
        $this->assertSame('2026-12-01T08:00:00+00:00', Carbon::parse($o['registered_at'])->utc()->toIso8601String());
        $o2 = $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'Y', 'buyer_phone' => '02', 'items' => [['type_id' => $type['id'], 'quantity' => 1]]]);
        $this->assertSame('box_office', $o2['channel']);
        $this->assertNull($o2['kiosk']);
    }
}
