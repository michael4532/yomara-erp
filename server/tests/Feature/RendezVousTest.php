<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\RendezVous\BookingService;
use App\Modules\RendezVous\Models\Appointment;
use App\Modules\Ventes\Models\SalesDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RendezVousTest extends TestCase
{
    public function test_slots_online_booking_rules_reminders_cancel_reschedule_invoice_and_portal(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Salon Élégance');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['rdv_appointments']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $api()->putJson('/api/messaging/channels/whatsapp', ['provider' => 'meta', 'settings' => ['phone_number_id' => '77', 'access_token' => 'tok']])->assertOk();
        $api()->putJson('/api/company', ['settings' => ['rdv_slot_step' => 30, 'rdv_min_notice_hours' => 2]])->assertOk();
        $prat = User::create(['company_id' => $company->id, 'name' => 'Aïcha Coiffeuse', 'email' => 'aicha@salon.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Praticien')->value('id')]);
        $papi = fn () => $this->api($prat->createToken('p')->plainTextToken);
        $week = fn (array $r) => ['1' => $r, '2' => $r, '3' => $r, '4' => $r, '5' => $r, '6' => $r, '7' => []];
        $a = $api()->postJson('/api/rendez_vous/staff', ['name' => 'Aïcha', 'user_id' => $prat->id, 'schedule' => $week([['08:00', '12:00']])])->assertCreated()->json('data');
        $b = $api()->postJson('/api/rendez_vous/staff', ['name' => 'Bintou', 'schedule' => ['1' => [['14:00', '18:00']]]])->assertCreated()->json('data');
        $cut = $api()->postJson('/api/rendez_vous/services', ['name' => 'Coupe & brushing', 'duration_minutes' => 30, 'price' => 5900, 'category' => 'Coiffure'])->assertCreated()->json('data');
        $braids = $api()->postJson('/api/rendez_vous/services', ['name' => 'Tresses', 'duration_minutes' => 120, 'price' => 15000, 'staff_ids' => [$a['id']], 'requires_approval' => true])->assertCreated()->json('data');
        $monday = Carbon::now('Africa/Abidjan')->next(Carbon::MONDAY)->toDateString();

        // Créneaux : 8 le matin (Aïcha) + 8 l'après-midi (Bintou)
        $slots = $api()->getJson("/api/rendez_vous/slots?service_id={$cut['id']}&date=$monday")->assertOk()->json('data');
        $this->assertCount(16, $slots);
        $this->assertSame(['08:00', '17:30'], [$slots[0]['time'], end($slots)['time']]);
        $this->assertCount(5, $api()->getJson("/api/rendez_vous/slots?service_id={$braids['id']}&date=$monday")->json('data')); // 08:00 → 10:00, Aïcha seule

        // Réservation publique
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $slug = $company->fresh()->slug;
        $this->get("/rdv/$slug")->assertOk()->assertSee('Coupe &amp; brushing', false)->assertSee('Tresses');
        $this->get("/rdv/$slug?service={$cut['id']}&date=$monday")->assertOk()->assertSee('09:00')->assertSee('17:30');
        $nine = Carbon::parse("$monday 09:00", 'Africa/Abidjan')->toIso8601String();
        $this->get("/rdv/$slug/confirmer?service={$cut['id']}&start=".urlencode($nine))->assertOk()->assertSee('Confirmer le rendez-vous');
        $this->post("/rdv/$slug", ['service_id' => $cut['id'], 'starts_at' => $nine, 'name' => 'Mme Koffi Adjoua'])->assertSessionHasErrors('phone');
        $this->post("/rdv/$slug", ['service_id' => $cut['id'], 'starts_at' => $nine, 'name' => 'Mme Koffi Adjoua', 'phone' => '07 01 02 03 04', 'website' => 'spam'])->assertSessionHasErrors('website');
        $res = $this->post("/rdv/$slug", ['service_id' => $cut['id'], 'starts_at' => $nine, 'name' => 'Mme Koffi Adjoua', 'phone' => '07 01 02 03 04', 'notes' => 'Cheveux longs']);
        $appt = Appointment::withoutGlobalScopes()->latest('created_at')->first();
        $res->assertRedirect('/rdv/a/'.$appt->token);
        $this->assertSame(['confirmed', $a['id'], 'online', 5900.0], [$appt->status, $appt->staff_id, $appt->source, (float) $appt->price]);
        $this->assertSame('Mme Koffi Adjoua', Partner::withoutGlobalScopes()->find($appt->partner_id)->name);
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'confirm') && str_contains(json_encode($r->data(), JSON_UNESCAPED_SLASHES), '/rdv/a/'));
        $this->assertTrue(AppNotification::where('user_id', $prat->id)->where('module', 'rendez_vous')->exists());
        $this->get('/rdv/a/'.$appt->token)->assertOk()->assertSee('Confirmé')->assertSee('Aïcha')->assertSee('Annuler le rendez-vous');
        $this->get('/rdv/a/'.$appt->token.'/calendrier.ics')->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        // Le même créneau n'est plus disponible
        $this->post("/rdv/$slug", ['service_id' => $cut['id'], 'starts_at' => $nine, 'name' => 'Autre', 'phone' => '0505050505'])->assertSessionHas('error');
        $this->assertCount(15, $api()->getJson("/api/rendez_vous/slots?service_id={$cut['id']}&date=$monday")->json('data'));

        // Le praticien gère son agenda, pas celui des autres ; pas de chevauchement
        $ten = Carbon::parse("$monday 10:00", 'Africa/Abidjan')->utc()->toIso8601String();
        $papi()->postJson('/api/rendez_vous/appointments', ['staff_id' => $a['id'], 'service_id' => $cut['id'], 'starts_at' => $ten, 'customer_name' => 'M. Yao', 'price' => 1])
            ->assertCreated()->assertJsonPath('data.price', 5900);
        $papi()->postJson('/api/rendez_vous/appointments', ['staff_id' => $a['id'], 'service_id' => $cut['id'], 'starts_at' => Carbon::parse($ten)->addMinutes(15)->toIso8601String(), 'customer_name' => 'X'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Créneau déjà pris : M. Yao de 10:00 à 10:30.']);
        $papi()->postJson('/api/rendez_vous/appointments', ['staff_id' => $b['id'], 'service_id' => $cut['id'], 'starts_at' => $ten, 'customer_name' => 'X'])->assertStatus(422);

        // Prestation sur demande : à confirmer, puis confirmée (message au client)
        $this->flushHeaders();
        $tuesday = Carbon::parse($monday)->addDay()->toDateString();
        $eleven = Carbon::parse("$tuesday 08:00", 'Africa/Abidjan')->toIso8601String();
        $this->post("/rdv/$slug", ['service_id' => $braids['id'], 'starts_at' => $eleven, 'name' => 'Awa', 'phone' => '0102030405']);
        $pending = Appointment::withoutGlobalScopes()->where('service_id', $braids['id'])->first();
        $this->assertSame('pending', $pending->status);
        $this->assertSame(120, (int) $pending->starts_at->diffInMinutes($pending->ends_at));
        $sent = count(Http::recorded());
        $api()->putJson("/api/rendez_vous/appointments/{$pending->id}", ['status' => 'confirmed'])->assertOk();
        $this->assertSame($sent + 1, count(Http::recorded()));

        // Fermeture de l'après-midi : les créneaux de Bintou disparaissent
        $api()->postJson('/api/rendez_vous/closures', ['starts_at' => Carbon::parse("$monday 13:00", 'Africa/Abidjan')->toIso8601String(), 'ends_at' => Carbon::parse("$monday 19:00", 'Africa/Abidjan')->toIso8601String(), 'reason' => 'Formation'])->assertCreated();
        $left = collect($api()->getJson("/api/rendez_vous/slots?service_id={$cut['id']}&date=$monday")->json('data'))->pluck('time');
        $this->assertFalse($left->contains('14:00'));
        $this->assertSame(['08:00', '08:30', '09:30', '10:30', '11:00', '11:30'], $left->values()->all()); // 9 h et 10 h sont pris

        // Le client déplace puis annule son rendez-vous
        $this->flushHeaders();
        $this->post("/rdv/$slug", ['service_id' => $cut['id'], 'starts_at' => Carbon::parse("$monday 08:30", 'Africa/Abidjan')->toIso8601String(), 'reschedule' => $appt->token])->assertRedirect('/rdv/a/'.$appt->token);
        $this->assertSame('08:30', $appt->fresh()->starts_at->timezone('Africa/Abidjan')->format('H:i'));
        $this->post('/rdv/a/'.$appt->token.'/annuler', ['reason' => 'Empêchement'])->assertRedirect();
        $this->assertSame('cancelled', $appt->fresh()->status);
        $this->assertStringContainsString('Empêchement', $appt->fresh()->cancel_reason);

        // Rappels : seulement pour les rendez-vous pris à l'avance, une seule fois
        app()->instance('tenant', $company->fresh());
        // Bintou : n'encombre pas l'agenda d'Aïcha (rendez-vous du lundi matin plus bas), quelle que soit l'heure du test
        $soon = Appointment::create(['staff_id' => $b['id'], 'service_id' => $cut['id'], 'customer_name' => 'Rappel', 'customer_phone' => '0707070707', 'starts_at' => now()->addHours(20)->toDateTimeString(), 'ends_at' => now()->addHours(20)->addMinutes(30)->toDateTimeString(), 'notify' => false]);
        $soon->forceFill(['created_at' => now()->subDays(3), 'notify' => true])->saveQuietly();
        $svc = app(BookingService::class);
        $this->assertSame(1, $svc->reminders($company->fresh()));
        $this->assertSame(0, $svc->reminders($company->fresh()));

        // Facturation : prix TTC de la prestation
        $yao = Appointment::withoutGlobalScopes()->where('customer_name', 'M. Yao')->first();
        $api()->putJson("/api/rendez_vous/appointments/{$yao->id}", ['status' => 'done'])->assertOk();
        $inv = $api()->postJson("/api/rendez_vous/appointments/{$yao->id}/invoice")->assertOk()->json('invoice');
        $this->assertSame(5900.0, (float) $inv['total']);
        $api()->postJson("/api/rendez_vous/appointments/{$yao->id}/invoice")->assertStatus(422);
        $this->assertSame(1, SalesDocument::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $stats = $api()->getJson('/api/rendez_vous/stats?from='.now()->toDateString().'&to='.Carbon::parse($monday)->addDay()->toDateString())->assertOk()->json();
        $this->assertSame(1, $stats['done']);

        // Portail : le client voit ses rendez-vous et réserve depuis son espace
        $url = $api()->postJson("/api/portail/partners/{$appt->partner_id}/invite", ['channel' => 'none'])->json('url');
        $ptoken = basename(parse_url($url, PHP_URL_PATH));
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get(parse_url($url, PHP_URL_PATH).'/rendez-vous')->assertOk()->assertSee('Coupe &amp; brushing', false)->assertSee('Prendre rendez-vous');
        $this->get("/rdv/$slug/confirmer?service={$cut['id']}&start=".urlencode($nine)."&portal=$ptoken")->assertOk()->assertSee('Mme Koffi Adjoua');
        $this->post("/rdv/$slug", ['service_id' => $cut['id'], 'starts_at' => Carbon::parse("$monday 08:00", 'Africa/Abidjan')->toIso8601String(), 'portal' => $ptoken, 'name' => 'Mme Koffi Adjoua', 'phone' => '0701020304'])->assertSessionMissing('error');
        $this->assertTrue(Appointment::withoutGlobalScopes()->where('partner_id', $appt->partner_id)->where('source', 'portal')->exists());
        $this->get('/rdv/inconnu')->assertNotFound();
    }
}
