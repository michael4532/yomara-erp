<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Agenda\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Rendez-vous ↔ Agenda : le rendez-vous apparaît dans l'agenda du praticien ; ses réunions et congés bloquent les créneaux. */
class RdvAgendaTest extends TestCase
{
    public function test_appointments_in_agenda_and_agenda_blocks_slots(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Cabinet Agenda');
        $api = fn () => $this->api($token);
        $api()->putJson('/api/company', ['settings' => ['rdv_slot_step' => 30, 'rdv_min_notice_hours' => 0, 'timezone' => 'Africa/Abidjan']])->assertOk();
        $doc = User::create(['company_id' => $company->id, 'name' => 'Dr Koné', 'email' => 'kone@cab.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Praticien')->value('id')]);
        $week = ['1' => [['08:00', '12:00']], '2' => [['08:00', '12:00']], '3' => [['08:00', '12:00']], '4' => [['08:00', '12:00']], '5' => [['08:00', '12:00']], '6' => [], '7' => []];
        $st = $api()->postJson('/api/rendez_vous/staff', ['name' => 'Dr Koné', 'user_id' => $doc->id, 'schedule' => $week])->assertCreated()->json('data');
        $sv = $api()->postJson('/api/rendez_vous/services', ['name' => 'Consultation', 'duration_minutes' => 30, 'price' => 10000])->assertCreated()->json('data');
        $monday = Carbon::now('Africa/Abidjan')->next(Carbon::MONDAY)->toDateString();
        $slots = fn () => collect($api()->getJson("/api/rendez_vous/slots?service_id={$sv['id']}&date=$monday")->assertOk()->json('data'))->pluck('time')->all();
        $this->assertCount(8, $slots());

        // Rendez-vous → événement privé dans l'agenda du praticien
        $at = Carbon::parse("$monday 09:00", 'Africa/Abidjan');
        $id = $api()->postJson('/api/rendez_vous/appointments', ['staff_id' => $st['id'], 'service_id' => $sv['id'], 'starts_at' => $at->toIso8601String(), 'customer_name' => 'Awa Diallo', 'customer_phone' => '0707070707'])
            ->assertCreated()->json('data.id');
        $evId = DB::table('rdv_appointments')->where('id', $id)->value('agenda_event_id');
        $ev = Event::withoutGlobalScopes()->find($evId);
        $this->assertEquals([$doc->id, 'appointment', 'private', 'RDV Consultation · Awa Diallo'], [$ev->owner_id, $ev->category, $ev->visibility, $ev->title]);
        $this->assertSame($at->copy()->utc()->toDateTimeString(), $ev->starts_at->toDateTimeString());
        $this->assertNotContains('09:00', $slots()); // pris par le rendez-vous, pas compté deux fois
        $this->assertCount(7, $slots());

        // Déplacement : l'événement suit ; annulation : il disparaît
        $api()->putJson("/api/rendez_vous/appointments/$id", ['starts_at' => $at->copy()->addHour()->toIso8601String()])->assertOk();
        $this->assertSame($at->copy()->addHour()->utc()->toDateTimeString(), Event::withoutGlobalScopes()->find($evId)->starts_at->toDateTimeString());
        $api()->putJson("/api/rendez_vous/appointments/$id", ['status' => 'cancelled'])->assertOk();
        $this->assertTrue(Event::withoutGlobalScopes()->find($evId)->trashed());
        $this->assertCount(8, $slots());

        // Réunion d'agenda (ex. venue d'Outlook) et congé hebdomadaire récurrent : créneaux bloqués
        $mk = fn (array $d) => (new Event)->forceFill(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'status' => 'confirmed', 'category' => 'meeting', 'visibility' => 'public', 'all_day' => false] + $d)->save();
        $mk(['title' => 'Staff', 'owner_id' => $admin->id, 'attendee_ids' => [$doc->id], 'starts_at' => Carbon::parse("$monday 10:00", 'Africa/Abidjan')->utc(), 'ends_at' => Carbon::parse("$monday 11:00", 'Africa/Abidjan')->utc()]);
        $mk(['title' => 'Refusée', 'owner_id' => $admin->id, 'attendee_ids' => [$doc->id], 'responses' => [(string) $doc->id => 'declined'], 'starts_at' => Carbon::parse("$monday 08:00", 'Africa/Abidjan')->utc(), 'ends_at' => Carbon::parse("$monday 08:30", 'Africa/Abidjan')->utc()]);
        $mk(['title' => 'Bloc opératoire', 'owner_id' => $doc->id, 'starts_at' => Carbon::parse("$monday 11:30", 'Africa/Abidjan')->subWeeks(2)->utc(), 'ends_at' => Carbon::parse("$monday 12:00", 'Africa/Abidjan')->subWeeks(2)->utc(),
            'recurrence' => ['freq' => 'weekly', 'interval' => 1]]);
        $this->assertSame(['08:00', '08:30', '09:00', '09:30', '11:00'], $slots());
        // Réglage désactivé : l'agenda ne bloque plus
        $api()->putJson('/api/company', ['settings' => ['rdv_agenda_busy' => false]])->assertOk();
        $this->assertCount(8, $slots());
    }
}
