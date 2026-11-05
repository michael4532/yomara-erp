<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Modules\Location\LocationHooks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Location de matériel : tarifs selon la durée, disponibilités, sortie, retour en retard, dégâts, caution, facture. */
class LocationTest extends TestCase
{
    public function test_rental_pricing_availability_return_deposit_and_invoice(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Loc Matériel SARL');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/location/schema')->assertOk();
        $this->assertNotNull(Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Gestionnaire location')->first());
        $client = $this->uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'partners', 'op' => 'upsert', 'record_id' => $client, 'data' => ['name' => 'Events Plus']]]])->assertOk();

        $gen = $api()->postJson('/api/location/records/items', ['title' => 'Groupe électrogène 20 kVA', 'category' => 'Énergie', 'rate_day' => 45000, 'rate_week' => 250000, 'deposit' => 300000])
            ->assertCreated()->assertJsonPath('data.availability', 'Disponible')->json('data');
        $chairs = $api()->postJson('/api/location/records/items', ['title' => 'Chaise pliante', 'category' => 'Mobilier', 'quantity' => 200, 'rate_day' => 300, 'deposit' => 1000])
            ->assertCreated()->assertJsonPath('data.availability', '200 / 200')->json('data');

        $start = now()->addDay()->setTime(8, 0);
        $r = $api()->postJson('/api/location/records/rentals', ['partner_id' => $client, 'start_at' => $start->toDateTimeString(), 'end_at' => $start->copy()->addDays(3)->toDateTimeString(),
            'delivery' => true, 'delivery_fee' => 25000])->assertCreated()->assertJsonPath('data.status', 'quote')->assertJsonPath('data.period', '3 j')->json('data');
        $this->assertSame('LOC-'.now()->format('Y').'-0001', $r['number']);
        $api()->postJson('/api/location/records/rentals', ['start_at' => $start->toDateTimeString(), 'end_at' => $start->copy()->subHour()->toDateTimeString(), 'customer_name' => 'X'])->assertStatus(422);
        $api()->postJson('/api/location/rentals/'.$r['id'].'/reserve')->assertStatus(422); // pas de matériel

        // tarif journalier × 3 jours ; semaine moins chère pour un groupe
        $api()->postJson('/api/location/records/lines', ['rental_id' => $r['id'], 'item_id' => $gen['id']])->assertCreated()->assertJsonPath('data.amount', 135000);
        $l2 = $api()->postJson('/api/location/records/lines', ['rental_id' => $r['id'], 'item_id' => $chairs['id'], 'quantity' => 150])->assertCreated()->json('data');
        $this->assertEquals(135000, $l2['amount']);
        $this->assertEquals(295000, $api()->getJson('/api/location/records/rentals/'.$r['id'])->json('data.total'));

        // réservation : caution calculée (300 000 + 150 × 1 000), matériel bloqué
        $api()->postJson('/api/location/rentals/'.$r['id'].'/reserve', ['deposit_received' => true, 'deposit_method' => 'mobile_money'])->assertOk()
            ->assertJsonPath('data.status', 'reserved')->assertJsonPath('data.deposit_status', 'received');
        $this->assertEquals(450000, DB::table('lo_rentals')->find($r['id'])->deposit_amount);
        $free = collect($api()->getJson('/api/location/availability?start='.urlencode($start->copy()->addDay()->toDateTimeString()).'&end='.urlencode($start->copy()->addDays(2)->toDateTimeString()))->json('data'));
        $this->assertSame(0, $free->firstWhere('id', $gen['id'])['free']);
        $this->assertSame(50, $free->firstWhere('id', $chairs['id'])['free']);

        // second client sur la même période : refusé pour le groupe, 50 chaises possibles
        $r2 = $api()->postJson('/api/location/records/rentals', ['customer_name' => 'Mariage Kouadio', 'phone' => '0707070707', 'start_at' => $start->copy()->addDays(2)->toDateTimeString(),
            'end_at' => $start->copy()->addDays(4)->toDateTimeString()])->assertCreated()->json('data');
        $api()->postJson('/api/location/records/lines', ['rental_id' => $r2['id'], 'item_id' => $gen['id']])->assertCreated();
        $msg = $api()->postJson('/api/location/rentals/'.$r2['id'].'/reserve')->assertStatus(422)->json('message');
        $this->assertStringContainsString('0 disponible(s)', $msg);
        $api()->deleteJson('/api/location/records/lines/'.DB::table('lo_lines')->where('rental_id', $r2['id'])->value('id'))->assertNoContent();
        $this->assertEquals(0, DB::table('lo_rentals')->find($r2['id'])->total);
        $api()->postJson('/api/location/records/lines', ['rental_id' => $r2['id'], 'item_id' => $chairs['id'], 'quantity' => 60])->assertCreated();
        $api()->postJson('/api/location/rentals/'.$r2['id'].'/reserve')->assertStatus(422);
        $api()->putJson('/api/location/records/lines/'.DB::table('lo_lines')->where('rental_id', $r2['id'])->value('id'), ['quantity' => 50])->assertOk();
        $api()->postJson('/api/location/rentals/'.$r2['id'].'/reserve')->assertOk();
        // une ligne d'un contrat réservé ne peut pas dépasser le stock libre
        $api()->putJson('/api/location/records/lines/'.$l2['id'], ['quantity' => 151])->assertStatus(422);

        // rappel la veille du départ
        $this->travelTo($start->copy()->subHours(12));
        $this->assertSame(1, app(LocationHooks::class)->alerts($company));
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'Départ en location à préparer')->exists());

        // sortie, puis retour avec un jour de retard et une chaise cassée
        $this->travelTo($start->copy()->addMinutes(10));
        $api()->postJson('/api/location/rentals/'.$r['id'].'/checkout', ['conditions' => [$l2['id'] => 'Bon état']])->assertOk()->assertJsonPath('data.status', 'out');
        $api()->putJson('/api/location/records/lines/'.$l2['id'], ['quantity' => 10])->assertStatus(422);
        $api()->deleteJson('/api/location/records/rentals/'.$r['id'])->assertStatus(422);
        $this->assertSame('Loué', $api()->getJson('/api/location/records/items/'.$gen['id'])->json('data.availability'));

        $this->travelTo($start->copy()->addDays(4)->addMinutes(30)); // 24 h 30 de retard : 1 jour entamé
        $this->assertSame(1, app(LocationHooks::class)->alerts($company)); // pas rendu
        $back = $api()->postJson('/api/location/rentals/'.$r['id'].'/return', ['deposit_kept' => 5000, 'lines' => [$l2['id'] => ['condition_in' => '1 chaise cassée', 'damage_fee' => 5000]]])
            ->assertOk()->assertJsonPath('data.status', 'returned')->assertJsonPath('data.deposit_status', 'partial')->json('data');
        $this->assertEquals(45000 + 150 * 300, $back['late_fee']); // 1 jour de retard au tarif journalier
        $this->assertEquals(5000, $back['damage_fee']);
        $this->assertEquals(295000 + 90000 + 5000, $back['total']);

        // facture brouillon ; la caution n'est pas facturée
        $inv = $api()->postJson('/api/location/rentals/'.$r['id'].'/invoice')->assertOk()->assertJsonPath('data.status', 'invoiced')->json('data');
        $doc = DB::table('trade_documents')->where('id', $inv['invoice_id'])->first();
        $this->assertSame('draft', $doc->status);
        $this->assertEqualsWithDelta(390000 * 1.18, (float) $doc->total, 1);
        $api()->postJson('/api/location/rentals/'.$r['id'].'/invoice')->assertStatus(422);
        $api()->putJson('/api/location/records/rentals/'.$r['id'], ['notes' => 'x'])->assertStatus(422);

        $this->travelBack();
        $d = $api()->getJson('/api/location/dashboard')->assertOk()->json('data');
        $this->assertSame(201, $d['units']);
        $this->assertSame(0, $d['units_out']);
        $api()->deleteJson('/api/location/records/items/'.$gen['id'])->assertStatus(422);
        $api()->postJson('/api/location/rentals/'.$r2['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    }
}
