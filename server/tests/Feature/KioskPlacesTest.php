<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\TestCase;

/** Fréquentation : villes avec leurs communes (Abidjan), visiteurs par jour, évolution par rapport à la veille, rapport Excel. */
class KioskPlacesTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_places_grouped_by_city_and_day_over_day_change(): void
    {
        Carbon::setTestNow('2026-12-03 18:00:00');
        [, , $token] = $this->makeCompany('Salon Agri');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = function (string $entity, array $data, ?string $id = null) use ($api) {
            $r = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data]]])->assertOk()->json('results.0');
            $this->assertSame('applied', $r['status'], json_encode($r));

            return $r['record'];
        };
        $ev = $this->uuid();
        $push('ev_events', ['name' => 'SELAB 2026', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02', 'status' => 'open'], $ev);
        $type = $push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Visiteur', 'price' => 0]);
        $n = 0;
        $visitor = function (?string $city, array $days) use ($push, $ev, $type, &$n) {
            $code = sprintf('01%04d', ++$n);
            $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => "V$n", 'buyer_phone' => '07070707'.sprintf('%02d', $n), 'buyer_city' => $city,
                'items' => [['type_id' => $type['id'], 'quantity' => 1]], 'status' => 'paid', 'payment_method' => 'free', 'kiosk' => 'Téléphone 1', 'registered_at' => $days[0].'T09:00:00+00:00',
                'tickets' => [['id' => $this->uuid(), 'type_id' => $type['id'], 'code' => $code, 'token' => str_repeat(chr(96 + $n), 32)]]]);
            foreach ($days as $d) {
                $push('ev_checkins', ['event_id' => $ev, 'code' => $code, 'scanned_at' => $d.'T10:00:00+00:00', 'gate' => 'Téléphone 1']);
            }
        };
        $visitor('yopougon', ['2026-12-01', '2026-12-02']);
        $visitor('Cocody', ['2026-12-01']);
        $visitor('Abidjan', ['2026-12-01']);
        $visitor('Abidjan', ['2026-12-01']);
        $visitor('BOUAKE', ['2026-12-02']);
        $visitor(null, ['2026-12-02']);
        $visitor('Cocody', ['2026-12-02']);

        $a = $api()->getJson("/api/billetterie/events/{$ev}/attendance")->assertOk()->json('data');
        $abj = collect($a['places'])->firstWhere('name', 'Abidjan');
        $this->assertSame(5, $abj['registrations']);
        $this->assertSame(5, $abj['visitors']);
        $this->assertSame([4, 2], array_column($abj['days'], 'entries'));
        $this->assertSame(['Cocody', 'Yopougon'], array_column($abj['communes'], 'name'));
        $this->assertSame([1, 1], array_column(collect($abj['communes'])->firstWhere('name', 'Yopougon')['days'], 'entries'));
        $this->assertSame(1, collect($a['places'])->firstWhere('name', 'Bouaké')['registrations']);
        $this->assertSame('Non renseignée', last($a['places'])['name']);

        // jour 1 : 4 visiteurs, jour 2 : 4 visiteurs (Yopougon revenu + 3 nouveaux) → 0 % ; inscrits 4 puis 3 → −25 %
        [$d1, $d2] = $a['days'];
        $this->assertNull($d1['entries_change']);
        $this->assertSame(4, $d2['entries']);
        $this->assertEquals(0, $d2['entries_change']);
        $this->assertSame(-1, $d2['registrations_diff']);
        $this->assertEquals(-25, $d2['registrations_change']);

        $api()->get("/api/billetterie/events/{$ev}/attendance/export")->assertOk();
    }
}
