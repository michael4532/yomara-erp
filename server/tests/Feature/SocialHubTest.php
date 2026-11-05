<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Social\Networks;
use App\Modules\Social\TextAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialHubTest extends TestCase
{
    protected function fakeNetworks(int $mastodonStatus = 200): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'yomara_bot']]),
            'api.telegram.org/*/getChat*' => Http::response(['ok' => true, 'result' => ['id' => -100123, 'title' => 'Canal Boutique', 'username' => 'boutique', 'type' => 'channel']]),
            'api.telegram.org/*/send*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
            'mastodon.example/api/v1/accounts/verify_credentials' => Http::response(['id' => '9', 'username' => 'boutique', 'acct' => 'boutique', 'display_name' => 'Boutique']),
            'mastodon.example/api/v1/statuses' => Http::response($mastodonStatus === 200 ? ['id' => '555', 'url' => 'https://mastodon.example/@boutique/555'] : ['error' => 'Service Unavailable'], $mastodonStatus),
            'sensya.yomaratechnologies.com/api/v1/external/me' => Http::response(['data' => ['id' => 'u42', 'name' => 'Boutique Awa', 'username' => 'awa', 'max_length' => 1500]]),
            'sensya.yomaratechnologies.com/api/v1/external/posts' => Http::response(['data' => ['id' => 'p1', 'url' => 'https://sensya.yomaratechnologies.com/p/p1']], 201),
        ]);
    }

    /** Société avec Telegram, Mastodon et Sensya connectés. */
    protected function connected(string $token): array
    {
        $this->api($token)->postJson('/api/social/connect-token/telegram', ['token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'chat' => '@boutique'])->assertOk();
        $this->api($token)->postJson('/api/social/connect-token/mastodon', ['instance' => 'mastodon.example', 'token' => 'tok'])->assertOk();
        $acc = $this->api($token)->postJson('/api/social/connect-token/sensya', ['token' => 'sensya-token'])->assertOk()->json('accounts');

        return collect($acc)->pluck('id', 'network')->all();
    }

    public function test_text_is_fitted_to_each_network_limit(): void
    {
        $long = str_repeat('Notre nouvelle collection arrive en boutique avec des tissus wax faits main. ', 12).'https://boutique.example/wax '.implode(' ', array_map(fn ($i) => '#Tag'.$i, range(1, 40)));
        foreach (['x' => 280, 'bluesky' => 300, 'threads' => 500, 'instagram' => 2200, 'mastodon' => 500] as $net => $limit) {
            $t = TextAdapter::fit($net, $long);
            $this->assertLessThanOrEqual($limit, Networks::count($net, $t), $net);
            $this->assertNotSame('', trim($t));
        }
        $this->assertStringContainsString('https://boutique.example/wax', TextAdapter::fit('x', $long));
        $ig = TextAdapter::fit('instagram', $long);
        $this->assertStringNotContainsString('https://', $ig); // liens non cliquables sur Instagram
        $this->assertLessThanOrEqual(30, preg_match_all('/#Tag\d+/', $ig));
        // Sur X un lien compte 23 caractères quelle que soit sa longueur
        $this->assertSame(23 + 4, Networks::count('x', 'Vu: https://exemple.com/'.str_repeat('a', 80)));
        $this->assertSame(1024, Networks::limit('telegram', true));
    }

    public function test_publish_simultaneously_on_several_networks(): void
    {
        $this->fakeNetworks();
        [, , $t] = $this->makeCompany('Awa');
        $ids = $this->connected($t);
        $this->assertSame(1500, collect($this->api($t)->getJson('/api/social/overview')->json('accounts'))->firstWhere('network', 'sensya')['limit']);

        $variants = $this->api($t)->postJson('/api/social/assist/adapt', ['text' => str_repeat('Grande promo de rentrée sur tous nos pagnes. ', 20), 'networks' => ['mastodon', 'telegram', 'sensya']])
            ->assertOk()->assertJsonPath('ai', false)->json('variants');
        $this->assertLessThanOrEqual(500, $variants['mastodon']['count']);

        $post = $this->api($t)->postJson('/api/social/posts', ['action' => 'now', 'body' => 'Bonjour à tous ! Nouvelle collection en boutique #Wax', 'accounts' => array_values($ids),
            'variants' => ['mastodon' => ['text' => $variants['mastodon']['text']]]])->assertCreated();
        $post->assertJsonPath('status', 'published');
        $this->assertCount(3, collect($post->json('targets'))->where('status', 'published'));
        $this->assertSame('https://t.me/boutique/77', collect($post->json('targets'))->firstWhere('network', 'telegram')['url']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sensya.yomaratechnologies.com/api/v1/external/posts') && $r->hasHeader('Authorization', 'Bearer sensya-token'));

        // Republier ne renvoie rien en double
        $this->api($t)->postJson('/api/social/posts/'.$post->json('id').'/publish')->assertStatus(422);
    }

    public function test_writer_needs_approval_then_scheduler_publishes(): void
    {
        $this->fakeNetworks();
        [$c, , $boss] = $this->makeCompany('Valid');
        $ids = $this->connected($boss);
        $role = Role::create(['company_id' => $c->id, 'name' => 'Rédac', 'permissions' => ['social.view', 'social.manage']]);
        $w = User::create(['company_id' => $c->id, 'role_id' => $role->id, 'name' => 'Rédactrice', 'email' => 'redac@v.ci', 'password' => 'secret123', 'is_active' => true]);
        $wt = $w->createToken('t')->plainTextToken;

        $this->api($wt)->postJson('/api/social/connect-token/telegram', ['token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'chat' => '@x'])->assertForbidden();
        $id = $this->api($wt)->postJson('/api/social/posts', ['action' => 'schedule', 'body' => 'Atelier samedi', 'accounts' => [$ids['telegram']], 'scheduled_at' => now()->addHour()->toIso8601String()])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->api($wt)->postJson('/api/social/posts/'.$id.'/approve')->assertForbidden();
        $this->api($boss)->postJson('/api/social/posts/'.$id.'/approve')->assertOk()->assertJsonPath('status', 'scheduled');

        $this->artisan('erp:social-hub')->assertSuccessful();
        $this->assertSame('scheduled', DB::table('so_posts')->where('id', $id)->value('status')); // pas encore l'heure
        $this->travel(61)->minutes();
        $this->artisan('erp:social-hub')->assertSuccessful();
        $this->assertSame('published', DB::table('so_posts')->where('id', $id)->value('status'));
    }

    public function test_limits_and_required_media_are_enforced(): void
    {
        $this->fakeNetworks();
        [, , $t] = $this->makeCompany('Lim');
        $ids = $this->connected($t);
        $this->api($t)->postJson('/api/social/posts', ['action' => 'now', 'body' => str_repeat('a', 600), 'accounts' => [$ids['mastodon']],
            'variants' => ['mastodon' => ['text' => str_repeat('a', 600)]]])->assertUnprocessable();
        $this->api($t)->postJson('/api/social/posts', ['action' => 'schedule', 'body' => 'ok', 'accounts' => [$ids['mastodon']], 'scheduled_at' => now()->subDay()->toIso8601String()])->assertUnprocessable();
        $this->api($t)->postJson('/api/social/posts', ['action' => 'draft', 'body' => 'brouillon'])->assertCreated()->assertJsonPath('status', 'draft');

        // Un fichier HTML déguisé en image est refusé
        $bad = UploadedFile::fake()->createWithContent('photo.jpg', '<html><script>alert(1)</script></html>');
        $this->api($t)->post('/api/social/media', ['file' => $bad])->assertUnprocessable();
    }

    public function test_transient_failure_is_retried_automatically(): void
    {
        [, , $t] = $this->makeCompany('Retry');
        $this->fakeNetworks(503);
        $ids = $this->connected($t);
        $id = $this->api($t)->postJson('/api/social/posts', ['action' => 'now', 'body' => 'Bonjour', 'accounts' => [$ids['mastodon'], $ids['telegram']]])->assertCreated()
            ->assertJsonPath('status', 'partial')->json('id');
        $failed = DB::table('so_targets')->where('post_id', $id)->where('network', 'mastodon')->first();
        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->retry_at);

        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fakeNetworks(200);
        $this->travel(6)->minutes();
        $this->artisan('erp:social-hub')->assertSuccessful();
        $this->assertSame('published', DB::table('so_posts')->where('id', $id)->value('status'));
        Http::assertSentCount(1); // seul Mastodon a été relancé, Telegram n'a pas reçu de doublon
    }

    public function test_accounts_and_posts_are_isolated_between_companies(): void
    {
        $this->fakeNetworks();
        [, , $a] = $this->makeCompany('A');
        $ids = $this->connected($a);
        $post = $this->api($a)->postJson('/api/social/posts', ['action' => 'draft', 'body' => 'privé', 'accounts' => [$ids['telegram']]])->json('id');
        [, , $b] = $this->makeCompany('B');
        $this->api($b)->getJson('/api/social/posts/'.$post)->assertUnprocessable();
        $this->api($b)->deleteJson('/api/social/accounts/'.$ids['telegram']);
        $this->assertDatabaseHas('so_accounts', ['id' => $ids['telegram']]);
        // Les comptes d'une autre société sont ignorés
        $this->api($b)->postJson('/api/social/posts', ['action' => 'now', 'body' => 'x', 'accounts' => [$ids['telegram']]])->assertUnprocessable();
    }

    public function test_oauth_apps_and_connection_flow(): void
    {
        config(['erp.mode' => 'saas']);
        [, , $sa] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [, , $t] = $this->makeCompany('Client');
        $this->api($t)->postJson('/api/social/connect/x')->assertUnprocessable(); // application pas encore paramétrée
        $this->api($t)->putJson('/api/admin/social-apps/x', ['client_id' => 'cid', 'client_secret' => 'sec'])->assertForbidden();
        $apps = $this->api($sa)->putJson('/api/admin/social-apps/x', ['client_id' => 'cid', 'client_secret' => 'sec'])->assertOk()->json('providers');
        $x = collect($apps)->firstWhere('key', 'x');
        $this->assertTrue($x['has_secret']);
        $this->assertArrayNotHasKey('client_secret', $x);

        $url = $this->api($t)->postJson('/api/social/connect/x')->assertOk()->json('url');
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);

        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 7200]),
            'api.x.com/2/users/me*' => Http::response(['data' => ['id' => '42', 'name' => 'Boutique', 'username' => 'boutique']]),
        ]);
        $this->get('/oauth/social/x/retour?state=mauvais&code=c')->assertStatus(422);
        $this->get('/oauth/social/x/retour?state='.$q['state'].'&code=c')->assertOk()->assertSee('Comptes connectés');
        $this->get('/oauth/social/x/retour?state='.$q['state'].'&code=c')->assertStatus(422); // lien à usage unique
        $acc = DB::table('so_accounts')->where('network', 'x')->first();
        $this->assertSame('boutique', $acc->username);
        $this->assertStringNotContainsString('"at"', $acc->credentials); // jetons chiffrés
    }

    public function test_oauth_network_drivers_publish_with_media(): void
    {
        [$c, $u, $t] = $this->makeCompany('Drivers');
        $store = fn ($net, $id, $extra = []) => \App\Modules\Social\SocialOAuth::store($c, $u, $net, $id, ucfirst($net), ['provider' => null, 'access_token' => 'tok'] + ($extra['creds'] ?? []), $extra);
        $store('facebook', '100');
        $store('instagram', '200', ['username' => 'awa']);
        $store('threads', '300', ['username' => 'awa']);
        $store('x', '400', ['username' => 'awa']);
        $store('linkedin', 'organization:500');
        $store('pinterest', 'awa', ['meta' => ['boards' => [['id' => 'b1', 'name' => 'Wax']], 'board_id' => 'b1']]);
        $store('gbp', 'accounts/1/locations/2');
        $store('bluesky', 'did:plc:abc', ['creds' => ['identifier' => 'awa.bsky.social', 'app_password' => 'abcd-efgh-ijkl-mnop']]);
        Http::fake([
            'graph.facebook.com/v21.0/100/photos' => Http::response(['id' => 'ph1', 'post_id' => '100_1']),
            'graph.facebook.com/v21.0/200/media' => Http::response(['id' => 'cont1']),
            'graph.facebook.com/v21.0/200/media_publish' => Http::response(['id' => 'ig1']),
            'graph.facebook.com/v21.0/ig1*' => Http::response(['permalink' => 'https://www.instagram.com/p/ig1']),
            'graph.threads.net/v1.0/300/threads' => Http::response(['id' => 'tc1']),
            'graph.threads.net/v1.0/tc1*' => Http::response(['status' => 'FINISHED']),
            'graph.threads.net/v1.0/300/threads_publish' => Http::response(['id' => 'th1']),
            'graph.threads.net/v1.0/th1*' => Http::response(['permalink' => 'https://www.threads.net/@awa/post/th1']),
            'api.x.com/2/media/upload' => Http::response(['data' => ['id' => 'm1']]),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => 't1']], 201),
            'api.linkedin.com/rest/images*' => Http::response(['value' => ['uploadUrl' => 'https://upload.linkedin.example/u', 'image' => 'urn:li:image:1']]),
            'upload.linkedin.example/*' => Http::response('', 201),
            'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:9']),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin1'], 201),
            'mybusiness.googleapis.com/*' => Http::response(['name' => 'accounts/1/locations/2/localPosts/7', 'searchUrl' => 'https://g.page/x']),
            'bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => 'j', 'did' => 'did:plc:abc', 'handle' => 'awa.bsky.social']),
            'bsky.social/xrpc/com.atproto.repo.uploadBlob' => Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'x'], 'mimeType' => 'image/png', 'size' => 10]]),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:abc/app.bsky.feed.post/rk1']),
        ]);
        $png = UploadedFile::fake()->image('wax.png', 800, 800);
        $media = $this->api($t)->post('/api/social/media', ['file' => $png])->assertOk()->json('id');
        $accounts = DB::table('so_accounts')->where('company_id', $c->id)->pluck('id')->all();
        $post = $this->api($t)->postJson('/api/social/posts', ['action' => 'now', 'title' => 'Nouvelle collection', 'body' => 'Nouvelle collection wax en boutique ! #Wax https://boutique.example',
            'media' => [$media], 'accounts' => $accounts, 'options' => ['first_comment' => '#Mode']])->assertCreated();
        $errors = collect($post->json('targets'))->where('status', '!=', 'published')->pluck('error', 'network')->all();
        $this->assertSame([], $errors);
        $this->assertSame('published', $post->json('status'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'createRecord') && ($r['record']['facets'] ?? null) && $r['record']['embed']['$type'] === 'app.bsky.embed.images');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.x.com/2/tweets') && $r['media']['media_ids'] === ['m1']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.pinterest.com/v5/pins') && $r['board_id'] === 'b1' && $r['title'] === 'Nouvelle collection');
    }
}
