<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Agenda\Recurrence;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    public function test_recurrence_rules(): void
    {
        $mon = Carbon::parse('2026-11-02 09:00:00'); // lundi
        $occ = Recurrence::occurrences($mon, $mon->copy()->addHour(), ['freq' => 'weekly', 'byday' => [1, 3], 'count' => 4], [], $mon->copy()->subDay(), $mon->copy()->addMonths(2));
        $this->assertSame(['2026-11-02', '2026-11-04', '2026-11-09', '2026-11-11'], array_map(fn ($o) => $o[0]->toDateString(), $occ));
        $d31 = Carbon::parse('2026-01-31 08:00:00');
        $occ = Recurrence::occurrences($d31, $d31->copy()->addHour(), ['freq' => 'monthly', 'until' => '2026-06-30'], ['2026-05-31'], $d31, Carbon::parse('2027-01-01'));
        $this->assertSame(['2026-01-31', '2026-03-31'], array_map(fn ($o) => $o[0]->toDateString(), $occ));
        $occ = Recurrence::occurrences($mon, $mon->copy()->addHour(), ['freq' => 'daily', 'interval' => 2], [], Carbon::parse('2026-11-10'), Carbon::parse('2026-11-15'));
        $this->assertSame(['2026-11-10', '2026-11-12', '2026-11-14'], array_map(fn ($o) => $o[0]->toDateString(), $occ));
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4', Recurrence::rrule(['freq' => 'weekly', 'byday' => [1, 3], 'count' => 4]));
    }

    public function test_events_invitations_responses_resources_private_overlay_feed_and_reminders(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Agence Lumière');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $api()->putJson('/api/messaging/channels/whatsapp', ['provider' => 'meta', 'settings' => ['phone_number_id' => '77', 'access_token' => 'tok']])->assertOk();
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $roleName) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@lumiere.ci', 'password' => 'secret123', 'role_id' => $role($roleName)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        [$jean, $jeanApi] = $mk('Jean', 'Commercial');
        [$paul, $paulApi] = $mk('Paul', 'Comptable');
        [$fatou, $fatouApi] = $mk('Fatou', 'Direction');
        [$awa, $awaApi] = $mk('Awa', 'Caissier');
        $push = fn ($client, array $mut) => $client()->postJson('/api/sync/push', ['mutations' => [$mut + ['id' => $this->uuid(), 'op' => 'upsert']]])->assertOk()->json('results.0');
        $pull = fn ($client) => collect($client()->getJson('/api/sync/pull?cursor=0')->assertOk()->json('changes.ag_events.upserts') ?? [])->keyBy('id');
        $notif = fn (User $u, string $title) => AppNotification::withoutGlobalScopes()->where('user_id', $u->id)->where('title', $title)->count();
        $at = fn (int $days, string $time) => today()->addDays($days)->format('Y-m-d').' '.$time.':00';
        $nextMonday = today()->next(Carbon::MONDAY);

        // Ressources : réservées à la direction / l'administrateur
        $salle = $this->uuid();
        $this->assertSame('rejected', $push($jeanApi, ['entity' => 'ag_resources', 'record_id' => $salle, 'data' => ['name' => 'Salle de réunion']])['status']);
        $push($api, ['entity' => 'ag_resources', 'record_id' => $salle, 'data' => ['name' => 'Salle de réunion', 'kind' => 'room', 'capacity' => 8]]);

        // Réunion avec invitations, salle et rappel
        $meet = $this->uuid();
        $r = $push($jeanApi, ['entity' => 'ag_events', 'record_id' => $meet, 'data' => ['title' => 'Revue des devis', 'starts_at' => $at(1, '10:00'), 'ends_at' => $at(1, '11:00'), 'category' => 'meeting',
            'attendee_ids' => [$paul->id, $fatou->id, $jean->id], 'resource_id' => $salle, 'reminder_minutes' => 30]]);
        $this->assertSame(['applied', $jean->id, [$paul->id, $fatou->id]], [$r['status'], $r['record']['owner_id'], $r['record']['attendee_ids']]);
        $this->assertSame([1, 1, 0], [$notif($paul, 'Invitation : Revue des devis'), $notif($fatou, 'Invitation : Revue des devis'), $notif($jean, 'Invitation : Revue des devis')]);

        // Réunion hebdomadaire dans la même salle
        $weekly = $this->uuid();
        $push($jeanApi, ['entity' => 'ag_events', 'record_id' => $weekly, 'data' => ['title' => 'Point commercial', 'starts_at' => $nextMonday->format('Y-m-d').' 09:00:00', 'ends_at' => $nextMonday->format('Y-m-d').' 10:00:00',
            'recurrence' => ['freq' => 'weekly', 'byday' => [1]], 'resource_id' => $salle, 'attendee_ids' => [$paul->id]]]);

        // Pas de double réservation (y compris sur une occurrence future de la réunion hebdomadaire)
        $c = $push($paulApi, ['entity' => 'ag_events', 'record_id' => $this->uuid(), 'data' => ['title' => 'Clôture', 'starts_at' => $at(1, '10:30'), 'ends_at' => $at(1, '12:00'), 'resource_id' => $salle]]);
        $this->assertSame('rejected', $c['status']);
        $this->assertStringContainsString('« Salle de réunion » est déjà réservé(e)', $c['message']);
        $in3 = $nextMonday->copy()->addWeeks(3)->format('Y-m-d');
        $this->assertSame('rejected', $push($paulApi, ['entity' => 'ag_events', 'record_id' => $this->uuid(), 'data' => ['title' => 'Formation', 'starts_at' => "$in3 09:30:00", 'ends_at' => "$in3 11:00:00", 'resource_id' => $salle]])['status']);
        $push($jeanApi, ['entity' => 'ag_events', 'record_id' => $weekly, 'data' => ['exceptions' => [$in3]]]);
        $this->assertSame('applied', $push($paulApi, ['entity' => 'ag_events', 'record_id' => $this->uuid(), 'data' => ['title' => 'Formation', 'starts_at' => "$in3 09:30:00", 'ends_at' => "$in3 11:00:00", 'resource_id' => $salle]])['status']);
        $this->assertSame('rejected', $push($paulApi, ['entity' => 'ag_events', 'record_id' => $this->uuid(), 'data' => ['title' => 'X', 'starts_at' => $at(2, '10:00'), 'ends_at' => $at(2, '09:00')]])['status']);

        // Réponses des invités : seul l'organisateur modifie, l'invité ne fait que répondre
        $r = $push($paulApi, ['entity' => 'ag_events', 'record_id' => $meet, 'data' => ['title' => 'Piraté', 'responses' => [(string) $paul->id => 'accepted']]]);
        $this->assertSame(['applied', 'Revue des devis', 'accepted'], [$r['status'], $r['record']['title'], $r['record']['responses'][(string) $paul->id]]);
        $this->assertSame(1, $notif($jean, 'Paul accepte « Revue des devis »'));
        $push($fatouApi, ['entity' => 'ag_events', 'record_id' => $meet, 'data' => ['responses' => [(string) $fatou->id => 'declined']]]);
        $this->assertSame('rejected', $push($awaApi, ['entity' => 'ag_events', 'record_id' => $meet, 'data' => ['title' => 'X']])['status']);
        $this->assertSame('rejected', $awaApi()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'ag_events', 'op' => 'delete', 'record_id' => $meet]]])->json('results.0.status'));

        // Déplacement : les invités sont prévenus (sans nouvelle invitation)
        $push($jeanApi, ['entity' => 'ag_events', 'record_id' => $meet, 'data' => ['starts_at' => $at(1, '11:00'), 'ends_at' => $at(1, '12:00')]]);
        $this->assertSame([1, 1], [$notif($paul, 'Modifié : Revue des devis'), $notif($paul, 'Invitation : Revue des devis')]);

        // Événement privé : « Occupé » pour les collègues
        $priv = $this->uuid();
        $push($fatouApi, ['entity' => 'ag_events', 'record_id' => $priv, 'data' => ['title' => 'Rendez-vous médical', 'location' => 'Clinique', 'starts_at' => $at(2, '15:00'), 'ends_at' => $at(2, '16:00'), 'visibility' => 'private', 'category' => 'personal']]);
        $this->assertSame(['Occupé', null, true], [$pull($awaApi)[$priv]['title'], $pull($awaApi)[$priv]['location'], $pull($awaApi)[$priv]['masked']]);
        $this->assertSame('Rendez-vous médical', $pull($fatouApi)[$priv]['title']);

        // Invités externes : invitation par WhatsApp
        $ext = $this->uuid();
        $push($jeanApi, ['entity' => 'ag_events', 'record_id' => $ext, 'data' => ['title' => 'Présentation au client', 'starts_at' => $at(3, '15:00'), 'ends_at' => $at(3, '16:00'), 'category' => 'appointment',
            'location' => 'Hôtel Ivoire', 'guests' => [['name' => 'M. Diallo', 'phone' => '+225 07 08 09 10 11']], 'notify_guests' => true]]);
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'vous invite : « Présentation au client »'));

        // Annulation
        $push($jeanApi, ['entity' => 'ag_events', 'record_id' => $ext, 'data' => ['status' => 'cancelled']]);

        // Agenda des autres modules : congé approuvé visible par la RH et par le salarié concerné
        $emp = new \App\Modules\Rh\Models\Employee(['first_name' => 'Awa', 'last_name' => 'Traoré', 'hire_date' => '2024-01-01', 'base_salary' => 90000]);
        $emp->id = $this->uuid();
        $emp->company_id = $company->id;
        $emp->user_id = $awa->id;
        $emp->matricule = 'EMP09001';
        $emp->saveQuietly();
        $leave = new \App\Modules\Rh\Models\Leave(['employee_id' => $emp->id, 'type' => 'paid', 'start_date' => today()->addDays(5)->toDateString(), 'end_date' => today()->addDays(9)->toDateString(), 'days' => 5, 'status' => 'approved']);
        $leave->id = $this->uuid();
        $leave->company_id = $company->id;
        $leave->saveQuietly();
        $range = '?from='.today()->toDateString().'&to='.today()->addDays(30)->toDateString();
        $ov = collect($api()->getJson('/api/agenda/overlay'.$range)->assertOk()->json('data'))->where('kind', 'leave');
        $this->assertCount(1, $ov);
        $this->assertStringContainsString('Awa Traoré', $ov->first()['title']);
        $this->assertCount(1, collect($awaApi()->getJson('/api/agenda/overlay'.$range)->json('data'))->where('kind', 'leave'));
        $this->assertCount(0, collect($jeanApi()->getJson('/api/agenda/overlay'.$range)->json('data'))->where('kind', 'leave'));

        // Abonnement Google / Outlook : événements de l'utilisateur, récurrence, rappel ; ni le privé d'un collègue ni les refus
        $url = $paulApi()->getJson('/api/agenda/feed')->assertOk()->json('data.url');
        $this->assertSame($url, $paulApi()->getJson('/api/agenda/feed')->json('data.url'));
        $this->flushHeaders();
        $ics = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringContainsString('SUMMARY:Point commercial', $ics);
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=MO', $ics);
        $this->assertStringContainsString('EXDATE:', $ics);
        $this->assertStringContainsString('SUMMARY:Revue des devis', $ics);
        $this->assertStringContainsString('TRIGGER:-PT30M', $ics);
        $this->assertStringNotContainsString('médical', $ics);
        $this->assertStringNotContainsString('Présentation au client', $ics);
        $fatouIcs = $this->get(parse_url($fatouApi()->getJson('/api/agenda/feed')->json('data.url'), PHP_URL_PATH))->getContent();
        $this->assertStringNotContainsString('Revue des devis', $fatouIcs); // a décliné
        $this->assertStringContainsString('Rendez-vous médical', $fatouIcs);
        $renewed = $paulApi()->postJson('/api/agenda/feed', ['renew' => true])->json('data.url');
        $this->assertNotSame($url, $renewed);
        $this->flushHeaders();
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();

        // Rappels : avant la réunion, pour l'organisateur et les participants qui n'ont pas décliné, une seule fois
        $soon = $this->uuid();
        $start = now()->addMinutes(20)->startOfMinute();
        $push($jeanApi, ['entity' => 'ag_events', 'record_id' => $soon, 'data' => ['title' => 'Appel fournisseur', 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            'attendee_ids' => [$paul->id, $fatou->id], 'responses' => [], 'reminder_minutes' => 30]]);
        $push($fatouApi, ['entity' => 'ag_events', 'record_id' => $soon, 'data' => ['responses' => [(string) $fatou->id => 'declined']]]);
        $this->artisan('erp:agenda-reminders')->assertSuccessful();
        $this->artisan('erp:agenda-reminders')->assertSuccessful();
        $this->assertSame([1, 1, 0], [$notif($jean, 'Rappel : Appel fournisseur'), $notif($paul, 'Rappel : Appel fournisseur'), $notif($fatou, 'Rappel : Appel fournisseur')]);

        // Ressource encore réservée : suppression refusée
        $this->assertSame('rejected', $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'ag_resources', 'op' => 'delete', 'record_id' => $salle]]])->json('results.0.status'));
        $this->assertContains($jean->id, array_column($jeanApi()->getJson('/api/agenda/users')->json('data'), 'id'));
    }
}
