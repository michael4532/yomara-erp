<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\PlatformSetting;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Sequences;
use App\Modules\Social\MetaWebhookController;
use App\Modules\Social\SocialOAuth;
use App\Modules\Veille\WebFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Publicité & acquisition, boîte des réseaux, relances CRM, note des prospects, visuels, balises de suivi. */
class AcquisitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WebFetcher::$resolver = fn () => ['93.184.216.34'];
        PlatformSetting::put('social.apps', ['meta' => ['client_id' => 'APP1', 'client_secret' => encrypt('APPSECRET'), 'extended' => true]]);
    }

    protected function tearDown(): void
    {
        WebFetcher::$resolver = null;
        parent::tearDown();
    }

    protected function whatsapp($api): void
    {
        $api->putJson('/api/messaging/channels/whatsapp', ['provider' => 'meta', 'settings' => ['phone_number_id' => '77', 'access_token' => 'tok']])->assertOk();
    }

    public function test_leads_from_webhooks_meta_ads_landing_pages_and_roi(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Agence Immo Plus');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/acquisition/schema')->assertOk();
        $this->whatsapp($api());
        Http::fake([
            'graph.facebook.com/*/77/messages' => Http::response(['messages' => [['id' => 'wamid.1']]]),
            'graph.facebook.com/v21.0/LG1*' => Http::response(['created_time' => '2026-11-04T10:00:00+0000', 'field_data' => [['name' => 'full_name', 'values' => ['Awa Koné']], ['name' => 'phone_number', 'values' => ['+2250701020304']],
                ['name' => 'quel_budget_?', 'values' => ['50 millions']]], 'ad_name' => 'Villa Cocody vidéo', 'campaign_id' => 'C123', 'campaign_name' => 'Villas Cocody', 'form_id' => 'F9', 'platform' => 'fb']),
            'graph.facebook.com/v21.0/F9*' => Http::response(['name' => 'Formulaire villas']),
            'graph.facebook.com/v21.0/act_555?*' => Http::response(['name' => 'Compte pub Immo', 'currency' => 'XOF']),
            'graph.facebook.com/v21.0/act_555/insights*' => Http::response(['data' => [
                ['campaign_id' => 'C123', 'campaign_name' => 'Villas Cocody', 'date_start' => now()->subDay()->toDateString(), 'spend' => '60000', 'impressions' => '9000', 'clicks' => '210', 'actions' => [['action_type' => 'lead', 'value' => '3']]],
                ['campaign_id' => 'C123', 'campaign_name' => 'Villas Cocody', 'date_start' => now()->toDateString(), 'spend' => '40000', 'impressions' => '6000', 'clicks' => '150'],
            ]]),
            'graph.facebook.com/v21.0/PIX1/events' => Http::response(['events_received' => 1]),
            'graph.facebook.com/*' => Http::response(['id' => 'x']),
        ]);

        // source « Facebook / Instagram » créée à l'activation : réponse WhatsApp et relance automatique
        $seq = $api()->postJson('/api/crm/sequences', ['name' => 'Relance prospects pub', 'steps' => [
            ['channel' => 'task', 'delay_days' => 0, 'text' => 'Appeler {{prenom}} pour qualifier le besoin'],
            ['channel' => 'whatsapp', 'delay_days' => 2, 'text' => 'Bonjour {{prenom}}, avez-vous pu regarder nos villas ? {{commercial}}'],
        ]])->assertCreated()->json('data');
        $meta = DB::table('ac_sources')->where('company_id', $company->id)->where('platform', 'meta')->first();
        $this->assertNotNull($meta);
        $api()->putJson('/api/acquisition/records/sources/'.$meta->id, ['reply_channel' => 'whatsapp', 'sequence_id' => $seq['id'], 'tags' => 'villa, pub'])->assertOk();

        // webhook Meta : signature obligatoire, page reliée par la société
        SocialOAuth::store($company, $admin, 'facebook', 'PAGE1', 'Immo Plus', ['provider' => 'meta', 'access_token' => 'PTOK'], ['kind' => 'page']);
        $this->get('/api/meta-webhook?hub.mode=subscribe&hub.verify_token='.MetaWebhookController::verifyToken().'&hub.challenge=42')->assertOk()->assertSee('42');
        $this->get('/api/meta-webhook?hub.mode=subscribe&hub.verify_token=faux&hub.challenge=42')->assertForbidden();
        $payload = json_encode(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => 'LG1', 'page_id' => 'PAGE1', 'form_id' => 'F9']]]]]]);
        $this->call('POST', '/api/meta-webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $payload)->assertForbidden();
        $this->call('POST', '/api/meta-webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'APPSECRET')], $payload)
            ->assertOk()->assertJsonPath('handled', 1);
        app()->instance('tenant', $company);
        $lead = Lead::where('ad_ref', 'LG1')->firstOrFail();
        $this->assertSame(['Facebook / Instagram — Awa Koné', 'ads', 'meta', 'Villas Cocody'], [$lead->title, $lead->source, $lead->ad_platform, DB::table('ac_campaigns')->find($lead->ad_campaign_id)->title]);
        $this->assertStringContainsString('50 millions', $lead->notes);
        $this->assertNotNull($lead->owner_id);
        $this->assertGreaterThan(15, $lead->score);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/77/messages') && str_contains(json_encode($req->data()), 'Awa'));
        $this->assertTrue(AppNotification::where('title', 'like', 'Nouveau prospect Facebook%')->exists());
        $this->assertSame(1, DB::table('crm_sequence_runs')->where('lead_id', $lead->id)->where('status', 'active')->count());
        // le même événement renvoyé n'est pas compté deux fois
        $this->call('POST', '/api/meta-webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'APPSECRET')], $payload)->assertOk();
        $this->assertSame(1, DB::table('ac_leads')->where('company_id', $company->id)->count());

        // relance : la tâche arrive tout de suite, le WhatsApp 2 jours après
        $this->assertSame(1, app(Sequences::class)->tick($company));
        $this->assertTrue(DB::table('crm_activities')->where('lead_id', $lead->id)->where('subject', 'Appeler Awa pour qualifier le besoin')->exists());
        $this->assertSame(0, app(Sequences::class)->tick($company));
        $this->travel(49)->hours();
        $this->assertSame(1, app(Sequences::class)->tick($company));
        $this->travelBack();
        $this->assertSame('done', DB::table('crm_sequence_runs')->where('lead_id', $lead->id)->value('status'));

        // formulaire externe (Zapier, WordPress…) : doublon rattaché à l'affaire en cours
        $zap = $api()->postJson('/api/acquisition/records/sources', ['title' => 'Site WordPress', 'platform' => 'website', 'assign' => 'owner'])->assertCreated()->json('data');
        $this->assertStringContainsString('/api/leads-in/', $zap['webhook_url']);
        $this->postJson('/api/leads-in/'.basename($zap['webhook_url']), ['Nom' => 'Awa Koné', 'Téléphone' => '07 01 02 03 04', 'Message' => 'Toujours intéressée'])->assertOk()->assertJsonPath('status', 'duplicate');
        $this->postJson('/api/leads-in/'.basename($zap['webhook_url']), ['nom' => 'Traoré', 'prenom' => 'Ibrahim', 'email' => 'ib@exemple.ci', 'utm_campaign' => 'Villas Cocody'])->assertOk()->assertJsonPath('status', 'created');
        app()->instance('tenant', $company);
        $ib = Lead::where('email', 'ib@exemple.ci')->firstOrFail();
        $this->assertSame(['Ibrahim Traoré', 'villas_cocody', $lead->ad_campaign_id], [$ib->contact_name, $ib->utm_campaign, $ib->ad_campaign_id]);
        $this->postJson('/api/leads-in/'.basename($zap['webhook_url']), ['nom' => 'Sans contact'])->assertStatus(422);
        $this->postJson('/api/leads-in/'.str_repeat('a', 40), ['email' => 'x@y.ci'])->assertNotFound();

        // formulaire Google Ads : clé vérifiée
        $g = $api()->postJson('/api/acquisition/records/sources', ['title' => 'Google Ads', 'platform' => 'google', 'secret' => 'cle-google'])->assertCreated()->json('data');
        $gUrl = '/api/leads-in/'.basename($g['webhook_url']);
        $gBody = ['lead_id' => 'GL1', 'campaign_id' => 999, 'form_id' => 4, 'google_key' => 'cle-google', 'user_column_data' => [['column_id' => 'FULL_NAME', 'string_value' => 'Marc Yao'], ['column_id' => 'PHONE_NUMBER', 'string_value' => '+2250505050505']]];
        $this->postJson($gUrl, ['google_key' => 'faux'] + $gBody)->assertForbidden();
        $this->postJson($gUrl, $gBody)->assertOk()->assertJsonPath('status', 'created');
        $this->assertSame('google', DB::table('ac_campaigns')->where('external_id', '999')->value('platform'));

        // compte publicitaire Meta : dépenses par campagne et par jour, conversions renvoyées
        $acc = $api()->postJson('/api/acquisition/accounts', ['platform' => 'meta', 'external_id' => 'act_555', 'token' => 'SYS-TOKEN', 'pixel_id' => 'PIX1', 'send_conversions' => true])
            ->assertCreated()->assertJsonPath('data.name', 'Compte pub Immo')->json('data');
        $this->assertSame(1, $acc['campaigns']);
        $camp = DB::table('ac_campaigns')->where('external_id', 'C123')->first();
        $this->assertEquals(100000, DB::table('ac_spend')->where('campaign_id', $camp->id)->sum('amount'));
        $this->assertArrayNotHasKey('token', $api()->getJson('/api/acquisition/accounts')->json('data.0'));
        // affaire gagnée : retour sur investissement et achat renvoyé à Meta
        $won = DB::table('crm_stages')->where('company_id', $company->id)->where('kind', 'won')->value('id');
        app()->instance('tenant', $company);
        $lead->refresh()->forceFill(['expected_revenue' => 45000000])->save();
        $lead->update(['stage_id' => $won]);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'PIX1/events') && ($req->data()['data'][0]['event_name'] ?? '') === 'Purchase' && ($req->data()['data'][0]['user_data']['lead_id'] ?? '') === 'LG1');
        $c = $api()->getJson('/api/acquisition/records/campaigns/'.$camp->id)->assertOk()->json('data');
        $this->assertEquals([100000, 2, 1, 45000000, 50000], [$c['spent'], $c['leads'], $c['won'], $c['revenue'], $c['cpl']]);
        $this->assertSame('450 ×', $c['roas']);
        $rep = $api()->getJson('/api/acquisition/report')->assertOk()->json('data');
        $this->assertEquals(100000, $rep['spent']);
        $this->assertSame(4, $rep['leads']);
        $this->assertSame(1, $rep['duplicates']);
        $this->assertSame('meta', $rep['by_platform'][0]['platform']);
        $this->assertSame(1, DB::table('crm_sequence_runs')->where('lead_id', $lead->id)->count());

        // page de capture : visites, envoi, prospect créé, page fermée
        $page = $api()->postJson('/api/acquisition/records/pages', ['title' => 'Offre villas', 'headline' => 'Votre villa à Cocody dès 45 millions', 'bullets' => "Titre foncier\nLivraison 2027",
            'fields' => ['name', 'phone', 'email'], 'status' => 'published', 'offer' => 'Frais de notaire offerts', 'deadline' => now()->addDays(5)->toDateTimeString()])->assertCreated()->json('data');
        $this->assertSame('offre-villas', $page['slug']);
        $api()->postJson('/api/acquisition/records/pages', ['title' => 'Sans tel', 'headline' => 'X', 'fields' => ['name']])->assertStatus(422);
        $this->get('/lp/offre-villas?utm_campaign=villas_cocody')->assertOk()->assertSee('Votre villa à Cocody dès 45 millions')->assertSee('Frais de notaire offerts');
        $this->post('/lp/offre-villas', ['name' => 'Fatou Bamba'])->assertRedirect();
        $this->post('/lp/offre-villas', ['name' => 'Fatou Bamba', 'phone' => '0707070707', 'email' => 'fatou@exemple.ci', 'utm_campaign' => 'villas_cocody'])->assertRedirect('/lp/offre-villas?merci=1#form');
        app()->instance('tenant', $company);
        $fb = Lead::where('email', 'fatou@exemple.ci')->firstOrFail();
        $this->assertSame(['landing', 'villas_cocody'], [$fb->source, $fb->utm_campaign]);
        $p = $api()->getJson('/api/acquisition/records/pages/'.$page['id'])->json('data');
        $this->assertSame([1, 1], [$p['views'], $p['submissions']]);
        $this->get('/lp/offre-villas?merci=1')->assertSee('Merci');

        // essai de réception depuis l'application
        $api()->postJson('/api/acquisition/sources/'.$zap['id'].'/test')->assertOk()->assertJsonPath('data.status', 'created');

        // balises de suivi : vérifiées et ajoutées aux pages
        $api()->putJson('/api/acquisition/tracking', ['values' => ['meta_pixel' => 'abc']])->assertStatus(422);
        $api()->putJson('/api/acquisition/tracking', ['values' => ['meta_pixel' => '123456789012', 'ga4' => 'G-ABCD1234', 'gsc' => '<meta name="google-site-verification" content="abcdefghijklmnopqrstuvwxyz123" />']])->assertOk();
        $this->get('/lp/offre-villas')->assertSee("fbq('init',\"123456789012\")", false)->assertSee('gtag/js?id=G-ABCD1234', false)->assertSee('abcdefghijklmnopqrstuvwxyz123', false);
    }

    public function test_social_inbox_comments_messages_rules_and_lead(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Boutique Wax');
        $api = fn () => $this->api($token);
        SocialOAuth::store($company, $admin, 'facebook', 'PG7', 'Boutique Wax', ['provider' => 'meta', 'access_token' => 'PTOK'], ['kind' => 'page']);
        $acc = DB::table('so_accounts')->where('external_id', 'PG7')->first();
        Http::fake([
            'graph.facebook.com/v21.0/PG7/subscribed_apps' => Http::response(['success' => true]),
            'graph.facebook.com/v21.0/USER9*' => Http::response(['name' => 'Mariam Diallo']),
            'graph.facebook.com/v21.0/PG7/messages' => fn () => Http::response(['recipient_id' => 'USER9', 'message_id' => 'm_'.uniqid('', true)]),
            'graph.facebook.com/v21.0/CM1/comments' => Http::response(['id' => 'CM1_R1']),
            'graph.facebook.com/v21.0/CM1' => Http::response(['success' => true]),
            'graph.facebook.com/*' => Http::response(['data' => []]),
        ]);
        $api()->postJson('/api/social/accounts/'.$acc->id.'/inbox')->assertOk()->assertJsonPath('data.webhook', true);
        $api()->putJson('/api/social/inbox-rules', ['rules' => [['keywords' => 'prix, combien', 'public' => 'Bonjour {{prenom}}, on vous écrit en privé !', 'private' => 'Bonjour {{prenom}}, voici nos prix : …']]])->assertOk();
        $sign = fn ($p) => ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $p, 'APPSECRET')];
        $p1 = json_encode(['object' => 'page', 'entry' => [['id' => 'PG7', 'changes' => [['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'add', 'comment_id' => 'CM1', 'post_id' => 'PG7_P1', 'parent_id' => 'PG7_P1',
            'message' => 'C\'est quel prix le pagne ?', 'from' => ['id' => 'U1', 'name' => 'Koffi Yao'], 'created_time' => time()]]]]]]);
        $this->call('POST', '/api/meta-webhook', [], [], [], $sign($p1), $p1)->assertOk();
        // réponse automatique au mot-clé : réponse publique + message privé
        Http::assertSent(fn ($r) => str_contains($r->url(), 'CM1/comments') && str_contains((string) $r['message'], 'Bonjour Koffi'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'PG7/messages') && ($r->data()['recipient']['comment_id'] ?? null) === 'CM1');
        $p2 = json_encode(['object' => 'page', 'entry' => [['id' => 'PG7', 'messaging' => [['sender' => ['id' => 'USER9'], 'recipient' => ['id' => 'PG7'], 'timestamp' => time() * 1000, 'message' => ['mid' => 'm_in_1', 'text' => 'Vous livrez à Bouaké ?']]]]]]);
        $this->call('POST', '/api/meta-webhook', [], [], [], $sign($p2), $p2)->assertOk();
        $this->call('POST', '/api/meta-webhook', [], [], [], $sign($p2), $p2)->assertOk(); // doublon ignoré
        $list = $api()->getJson('/api/social/inbox')->assertOk()->json();
        $this->assertSame(2, $list['counts']['open']);
        $msg = collect($list['data'])->firstWhere('kind', 'message');
        $this->assertSame(['Mariam Diallo', 1], [$msg['author'], $msg['unread']]);
        $this->assertTrue(AppNotification::where('title', 'like', 'Message Facebook : Mariam%')->exists());
        $conv = $api()->postJson('/api/social/inbox/'.$msg['id'].'/reply', ['text' => 'Oui, en 48 h.'])->assertOk()->json('data');
        $this->assertSame(['in', 'out'], array_column($conv['messages'], 'direction'));
        $this->assertSame(0, $conv['unread']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'PG7/messages') && ($r->data()['recipient']['id'] ?? null) === 'USER9');
        $com = collect($list['data'])->firstWhere('kind', 'comment');
        $c2 = $api()->getJson('/api/social/inbox/'.$com['id'])->json('data');
        $in = collect($c2['messages'])->firstWhere('direction', 'in');
        $api()->postJson('/api/social/inbox/'.$com['id'].'/messages/'.$in['id'].'/hide')->assertOk()->assertJsonPath('data.messages.0.hidden', true);
        $leadId = $api()->postJson('/api/social/inbox/'.$msg['id'].'/lead')->assertOk()->json('data.lead_id');
        $this->assertStringContainsString('Bouaké', DB::table('crm_leads')->where('id', $leadId)->value('notes'));
        $api()->putJson('/api/social/inbox/'.$msg['id'], ['status' => 'done'])->assertOk();
        $this->assertSame(1, $api()->getJson('/api/social/inbox')->json('counts.open'));
    }

    public function test_brand_kit_visuals_scoring_rfm_and_purchase_trigger(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Super Marché Bonheur');
        $api = fn () => $this->api($token);
        $prod = $this->uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'products', 'op' => 'upsert', 'record_id' => $prod, 'data' => ['name' => 'Riz parfumé 25 kg', 'sale_price' => 18000, 'type' => 'product']]]])->assertOk();
        $api()->putJson('/api/social/brand', ['primary' => 'rouge'])->assertStatus(422);
        $api()->putJson('/api/social/brand', ['primary' => '#C2410C', 'accent' => '#FDE047', 'tone' => 'fun', 'contact' => 'Tél. 07 00 11 22 33'])->assertOk()->assertJsonPath('data.primary', '#C2410C');
        $prev = $api()->postJson('/api/social/visuals/preview', ['template' => 'promo', 'product_id' => $prod, 'price' => 14900, 'old_price' => 18000, 'format' => 'story'])->assertOk()->json('data.image');
        $this->assertStringStartsWith('data:image/png;base64,', $prev);
        $png = base64_decode(substr($prev, 22));
        $this->assertSame([540, 960], array_slice(getimagesizefromstring($png), 0, 2));
        $m = $api()->postJson('/api/social/visuals', ['template' => 'price_list', 'title' => 'Prix de la semaine', 'product_ids' => [$prod], 'format' => 'square'])->assertCreated()->json('data');
        $this->assertSame([1080, 1080], [$m['width'], $m['height']]);
        $api()->postJson('/api/social/visuals', ['template' => 'announce'])->assertStatus(422);
        @unlink(public_path(DB::table('so_media')->where('id', $m['id'])->value('path')));

        // note du prospect et températures
        $lead = $this->uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'crm_leads', 'op' => 'upsert', 'record_id' => $lead,
            'data' => ['title' => 'Grossiste Adjamé', 'contact_name' => 'Moussa', 'phone' => '0700000000', 'email' => 'm@g.ci', 'company_name' => 'Ets Moussa', 'source' => 'referral', 'expected_revenue' => 2000000, 'utm_campaign' => 'rentree']]]])->assertOk();
        $score = (int) DB::table('crm_leads')->where('id', $lead)->value('score');
        $this->assertGreaterThanOrEqual(40, $score);
        $stats = $api()->getJson('/api/crm/stats')->assertOk()->json();
        $this->assertSame(1, $stats['temperature']['warm'] + $stats['temperature']['hot']);
        $this->assertSame('rentree', $stats['by_campaign'][0]['label']);

        // segment RFM et scénario « Achat »
        $api()->getJson('/api/marketing/segment-fields')->assertOk()->assertJsonPath('rfm.champions', 'Meilleurs clients');
        $api()->postJson('/api/marketing/automations', ['name' => 'Merci pour votre achat', 'trigger' => 'purchase', 'trigger_config' => ['min_amount' => 10000],
            'steps' => [['type' => 'add_tag', 'config' => ['tag' => 'client']]]])->assertSuccessful();
        $aid = DB::table('mk_automations')->where('company_id', $company->id)->value('id');
        $api()->postJson('/api/marketing/automations/'.$aid.'/status', ['status' => 'active'])->assertOk();
        $partner = $this->uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'partners', 'op' => 'upsert', 'record_id' => $partner, 'data' => ['name' => 'Aya Kouassi', 'phone' => '0102030405', 'type' => 'customer']]]])->assertOk();
        app()->instance('tenant', $company);
        $doc = new \App\Modules\Ventes\Models\SalesDocument;
        $doc->forceFill(['id' => $this->uuid(), 'company_id' => $company->id, 'direction' => 'sale', 'type' => 'invoice', 'partner_id' => $partner, 'date' => now()->toDateString(), 'status' => 'validated', 'total' => 25000, 'number' => 'FA-T1'])->saveQuietly();
        event(new \App\Modules\Core\Events\DocumentValidated($doc));
        $contact = DB::table('mk_contacts')->where('company_id', $company->id)->where('partner_id', $partner)->first();
        $this->assertNotNull($contact);
        $this->assertSame(1, DB::table('mk_automation_runs')->where('automation_id', $aid)->where('contact_id', $contact->id)->count());
        $this->assertSame(1, $api()->postJson('/api/marketing/segments/count', ['match' => 'all', 'conditions' => [['field' => 'spent_min', 'value' => 20000]]])->assertOk()->json('count'));
    }

    public function test_google_ads_search_console_and_tiktok(): void
    {
        [$company, , $token] = $this->makeCompany('Clinique Santé Plus');
        $api = fn () => $this->api($token);
        PlatformSetting::put('social.apps', ['google' => ['client_id' => 'GID', 'client_secret' => encrypt('GSEC')]]);
        config(['erp.ads.google_developer_token' => 'DEV']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]),
            'googleads.googleapis.com/*/customers:listAccessibleCustomers' => Http::response(['resourceNames' => ['customers/1234567890']]),
            'googleads.googleapis.com/*/customers/1234567890/googleAds:searchStream' => Http::sequence()
                ->push([['results' => [['customer' => ['descriptiveName' => 'Clinique Ads', 'currencyCode' => 'XOF']]]]])
                ->push([['results' => [['campaign' => ['id' => '77', 'name' => 'Consultations'], 'segments' => ['date' => now()->toDateString()], 'metrics' => ['costMicros' => '25000000000', 'impressions' => '5000', 'clicks' => '300', 'conversions' => 12]]]]]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:clinique.ci', 'permissionLevel' => 'siteOwner']]]),
            'www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => [['keys' => ['clinique abidjan'], 'clicks' => 40, 'impressions' => 900, 'ctr' => .044, 'position' => 3.2]]]),
            'business-api.tiktok.com/open_api/v1.3/advertiser/info/*' => Http::response(['code' => 0, 'data' => ['list' => [['name' => 'Clinique TikTok', 'currency' => 'XOF']]]]),
            'business-api.tiktok.com/open_api/v1.3/report/integrated/get/*' => Http::response(['code' => 0, 'data' => ['list' => [['dimensions' => ['campaign_id' => 'T1', 'stat_time_day' => now()->toDateString().' 00:00:00'], 'metrics' => ['campaign_name' => 'Bilan santé', 'spend' => '15000', 'impressions' => '20000', 'clicks' => '500', 'conversion' => '9']]]]]),
        ]);
        $url = $api()->postJson('/api/acquisition/google/connect')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringContainsString('adwords', $q['scope']);
        $this->get('/oauth/acquisition/google/retour?state='.$q['state'].'&code=abc')->assertOk()->assertSee('Google est relié')->assertSee('1 compte(s) Google Ads');
        $acc = DB::table('ac_accounts')->where('company_id', $company->id)->where('platform', 'google')->first();
        $this->assertSame(['Clinique Ads', '1234567890'], [$acc->name, $acc->external_id]);
        $api()->postJson('/api/acquisition/accounts/'.$acc->id.'/sync')->assertOk()->assertJsonPath('data.rows', 1);
        $this->assertEquals(25000, DB::table('ac_spend')->where('company_id', $company->id)->sum('amount'));
        $gsc = $api()->getJson('/api/acquisition/search-console')->assertOk()->json('data');
        $this->assertSame(['clinique abidjan', 4.4], [$gsc['queries'][0]['key'], $gsc['queries'][0]['ctr']]);
        $api()->postJson('/api/acquisition/accounts', ['platform' => 'tiktok', 'external_id' => '700', 'token' => 'TT'])->assertCreated()->assertJsonPath('data.name', 'Clinique TikTok');
        $this->assertSame('Bilan santé', DB::table('ac_campaigns')->where('external_id', 'T1')->value('title'));
        $this->get('/oauth/acquisition/google/retour?state=inconnu&code=x')->assertSee('Connexion annulée');
    }
}
