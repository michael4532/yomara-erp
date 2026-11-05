<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Veille\VeilleService;
use App\Modules\Veille\WebFetcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Veille concurrentielle : fiches, prix comparés au catalogue, sites surveillés, analyse de site, mentions, comparatif, droits. */
class VeilleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WebFetcher::$resolver = fn (string $host) => match (true) {
            str_ends_with($host, 'interne.lan'), $host === 'intranet' => ['10.0.0.5'],
            default => ['93.184.216.34'],
        };
    }

    protected function tearDown(): void
    {
        WebFetcher::$resolver = null;
        parent::tearDown();
    }

    public function test_competitors_prices_watches_audit_mentions_and_compare(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Boulangerie Les Délices');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/veille/schema')->assertOk()->assertJsonPath('data.resources.competitors.can_manage', true);
        $prod = $this->uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'products', 'op' => 'upsert', 'record_id' => $prod,
            'data' => ['name' => 'Baguette tradition', 'sku' => 'BAG-T', 'sale_price' => 200, 'type' => 'product']]]])->assertOk();

        $us = $api()->postJson('/api/veille/records/competitors', ['title' => 'Les Délices', 'kind' => 'us', 'score_price' => '3', 'score_quality' => '5'])->assertCreated()->json('data');
        $c = $api()->postJson('/api/veille/records/competitors', ['title' => 'Pain Doré', 'website' => 'paindore.ci', 'threat' => 'high',
            'score_price' => '4', 'score_quality' => '3', 'score_service' => '2'])->assertCreated()->assertJsonPath('data.website', 'https://paindore.ci')->assertJsonPath('data.score', 3)->json('data');
        $this->assertCount(4, $c['ad_libraries']);
        $this->assertStringContainsString('q=Pain%20Dor%C3%A9', $c['ad_libraries'][0]['url']);
        $this->assertStringContainsString('domain=paindore.ci', $c['ad_libraries'][1]['url']);

        // relevés de prix : notre prix repris du catalogue, écart, évolution
        $p1 = $api()->postJson('/api/veille/records/prices', ['title' => 'Baguette', 'competitor_id' => $c['id'], 'competitor_price' => 180, 'our_product' => 'BAG-T', 'observed_on' => now()->subDays(10)->toDateString()])
            ->assertCreated()->assertJsonPath('data.our_price', 200)->assertJsonPath('data.gap', -10)->assertJsonPath('data.trend', 'Premier relevé')->json('data');
        $api()->postJson('/api/veille/records/prices', ['title' => 'Baguette', 'competitor_id' => $c['id'], 'competitor_price' => 150, 'promo' => true, 'our_product' => 'Baguette tradition'])
            ->assertCreated()->assertJsonPath('data.gap', -25)->assertJsonPath('data.trend', 'Baisse -16,7 %');
        $this->assertSame($prod, DB::table('vc_prices')->where('id', $p1['id'])->value('product_id'));
        $this->assertEquals(75, $api()->getJson('/api/veille/records/competitors/'.$c['id'])->json('data.price_index'));

        // publicités et audience
        $api()->postJson('/api/veille/records/ads', ['title' => '2 baguettes achetées = 1 offerte', 'competitor_id' => $c['id'], 'platform' => 'meta', 'started_on' => now()->subDays(4)->toDateString()])
            ->assertCreated()->assertJsonPath('data.days', 5);
        $api()->postJson('/api/veille/records/audience', ['competitor_id' => $c['id'], 'network' => 'facebook', 'followers' => 1000, 'recorded_on' => now()->subDays(30)->toDateString()])->assertCreated();
        $api()->postJson('/api/veille/records/audience', ['competitor_id' => $c['id'], 'network' => 'facebook', 'followers' => 1200])->assertCreated()->assertJsonPath('data.growth', '+200 (+20,0 %) en 30 j');

        // surveillance d'un site : adresse interne refusée ; premier passage mémorisé ; changement détecté → observation + notification
        $api()->postJson('/api/veille/records/watches', ['title' => 'Intranet', 'url' => 'http://intranet/admin'])->assertStatus(422);
        $api()->postJson('/api/veille/records/watches', ['title' => 'Interne', 'url' => 'https://srv.interne.lan/'])->assertStatus(422);
        $page = '<html><body><h1>Nos prix</h1><p>Baguette : 180 F</p><p>Croissant : 300 F</p><script>var t=1</script></body></html>';
        Http::fake(['paindore.ci/tarifs' => Http::sequence()->push($page)->push($page)->push(str_replace('180 F', '150 F — promo', $page))]);
        $w = $api()->postJson('/api/veille/records/watches', ['title' => 'Tarifs Pain Doré', 'competitor_id' => $c['id'], 'kind' => 'prices', 'url' => 'https://paindore.ci/tarifs'])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('last_text', $w);
        $api()->postJson('/api/veille/watches/'.$w['id'].'/check')->assertOk()->assertJsonPath('data.result.first', true);
        $api()->postJson('/api/veille/watches/'.$w['id'].'/check')->assertOk()->assertJsonPath('data.result.changed', false);
        $r = $api()->postJson('/api/veille/watches/'.$w['id'].'/check')->assertOk()->assertJsonPath('data.result.changed', true)->json('data');
        $this->assertStringContainsString('Baguette : 150 F — promo', $r['result']['summary']);
        $this->assertStringContainsString('Retiré (1)', $r['result']['summary']);
        $this->assertSame(1, $r['watch']['changes']);
        $obs = DB::table('vc_observations')->find($r['result']['observation_id']);
        $this->assertSame(['website', 'price', 'high'], [$obs->source, $obs->kind, $obs->impact]);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Site concurrent modifié%')->exists());

        // analyse du site : outils marketing détectés, réseaux complétés
        Http::fake([
            'paindore.ci/robots.txt' => Http::response("User-agent: *\nAllow: /"),
            'paindore.ci/sitemap.xml' => Http::response('', 404), 'paindore.ci/sitemap_index.xml' => Http::response('', 404), 'paindore.ci/wp-sitemap.xml' => Http::response('<sitemapindex></sitemapindex>'),
            'paindore.ci*' => Http::response('<html lang="fr"><head><title>Pain Doré — boulangerie artisanale à Abidjan</title><meta name="viewport" content="width=device-width">'
                .'<link rel="stylesheet" href="/wp-content/themes/x.css"><script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>'
                .'<script>!function(f,b,e,v,n,t,s){}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");fbq("init","123");</script></head>'
                .'<body><h1>Le bon pain</h1><a href="https://www.facebook.com/paindore">FB</a><a href="https://wa.me/2250700000000">WhatsApp</a></body></html>'),
        ]);
        $c2 = $api()->postJson('/api/veille/competitors/'.$c['id'].'/audit')->assertOk()->json('data');
        $this->assertStringContainsString('Publicité : Meta Pixel', $c2['tools']);
        $this->assertStringContainsString('Google Analytics', $c2['tools']);
        $this->assertStringContainsString('WordPress', $c2['tools']);
        $this->assertStringContainsString('Bouton WhatsApp', $c2['tools']);
        $this->assertSame('https://www.facebook.com/paindore', $c2['facebook']);
        $this->assertTrue($c2['site_audit']['sitemap']);
        $this->assertStringContainsString('Référencement :', $c2['audit_summary']);

        // mots-clés : Google Actualités, premier passage sans alerte, puis nouvelle mention → observation
        $rss = fn (array $items) => '<?xml version="1.0"?><rss><channel>'.implode('', array_map(fn ($i) => '<item><title>'.$i[0].' - Fraternité Matin</title><link>'.$i[1].'</link><pubDate>'.now()->subDay()->toRfc2822String().'</pubDate><source url="https://fratmat.info">Fraternité Matin</source></item>', $items)).'</channel></rss>';
        Http::fake(['news.google.com/*' => Http::sequence()
            ->push($rss([['Pain Doré ouvre à Cocody', 'https://news.example/a']]))
            ->push($rss([['Pain Doré ouvre à Cocody', 'https://news.example/a'], ['Pain Doré lève des fonds', 'https://news.example/b']]))]);
        $al = $api()->postJson('/api/veille/records/alerts', ['title' => '"Pain Doré"', 'competitor_id' => $c['id']])->assertCreated()->json('data');
        $api()->postJson('/api/veille/alerts/'.$al['id'].'/check')->assertOk()->assertJsonPath('data.result.new', 1)->assertJsonPath('data.result.first', true);
        $api()->postJson('/api/veille/alerts/'.$al['id'].'/check')->assertOk()->assertJsonPath('data.result.new', 1);
        $m = DB::table('vc_mentions')->where('url', 'https://news.example/b')->first();
        $this->assertSame(['new', 'Fraternité Matin', 'Pain Doré lève des fonds'], [$m->status, $m->source_name, $m->title]);
        $this->assertSame('seen', DB::table('vc_mentions')->where('url', 'https://news.example/a')->value('status'));
        $api()->postJson('/api/veille/mentions/'.$m->id.'/observe')->assertOk()->assertJsonPath('data.kind', 'press')->assertJsonPath('data.source', 'mention');
        $this->assertSame('useful', DB::table('vc_mentions')->find($m->id)->status);

        // comparatif et tableau de bord
        $cmp = $api()->getJson('/api/veille/compare')->assertOk()->json('data');
        $this->assertSame('us', $cmp['competitors'][0]['kind']);
        $pd = collect($cmp['competitors'])->firstWhere('id', $c['id']);
        $this->assertEquals(75, $pd['price_index']);
        $this->assertContains('Meta Pixel (Facebook / Instagram)', $pd['tools']);
        $this->assertSame(1200, $pd['audience']);
        $d = $api()->getJson('/api/veille/dashboard')->assertOk()->json('data');
        $this->assertSame(1, $d['competitors']);
        $this->assertSame(1, $d['ads_active']);
        $this->assertArrayHasKey('ai', $d);
        if (! $d['ai']) {
            $api()->postJson('/api/veille/competitors/'.$c['id'].'/battle-card')->assertStatus(422);
        }

        // un concurrent avec des relevés ne se supprime pas
        $api()->deleteJson('/api/veille/records/competitors/'.$c['id'])->assertStatus(422);
        $api()->deleteJson('/api/veille/records/competitors/'.$us['id'])->assertNoContent();

        // synthèse du lundi : une seule fois
        $this->assertTrue(app(VeilleService::class)->digest($company));
    }

    public function test_field_reporter_rights_photo_and_high_impact_alert(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Distribution Kouadio');
        $c = $this->api($token)->postJson('/api/veille/records/competitors', ['title' => 'Prix Bas Market'])->assertCreated()->json('data');
        $role = Role::create(['company_id' => $company->id, 'name' => 'Terrain', 'permissions' => ['veille.report']]);
        $rep = User::create(['company_id' => $company->id, 'role_id' => $role->id, 'name' => 'Commercial terrain', 'email' => 'terrain@dk.ci', 'password' => 'secret123', 'is_active' => true]);
        $repToken = $rep->createToken('t')->plainTextToken;
        $api = fn () => $this->api($repToken);
        $api()->postJson('/api/veille/records/competitors', ['title' => 'Autre'])->assertForbidden();
        $o = $api()->postJson('/api/veille/records/observations', ['title' => 'Promo -30 % sur le riz 25 kg', 'competitor_id' => $c['id'], 'kind' => 'price', 'impact' => 'high', 'source' => 'website'])
            ->assertCreated()->assertJsonPath('data.source', 'manual')->json('data');
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Veille : Prix Bas Market%')->exists());
        $api()->postJson('/api/veille/observations/'.$o['id'].'/photo', ['file' => UploadedFile::fake()->image('rayon.jpg')])->assertOk()->assertJsonPath('data.has_photo', true);
        $api()->get('/api/veille/observations/'.$o['id'].'/photo')->assertOk();
        $api()->postJson('/api/veille/watches/1/check')->assertForbidden();
        // il ne modifie pas l'observation d'un collègue
        $other = $this->api($token)->postJson('/api/veille/records/observations', ['title' => 'Ouverture d\'un dépôt à Yopougon', 'competitor_id' => $c['id']])->assertCreated()->json('data');
        $api()->putJson('/api/veille/records/observations/'.$other['id'], ['title' => 'x'])->assertStatus(422);
        $api()->deleteJson('/api/veille/records/observations/'.$o['id'])->assertForbidden();
    }
}
