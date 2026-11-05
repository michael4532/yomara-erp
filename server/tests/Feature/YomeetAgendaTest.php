<?php

namespace Tests\Feature;

use App\Models\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class YomeetAgendaTest extends TestCase
{
    public function test_yomeet_event_minutes_quota_packs_and_movable_agenda_items(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Yomeet SARL');
        $api = fn () => $this->api($token);
        $push = fn (array $m) => $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert'] + $m]])->assertOk()->json('results.0');

        // Événement Yomeet : salle intégrée créée, lien + identifiant + code d'accès
        $eid = $this->uuid();
        $r = $push(['entity' => 'ag_events', 'record_id' => $eid, 'data' => ['title' => 'Point commercial', 'starts_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
            'ends_at' => now()->addDay()->setTime(11, 0)->toDateTimeString(), 'guests' => [['name' => 'Client Kouassi', 'email' => 'k@client.ci']],
            'yomeet' => ['enabled' => true, 'lobby' => true, 'mute_on_entry' => true, 'password' => '482910']]]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $ev = DB::table('ag_events')->where('id', $eid)->first();
        $y = json_decode($ev->yomeet, true);
        $m = DB::table('vis_meetings')->where('id', $y['meeting_id'])->first();
        $this->assertSame([$eid, '482910', 'scheduled', 60], [$m->agenda_event_id, $m->password, $m->status, (int) $m->duration_minutes]);
        $this->assertStringContainsString('/visio/'.$m->code, $ev->online_link);
        $this->assertTrue(json_decode($m->options, true)['lobby']);
        $this->assertSame(1, DB::table('vis_participants')->where('meeting_id', $m->id)->whereNull('user_id')->where('email', 'k@client.ci')->count());
        // Déplacé d'une heure : la salle suit, l'identifiant reste
        $push(['entity' => 'ag_events', 'record_id' => $eid, 'data' => ['starts_at' => now()->addDay()->setTime(11, 0)->toDateTimeString(), 'ends_at' => now()->addDay()->setTime(12, 30)->toDateTimeString(), 'yomeet' => ['enabled' => true, 'lobby' => false]]]);
        $m2 = DB::table('vis_meetings')->where('id', $y['meeting_id'])->first();
        $this->assertSame([$m->code, 90, '482910'], [$m2->code, (int) $m2->duration_minutes, $m2->password]);
        $this->assertSame(1, DB::table('vis_meetings')->where('agenda_event_id', $eid)->count());
        // Supprimé : la salle est annulée
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'delete', 'entity' => 'ag_events', 'record_id' => $eid]]])->assertOk();
        $this->assertSame('cancelled', DB::table('vis_meetings')->where('id', $y['meeting_id'])->value('status'));

        // Minutes : incluses chaque mois, puis forfait acheté ; comptes de la plateforme non modifiables par la société
        config(['erp.visio.zego_app_id' => '123456', 'erp.visio.zego_secret' => str_repeat('a', 32)]);
        $q = $api()->getJson('/api/visio')->assertOk()->json('quota');
        $this->assertSame([300, 0, 300, false], [$q['included'], $q['purchased'], $q['remaining'], $q['exhausted']]);
        DB::table('vis_usage')->insert(['company_id' => $company->id, 'provider' => 'zego', 'month' => now()->format('Y-m'), 'seconds' => 300 * 60, 'switches' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue($api()->getJson('/api/visio/settings')->json('quota.exhausted'));
        $api()->putJson('/api/visio/settings', ['zego_app_id' => '999', 'defaults' => ['lobby' => true]])->assertOk();
        $this->assertNull(DB::table('vis_settings')->where('company_id', $company->id)->value('zego_app_id'));
        $this->assertSame(['m1000', 'm5000', 'm15000', 'm50000'], array_column($api()->getJson('/api/store')->json('minute_packs'), 'code'));

        [, , $superToken] = $this->makeCompany('Plateforme', 'entreprise', superAdmin: true);
        $bank = $this->api($superToken)->postJson('/api/admin/payment-gateways', ['driver' => 'bank_transfer', 'label' => 'Virement'])->assertCreated()->json('id');
        $order = $api()->postJson('/api/store/orders', ['module' => 'minutes:m1000', 'billing' => 'monthly', 'gateway_id' => $bank])->assertCreated()->json('order');
        $this->assertSame(['Yomeet — 1 000 minutes', 5000.0], [$order['module_name'], (float) $order['amount']]);
        $this->api($superToken)->postJson('/api/admin/store/orders/'.$order['id'].'/approve')->assertOk();
        $q = $api()->getJson('/api/visio')->json('quota');
        $this->assertSame([1300, 1000, false], [$q['allowance'], $q['remaining'], $q['exhausted']]);
        // Tarifs réglés par le super admin
        $this->api($superToken)->putJson('/api/admin/store/settings', ['visio' => ['included_minutes' => 600, 'packs' => [['code' => 'pro', 'minutes' => 3000, 'price' => 12000, 'label' => 'Pro']]]])->assertOk();
        $this->assertSame([600, 'pro'], [$api()->getJson('/api/visio')->json('quota.included'), $api()->getJson('/api/store')->json('minute_packs.0.code')]);

        // Agenda : échéance de tâche déplacée depuis le calendrier ; congés non déplaçables
        $pid = $this->uuid();
        $tid = $this->uuid();
        $push(['entity' => 'prj_projects', 'record_id' => $pid, 'data' => ['name' => 'Site web']]);
        $this->assertSame('applied', $push(['entity' => 'prj_tasks', 'record_id' => $tid, 'data' => ['project_id' => $pid, 'title' => 'Maquette', 'due_date' => now()->addDays(2)->toDateString()]])['status']);
        $api()->putJson("/api/agenda/overlay/task/$tid", ['starts_at' => now()->addDays(5)->toDateString().' 00:00:00'])->assertOk();
        $this->assertSame(now()->addDays(5)->toDateString(), substr(DB::table('prj_tasks')->where('id', $tid)->value('due_date'), 0, 10));
        $api()->putJson("/api/agenda/overlay/leave/$tid", ['starts_at' => now()->toDateString()])->assertNotFound();
    }
}
