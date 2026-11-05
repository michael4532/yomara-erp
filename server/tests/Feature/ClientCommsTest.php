<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\PlatformDelivery;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientCommsTest extends TestCase
{
    protected string $admin;

    protected string $client;

    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.mode' => 'saas']);
        [, , $this->admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [$c, , $this->client] = $this->makeCompany('Boutique Awa', 'starter');
        $this->companyId = $c->id;
    }

    protected function campaign(array $over = []): array
    {
        return $over + ['name' => 'Promo', 'channels' => ['notification', 'email'], 'audience' => ['segment' => 'all', 'recipients' => 'admins'], 'format' => 'text',
            'subject' => '−20 % pour {{entreprise}}', 'title' => 'Promo', 'body_text' => 'Bonjour {{prenom}}, code PROMO20.', 'cta_label' => 'Voir', 'cta_url' => '{{lien_boutique}}', 'schedule' => 'now'];
    }

    public function test_campaign_reaches_clients_only_and_tracks(): void
    {
        Mail::fake();
        $this->api($this->client)->getJson('/api/admin/comms')->assertForbidden();

        $id = $this->api($this->admin)->postJson('/api/admin/comms/campaigns', $this->campaign())->assertCreated()->json('data.id');
        $this->api($this->admin)->postJson('/api/admin/comms/campaigns/'.$id.'/launch')->assertOk()->assertJsonPath('result.companies', 1);

        // La société de l'éditeur n'est pas destinataire ; la cliente reçoit la notification personnalisée
        $n = AppNotification::where('company_id', $this->companyId)->where('type', 'announcement')->first();
        $this->assertNotNull($n);
        $this->assertStringNotContainsString('{{', $n->body);
        $this->api($this->client)->getJson('/api/announcements/'.$n->ref)->assertOk()->assertJsonPath('title', 'Promo');

        $d = PlatformDelivery::where('channel', 'email')->first();
        $this->assertSame('sent', $d->status);
        $this->get('/c/o/'.$d->token.'.gif')->assertOk();
        $this->assertNotNull($d->fresh()->opened_at);

        // Clic : seul un lien signé redirige (pas de redirection ouverte)
        $evil = rtrim(strtr(base64_encode('https://evil.example'), '+/', '-_'), '=');
        $this->get('/c/c/'.$d->token.'?u='.$evil.'&s=000000000000000000000000')->assertNotFound();

        $this->post('/c/u/'.$d->token)->assertOk();
        $this->assertDatabaseHas('platform_unsubscribes', ['email' => $d->email]);

        // Une autre société ne lit pas l'annonce
        [, , $other] = $this->makeCompany('Autre', 'starter');
        $this->api($other)->getJson('/api/announcements/'.$n->ref)->assertNotFound();
    }

    public function test_scheduled_recurring_and_automation_run_once(): void
    {
        Mail::fake();
        $id = $this->api($this->admin)->postJson('/api/admin/comms/campaigns', $this->campaign(['schedule' => 'recurring', 'recurrence' => ['freq' => 'daily', 'time' => '08:00'], 'channels' => ['notification']]))->assertCreated()->json('data.id');
        $this->api($this->admin)->postJson('/api/admin/comms/campaigns/'.$id.'/launch')->assertOk()->assertJsonPath('data.status', 'scheduled');
        \App\Models\PlatformCampaign::whereKey($id)->update(['next_run_at' => now()->subMinute()]);

        Company::whereKey($this->companyId)->update(['status' => 'trial', 'trial_ends_at' => now()->addDays(2)]);
        $auto = collect($this->api($this->admin)->getJson('/api/admin/comms')->json('automations'))->firstWhere('trigger', 'trial_ending');
        $this->api($this->admin)->getJson('/api/admin/comms/automations/'.$auto['id'].'/targets')->assertJsonPath('count', 1);
        $this->api($this->admin)->postJson('/api/admin/comms/automations/'.$auto['id'].'/toggle', ['is_active' => true])->assertOk();

        $this->artisan('erp:client-comms')->assertSuccessful();
        \App\Models\PlatformSetting::put('comms.automations_at', now()->subHours(2)->toIso8601String());
        $this->artisan('erp:client-comms')->assertSuccessful();

        $c = \App\Models\PlatformCampaign::find($id);
        $this->assertSame(1, $c->runs);
        $this->assertTrue($c->next_run_at->isFuture());
        $this->assertSame(1, PlatformDelivery::where('automation_id', $auto['id'])->where('channel', 'notification')->count()); // une seule fois
    }

    public function test_validation_rejects_bad_links_and_past_dates(): void
    {
        $this->api($this->admin)->postJson('/api/admin/comms/campaigns', $this->campaign(['cta_url' => 'javascript:alert(1)']))->assertUnprocessable();
        $id = $this->api($this->admin)->postJson('/api/admin/comms/campaigns', $this->campaign(['schedule' => 'once', 'send_at' => now()->subDay()->toDateTimeString()]))->assertCreated()->json('data.id');
        $this->api($this->admin)->postJson('/api/admin/comms/campaigns/'.$id.'/launch')->assertStatus(422);
    }
}
