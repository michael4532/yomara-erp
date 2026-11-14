<?php

namespace Tests\Feature;

use App\Models\CompanyModule;
use App\Models\PlatformSetting;
use App\Modules\Social\SocialOAuth;
use App\Modules\Social\XCredits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Plateforme en ligne : chaque publication sur X consomme des crédits du mois (inclus + packs achetés). */
class XCreditsTest extends TestCase
{
    public function test_x_posts_consume_monthly_credits_and_are_refused_beyond(): void
    {
        config(['erp.mode' => 'saas']);
        [$c, $u, $t] = $this->makeCompany('Crédits X');
        PlatformSetting::put('social.apps', ['x' => ['client_id' => 'cid', 'client_secret' => encrypt('s'), 'included_credits' => 3, 'link_weight' => 2]]);
        $x = SocialOAuth::store($c, $u, 'x', '400', 'Awa', ['provider' => null, 'access_token' => 'tok'], ['username' => 'awa']);
        $xId = DB::table('so_accounts')->where('company_id', $c->id)->where('network', 'x')->value('id');
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => 't1']], 201)]);

        $this->api($t)->getJson('/api/social/overview')->assertOk()->assertJsonPath('x_credits.quota', 3)->assertJsonPath('x_credits.left', 3);

        // 1 crédit sans lien, 2 avec un lien
        $this->api($t)->postJson('/api/social/posts', ['action' => 'now', 'body' => 'Bonjour', 'accounts' => [$xId]])->assertCreated()->assertJsonPath('status', 'published');
        $this->api($t)->postJson('/api/social/posts', ['action' => 'now', 'body' => 'Promo https://boutique.example', 'accounts' => [$xId]])->assertCreated()->assertJsonPath('status', 'published');
        $this->assertSame(3, XCredits::used($c));

        // Plus de crédits : refus à l'enregistrement, avec invitation à acheter un pack
        $r = $this->api($t)->postJson('/api/social/posts', ['action' => 'now', 'body' => 'Encore', 'accounts' => [$xId]])->assertUnprocessable();
        $this->assertStringContainsString('Crédits X insuffisants', json_encode($r->json(), JSON_UNESCAPED_UNICODE));

        // Une publication planifiée qui arrive à échéance sans crédits échoue sans relance
        $id = DB::table('so_posts')->insertGetId(['company_id' => $c->id, 'user_id' => $u->id, 'body' => 'Planifiée', 'status' => 'scheduled', 'scheduled_at' => now()->subMinute(), 'media' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('so_targets')->insert(['post_id' => $id, 'company_id' => $c->id, 'account_id' => $xId, 'network' => 'x', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        app(\App\Modules\Social\SocialService::class)->tick($c);
        $target = DB::table('so_targets')->where('post_id', $id)->first();
        $this->assertSame('failed', $target->status);
        $this->assertNull($target->retry_at);
        Http::assertSentCount(2);

        // Un pack acheté ajoute ses crédits au mois en cours
        CompanyModule::updateOrCreate(['company_id' => $c->id, 'module_key' => 'credits_x_50'], ['enabled' => true]);
        $this->api($t)->getJson('/api/social/overview')->assertJsonPath('x_credits.quota', 53)->assertJsonPath('x_credits.left', 50);
        $this->api($t)->postJson('/api/social/posts', ['action' => 'now', 'body' => 'Merci', 'accounts' => [$xId]])->assertCreated()->assertJsonPath('status', 'published');
    }

    public function test_private_installations_have_no_x_limit(): void
    {
        config(['erp.mode' => 'self']);
        [$c, $u, $t] = $this->makeCompany('Installation privée');
        PlatformSetting::put('social.apps', ['x' => ['client_id' => 'cid', 'client_secret' => encrypt('s'), 'included_credits' => 0]]);
        SocialOAuth::store($c, $u, 'x', '400', 'Awa', ['provider' => null, 'access_token' => 'tok'], ['username' => 'awa']);
        $xId = DB::table('so_accounts')->where('company_id', $c->id)->where('network', 'x')->value('id');
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => 't1']], 201)]);
        $this->api($t)->getJson('/api/social/overview')->assertJsonPath('x_credits', null);
        $this->api($t)->postJson('/api/social/posts', ['action' => 'now', 'body' => 'Bonjour', 'accounts' => [$xId]])->assertCreated()->assertJsonPath('status', 'published');
    }

    public function test_x_statistics_are_read_only_twice(): void
    {
        [$c, $u] = $this->makeCompany('Stats X');
        SocialOAuth::store($c, $u, 'x', '400', 'Awa', ['provider' => null, 'access_token' => 'tok'], ['username' => 'awa']);
        $acc = DB::table('so_accounts')->where('company_id', $c->id)->value('id');
        $post = DB::table('so_posts')->insertGetId(['company_id' => $c->id, 'user_id' => $u->id, 'body' => 'b', 'status' => 'published', 'media' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $tid = DB::table('so_targets')->insertGetId(['post_id' => $post, 'company_id' => $c->id, 'account_id' => $acc, 'network' => 'x', 'status' => 'published', 'external_id' => 't1',
            'published_at' => now()->subHours(2), 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['api.x.com/2/tweets/*' => Http::response(['data' => ['public_metrics' => ['like_count' => 3]]])]);
        $s = app(\App\Modules\Social\SocialService::class);

        $this->assertSame(0, $s->syncStats($c)); // trop tôt : on attend 24 heures
        DB::table('so_targets')->where('id', $tid)->update(['published_at' => now()->subDays(2)]);
        $this->assertSame(1, $s->syncStats($c)); // relevé à 24 heures
        $this->travel(8)->hours();
        $this->assertSame(0, $s->syncStats($c)); // pas de relevé toutes les 6 heures pour X
        DB::table('so_targets')->where('id', $tid)->update(['published_at' => now()->subDays(8), 'stats_at' => now()->subDays(6)]);
        $this->assertSame(1, $s->syncStats($c)); // relevé à 7 jours
        $this->assertSame(0, $s->syncStats($c));
    }
}
