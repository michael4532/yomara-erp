<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Modules\Contenus\SocialPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Intégrations des espaces de service : réseaux sociaux (Meta, LinkedIn), GitHub / GitLab, canaux d'équipe. */
class IntegrationsServicesTest extends TestCase
{
    public function test_social_networks_publish_and_stats(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Social SARL');
        $api = fn () => $this->api($token);
        $g = SocialPublisher::GRAPH;
        Http::fake([
            $g.'/1122?*' => Http::response(['id' => '1122', 'name' => 'Démo Page', 'instagram_business_account' => ['id' => '1784', 'username' => 'demo_ci']]),
            $g.'/1122/photos' => Http::response(['id' => '555', 'post_id' => '1122_555']),
            $g.'/1784/media' => Http::response(['id' => 'cont1']),
            $g.'/1784/media_publish' => Http::response(['id' => 'ig900']),
            $g.'/ig900?*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
            $g.'/1122_555?*' => Http::response(['insights' => ['data' => [['values' => [['value' => 1200]]]]], 'reactions' => ['summary' => ['total_count' => 40]], 'comments' => ['summary' => ['total_count' => 5]], 'shares' => ['count' => 3]]),
            $g.'/ig900/insights*' => Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 800]]], ['name' => 'total_interactions', 'values' => [['value' => 60]]]]]),
            'api.linkedin.com/rest/organizations/*' => Http::response(['localizedName' => 'Démo SARL', 'vanityName' => 'demo-sarl']),
            'api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:7001']),
            'api.linkedin.com/rest/socialActions/*' => Http::response(['likesSummary' => ['totalLikes' => 12], 'commentsSummary' => ['aggregatedTotalComments' => 2]]),
        ]);
        $acc = $api()->postJson('/api/contenus/social/connect/meta', ['token' => 'EAAG-page', 'account_id' => '1122'])->assertOk()->json('data');
        $this->assertSame(['facebook', 'instagram'], array_column($acc, 'platform'));
        $api()->postJson('/api/contenus/social/connect/linkedin', ['token' => 'AQV-li', 'account_id' => 'urn:li:organization:778899'])->assertOk()->assertJsonPath('data.0.name', 'Démo SARL');
        $this->assertCount(3, $api()->getJson('/api/contenus/social/accounts')->json('data'));
        $this->assertStringNotContainsString('EAAG', (string) DB::table('mc_social_accounts')->value('credentials'));

        $post = $api()->postJson('/api/contenus/records/posts', ['title' => 'Offre de lancement', 'networks' => ['facebook', 'instagram', 'linkedin', 'tiktok'], 'caption' => '-15 % sur les installations',
            'hashtags' => '#Abidjan', 'visual_url' => 'https://cdn.demo.ci/offre.jpg', 'link_url' => 'https://demo.ci/offre', 'scheduled_at' => now()->subMinute()->toDateTimeString(),
            'status' => 'approved', 'auto_publish' => true])->assertCreated()->json('data');
        $this->assertSame(1, app(SocialPublisher::class)->due($company));
        $p = $api()->getJson('/api/contenus/records/posts/'.$post['id'])->json('data');
        $this->assertSame('published', $p['status']);
        $this->assertSame('https://www.facebook.com/1122_555', $p['published_url']);
        $this->assertSame('Facebook ✓ · Instagram ✓ · LinkedIn ✓', $p['social_status']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1122/photos') && str_contains($r['caption'], "-15 % sur les installations\n\n#Abidjan") && $r['url'] === 'https://cdn.demo.ci/offre.jpg');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/posts') && $r['author'] === 'urn:li:organization:778899' && $r['content']['article']['source'] === 'https://demo.ci/offre' && $r->hasHeader('LinkedIn-Version'));
        $this->assertSame(0, app(SocialPublisher::class)->due($company)); // pas deux fois

        $api()->postJson('/api/contenus/social/stats')->assertOk()->assertJsonPath('data.updated', 1);
        $p = $api()->getJson('/api/contenus/records/posts/'.$post['id'])->json('data');
        $this->assertEquals([2000, 48 + 60 + 14], [$p['reach'], $p['engagements']]);

        // Instagram sans visuel : erreur notée, le responsable est prévenu ; les autres réseaux publient
        [$mk, $mapi] = (function () use ($company) {
            $u = \App\Models\User::create(['company_id' => $company->id, 'name' => 'Mk', 'email' => 'mk@soc.ci', 'password' => 'secret123',
                'role_id' => \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Marketing & communication')->value('id')]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        })();
        $p2 = $api()->postJson('/api/contenus/records/posts', ['title' => 'Texte seul', 'networks' => ['instagram', 'linkedin'], 'caption' => 'Bonjour', 'status' => 'review', 'owner_id' => $mk->id])->json('data');
        $mapi()->postJson('/api/contenus/posts/'.$p2['id'].'/publish')->assertStatus(422);
        $res = $api()->postJson('/api/contenus/posts/'.$p2['id'].'/publish')->assertOk()->json('data');
        $this->assertStringContainsString('visuel', $res['instagram']['error']);
        $this->assertNotEmpty($res['linkedin']['id']);
        $this->assertSame('error', DB::table('mc_social_accounts')->where('platform', 'instagram')->value('status'));
        $this->assertTrue(AppNotification::where('title', 'Publication non publiée sur Instagram')->exists());
    }

    public function test_github_and_gitlab_move_dev_items(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Git SARL');
        $api = fn () => $this->api($token);
        $appId = $api()->postJson('/api/dev/records/apps', ['title' => 'Boutique'])->assertCreated()->json('data.id');
        $app = DB::table('dev_apps')->find($appId);
        $this->assertStringContainsString('/api/dev-git/'.$app->git_token, $api()->getJson('/api/dev/records/apps/'.$appId)->json('data.webhook'));
        $this->assertArrayNotHasKey('git_secret', $api()->getJson('/api/dev/records/apps/'.$appId)->json('data'));
        $rel = $api()->postJson('/api/dev/records/releases', ['title' => '1.4.0', 'app_id' => $appId])->json('data.id');
        $item = $api()->postJson('/api/dev/records/items', ['title' => 'Carte de suivi', 'app_id' => $appId, 'status' => 'todo', 'release_id' => $rel, 'assignee_id' => $admin->id])->json('data');
        $this->assertSame('#0001', $item['number']);
        $gh = function (string $event, array $payload, ?string $secret = null) use ($app) {
            $body = json_encode($payload);

            return $this->call('POST', '/api/dev-git/'.$app->git_token, [], [], [], [
                'HTTP_X_GITHUB_EVENT' => $event, 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? $app->git_secret), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
        };
        $gh('push', ['ref' => 'refs/heads/main', 'commits' => []], 'mauvais')->assertStatus(401);
        $gh('ping', ['zen' => 'ok'])->assertOk();
        $gh('push', ['ref' => 'refs/heads/feature/0001-carte', 'commits' => [['message' => "Ajout de la carte\n\ndétails", 'url' => 'https://github.com/x/c/1', 'author' => ['name' => 'Kader']]]])->assertOk()->assertJsonPath('items', 1);
        $this->assertSame(['in_progress', 'feature/0001-carte'], [DB::table('dev_items')->where('id', $item['id'])->value('status'), DB::table('dev_items')->where('id', $item['id'])->value('branch')]);
        $pr = ['title' => 'Carte de suivi (#1)', 'html_url' => 'https://github.com/x/pull/7', 'head' => ['ref' => 'feature/0001-carte'], 'user' => ['login' => 'kader'], 'merged' => false];
        $gh('pull_request', ['action' => 'opened', 'pull_request' => $pr])->assertOk();
        $this->assertSame(['review', 'https://github.com/x/pull/7'], [DB::table('dev_items')->where('id', $item['id'])->value('status'), DB::table('dev_items')->where('id', $item['id'])->value('pr_url')]);
        $gh('pull_request', ['action' => 'closed', 'pull_request' => ['merged' => true] + $pr])->assertOk();
        $this->assertSame('testing', DB::table('dev_items')->where('id', $item['id'])->value('status'));
        $this->assertStringContainsString('Fusionnée : Carte de suivi', $api()->getJson('/api/dev/records/items/'.$item['id'])->json('data.git_activity'));
        $gh('push', ['ref' => 'refs/tags/v1.4.0', 'commits' => []])->assertOk();
        $this->assertSame('released', DB::table('dev_releases')->where('id', $rel)->value('status'));
        $this->assertSame('done', DB::table('dev_items')->where('id', $item['id'])->value('status'));

        // GitLab : merge request ouverte puis fermée sans fusion
        $i2 = $api()->postJson('/api/dev/records/items', ['title' => 'Export', 'app_id' => $appId, 'status' => 'in_progress'])->json('data');
        $gl = fn (array $p, string $ev = 'Merge Request Hook') => $this->call('POST', '/api/dev-git/'.$app->git_token, [], [], [], ['HTTP_X_GITLAB_EVENT' => $ev, 'HTTP_X_GITLAB_TOKEN' => $app->git_secret, 'CONTENT_TYPE' => 'application/json'], json_encode($p));
        $gl(['object_attributes' => ['action' => 'open', 'title' => 'Export CSV', 'source_branch' => '2-export', 'url' => 'https://gitlab.com/x/-/merge_requests/3'], 'user' => ['name' => 'Inès']])->assertOk();
        $this->assertSame('review', DB::table('dev_items')->where('id', $i2['id'])->value('status'));
        $gl(['object_attributes' => ['action' => 'close', 'title' => 'Export CSV', 'source_branch' => '2-export', 'url' => 'https://gitlab.com/x/-/merge_requests/3']])->assertOk();
        $this->assertSame('in_progress', DB::table('dev_items')->where('id', $i2['id'])->value('status'));
        $this->assertSame(['#0001', '#0002'], DB::table('dev_items')->orderBy('id')->pluck('number')->all());
    }

    public function test_team_channels_receive_notifications(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Canal SARL');
        $api = fn () => $this->api($token);
        Http::fake(['hooks.slack.com/*' => Http::response('ok'), 'api.telegram.org/*' => Http::response(['ok' => true]), 'discord.com/*' => Http::response('', 204)]);
        $api()->postJson('/api/integrations/channels', ['kind' => 'slack', 'name' => 'Parc', 'url' => 'https://evil.example.com/x', 'events' => ['flotte']])->assertStatus(422);
        $slack = $api()->postJson('/api/integrations/channels', ['kind' => 'slack', 'name' => '#parc-auto', 'url' => 'https://hooks.slack.com/services/T0/B0/xyz', 'events' => ['flotte']])->assertCreated()->json('data');
        $this->assertStringNotContainsString('xyz', $slack['target']);
        $tg = $api()->postJson('/api/integrations/channels', ['kind' => 'telegram', 'name' => 'Direction', 'token' => '123:ABC', 'chat_id' => '-100555', 'events' => ['*']])->assertCreated()->json('data');
        $api()->postJson('/api/integrations/channels/'.$tg['id'].'/test')->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org/bot123:ABC/sendMessage') && $r['chat_id'] === '-100555');
        $this->assertCount(2, $api()->getJson('/api/integrations')->json('data.channels'));

        // une demande de véhicule notifie le parc : relayée dans Slack (rubrique) et Telegram (tout)
        $api()->postJson('/api/flotte/records/trips', ['title' => 'Visite client Cocody', 'planned_start' => now()->addDay()->toDateTimeString(), 'planned_end' => now()->addDay()->addHours(3)->toDateTimeString()])->assertCreated();
        AppNotification::send($company->id, [$admin->id], 'flotte', 'Demande de véhicule', 'Koffi : visite client', 'flotte');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'hooks.slack.com') && str_contains($r['text'], '*Demande de véhicule*'));
        $this->assertSame(2, (int) DB::table('notify_channels')->where('id', $slack['id'])->value('sent_count'));
        AppNotification::send($company->id, [$admin->id], 'juridique', 'Contrat échu', null, 'juridique');
        $this->assertSame(2, (int) DB::table('notify_channels')->where('id', $slack['id'])->value('sent_count'));
        $this->assertSame(4, (int) DB::table('notify_channels')->where('id', $tg['id'])->value('sent_count'));
        $api()->putJson('/api/integrations/channels/'.$slack['id'], ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $api()->deleteJson('/api/integrations/channels/'.$tg['id'])->assertNoContent();
    }
}
