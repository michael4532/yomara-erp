<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\AppNotification;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteOrder;
use App\Modules\Sites\Models\SitePost;
use App\Modules\Sites\SiteService;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteShopBlogTest extends TestCase
{
    protected ArrayTransport $mails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mails = new ArrayTransport;
        Smtp::$transportFactory = fn () => $this->mails;
    }

    protected function tearDown(): void
    {
        Smtp::$transportFactory = null;
        parent::tearDown();
    }

    private function public(): void
    {
        $this->flushHeaders();
        app()->forgetInstance('tenant');
    }

    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Boutique Awa Mode');
        $company->forceFill(['phone' => '07 07 55 44 33', 'city' => 'Treichville'])->save();
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        app()->instance('tenant', $company);
        $site = app(SiteService::class)->create($company, 'boutique-eclat', 'Awa Mode');
        app(SiteService::class)->publish($site, true);
        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.a.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'a@a.ci', 'password' => 'x', 'from_email' => 'a@a.ci', 'from_name' => 'Awa Mode',
        ]])->assertOk();

        return compact('company', 'admin', 'token', 'api', 'push', 'site');
    }

    public function test_online_shop_cart_cash_on_delivery_online_payment_stock_statuses(): void
    {
        ['company' => $company, 'admin' => $admin, 'api' => $api, 'push' => $push, 'site' => $site] = $this->env();
        // Stock réservé par les commandes en cours (module Boutique en ligne) : vérifié dans EcommerceTest
        $api()->putJson('/api/ecommerce/settings', ['reserve_stock' => false])->assertOk();
        $cat = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'product_categories', 'op' => 'upsert', 'record_id' => $catId = $this->uuid(), 'data' => ['name' => 'Robes']]]])->json('results.0.status');
        $this->assertSame('applied', $cat);
        $robe = $this->uuid();
        $push('products', ['name' => 'Robe wax Akwaba', 'sale_price' => 15000, 'tax_rate' => 18, 'type' => 'product', 'category_id' => $catId, 'track_stock' => true], $robe);
        $sac = $this->uuid();
        $push('products', ['name' => 'Sac en raphia', 'sale_price' => 8000, 'tax_rate' => 0, 'type' => 'product', 'track_stock' => true], $sac);
        $foulard = $this->uuid();
        $push('products', ['name' => 'Foulard', 'sale_price' => 3000, 'tax_rate' => 18, 'type' => 'product', 'track_stock' => false], $foulard);
        $wh = $api()->postJson('/api/stock/warehouses', ['name' => 'Boutique', 'is_default' => true])->json('data.id');
        $push('stock_moves', ['product_id' => $robe, 'warehouse_id' => $wh, 'quantity' => 3, 'type' => 'adjustment', 'date' => now()->toDateString()]);

        // Boutique fermée tant qu'elle n'est pas activée
        $this->public();
        $this->get('/site/boutique-awa-mode/boutique')->assertNotFound();
        $api()->putJson('/api/sites/shop/settings', ['enabled' => true, 'payment_online' => false, 'payment_cod' => false, 'payment_momo' => false])->assertStatus(422);
        $set = $api()->putJson('/api/sites/shop/settings', ['enabled' => true, 'pickup' => true, 'pickup_address' => 'Avenue 16, Treichville', 'free_from' => 50000, 'min_order' => 5000,
            'zones' => [['name' => 'Abidjan Sud', 'fee' => 1000], ['name' => 'Abidjan Nord', 'fee' => 2000], ['name' => '', 'fee' => 5]], 'payment_online' => true, 'payment_cod' => true,
            'payment_momo' => true, 'momo_instructions' => 'Envoyez au 07 07 55 44 33 (Orange Money)'])->assertOk()->json('data');
        $this->assertCount(2, $set['zones']);

        // Catalogue public : prix TTC, stock, rupture, catégories ; paiement en ligne masqué sans prestataire
        $this->public();
        $page = $this->get("/site/boutique-awa-mode/boutique")->assertOk();
        $page->assertSee('Robe wax Akwaba')->assertSee('17 700 F CFA')->assertSee('8 000 F CFA')->assertSee('3 540 F CFA')->assertSee('Plus que 3')->assertSee('Rupture de stock')
            ->assertSee('Robes')->assertSee('id="cartCount"', false)->assertSee('Avenue 16, Treichville')->assertSee('Abidjan Nord')->assertDontSee('Payer maintenant')->assertSee('Payer à la livraison')->assertSee('Orange Money');
        $this->get('/site/boutique-awa-mode')->assertOk()->assertSee('Boutique');

        // Erreurs : rupture, stock dépassé, minimum, adresse manquante
        $cart = fn (array $items) => json_encode(array_map(fn ($id, $q) => ['id' => $id, 'quantity' => $q], array_keys($items), $items));
        $base = ['name' => 'Fatou Diallo', 'phone' => '05 44 33 22 11', 'email' => 'fatou@exemple.ci', 'delivery' => 'Abidjan Sud', 'address' => 'Koumassi, rue 12', 'payment' => 'cod'];
        $this->post('/site/boutique-awa-mode/commande', $base + ['cart' => $cart([$sac => 1])])->assertRedirectContains('rupture');
        $this->post('/site/boutique-awa-mode/commande', $base + ['cart' => $cart([$robe => 4])])->assertRedirectContains('plus+que+3+en+stock');
        $this->post('/site/boutique-awa-mode/commande', $base + ['cart' => $cart([$foulard => 1])])->assertRedirectContains('minimum');
        $this->post('/site/boutique-awa-mode/commande', array_merge($base, ['address' => '']) + ['cart' => $cart([$robe => 1])])->assertRedirectContains('adresse');
        $this->post('/site/boutique-awa-mode/commande', array_merge($base, ['payment' => 'online', 'cart' => $cart([$robe => 1])]))->assertRedirectContains('/commande/'); // sans prestataire : paiement à la livraison
        $this->assertSame(0 + 1, SiteOrder::withoutGlobalScopes()->count());
        $o0 = SiteOrder::withoutGlobalScopes()->first();
        $this->assertSame('cod', $o0->payment_mode);

        // Commande à la livraison : commande client validée dans les Ventes, client créé, équipe et client prévenus
        $r = $this->post('/site/boutique-awa-mode/commande', $base + ['cart' => $cart([$robe => 2, $foulard => 3]), 'note' => 'Appeler avant']);
        $o = SiteOrder::withoutGlobalScopes()->orderByDesc('id')->first();
        $r->assertRedirect('/site/boutique-awa-mode/commande/'.$o->token.'?nouveau=1');
        $this->assertSame('WEB-'.now()->year.'-0002', $o->number);
        $this->assertEquals([35400 + 10620, 1000, 47020], [$o->subtotal, $o->delivery_fee, $o->total]);
        $doc = SalesDocument::withoutGlobalScopes()->with('lines')->find($o->document_id);
        $this->assertEquals(['order', 'validated', $o->number, 47020.0], [$doc->type, $doc->status, $doc->reference, (float) $doc->total]);
        $this->assertSame(['Robe wax Akwaba', 'Foulard', 'Livraison — Abidjan Sud'], $doc->lines->pluck('description')->all());
        $this->assertSame('Fatou Diallo', \App\Modules\Core\Models\Partner::withoutGlobalScopes()->find($o->partner_id)->name);
        $this->assertSame($o0->partner_id, $o->partner_id); // même cliente retrouvée
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Commande en ligne '.$o->number.'%')->count());
        $mail = collect($this->mails->messages()->values())->map->getOriginalMessage()->last();
        $this->assertSame('Commande '.$o->number.' — Boutique Awa Mode', $mail->getSubject());
        $this->assertStringContainsString('/site/boutique-awa-mode/commande/'.$o->token, $mail->getHtmlBody());
        $this->get('/site/boutique-awa-mode/commande/'.$o->token.'?nouveau=1')->assertOk()->assertSee('Merci Fatou Diallo')->assertSee('2 × Robe wax Akwaba')->assertSee('47 020')->assertSee('localStorage.removeItem', false);
        $this->get('/site/boutique-awa-mode/commande/'.str_repeat('a', 40))->assertNotFound();
        // Retrait en boutique, transfert Mobile Money
        $this->post('/site/boutique-awa-mode/commande', array_merge($base, ['delivery' => 'pickup', 'address' => '', 'payment' => 'momo', 'cart' => $cart([$foulard => 2])]))->assertRedirectContains('/commande/');
        $mo = SiteOrder::withoutGlobalScopes()->orderByDesc('id')->first();
        $this->assertEquals([0, 'Retrait en boutique', 'momo', 7080], [$mo->delivery_fee, $mo->delivery_mode, $mo->payment_mode, $mo->total]);
        $this->get('/site/boutique-awa-mode/commande/'.$mo->token)->assertSee('Envoyez au 07 07 55 44 33');

        // Paiement en ligne : facture validée, redirection vers le paiement, commande payée et confirmée au règlement
        $this->api($this->tokenOf($admin))->postJson('/api/payments/gateways', ['driver' => 'paystack', 'label' => 'Carte / Mobile Money', 'credentials' => ['secret_key' => 'sk_test_abcdef123456']])->assertCreated();
        $this->public();
        $this->get('/site/boutique-awa-mode/boutique')->assertSee('Payer maintenant');
        $res = $this->post('/site/boutique-awa-mode/commande', ['name' => 'Koffi Yao', 'phone' => '0102030405', 'delivery' => 'Abidjan Nord', 'address' => 'Cocody Angré', 'payment' => 'online', 'cart' => $cart([$robe => 1])]);
        $po = SiteOrder::withoutGlobalScopes()->orderByDesc('id')->first();
        $this->assertStringContainsString('/pay/', $res->headers->get('Location'));
        $inv = SalesDocument::withoutGlobalScopes()->find($po->document_id);
        $this->assertEquals(['invoice', 19700.0], [$inv->type, (float) $inv->total]);
        $this->get('/site/boutique-awa-mode/commande/'.$po->token)->assertSee('Payer maintenant');
        app()->instance('tenant', $company);
        $p = new \App\Modules\Ventes\Models\SalesPayment(['document_id' => $inv->id, 'date' => now()->toDateString(), 'amount' => 19700, 'method' => 'mobile_money']);
        $p->partner_id = $inv->partner_id;
        $p->save();
        $inv->refresh()->refreshPaymentStatus();
        $this->assertEquals(['paid', 'confirmed'], [$po->fresh()->payment_status, $po->fresh()->status]);
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('title', 'Commande payée en ligne : '.$po->number)->count());

        // Suivi dans l'ERP : états, annulation (document de vente annulé), refus d'annuler une commande payée
        $shop = $api()->getJson('/api/sites/shop')->assertOk()->json('data');
        $this->assertCount(4, $shop['orders']);
        $this->assertSame(4, $shop['stats']['month_count']);
        $this->assertEquals(SiteOrder::withoutGlobalScopes()->sum('total'), $shop['stats']['month_total']);
        $this->assertStringContainsString('wa.me/2250544332211', $shop['orders'][1]['whatsapp_url'] ?? $shop['orders'][2]['whatsapp_url']);
        $api()->postJson('/api/sites/orders/'.$o->id.'/status', ['status' => 'preparing'])->assertOk()->assertJsonPath('data.status_label', 'En préparation');
        $api()->postJson('/api/sites/orders/'.$o->id.'/status', ['status' => 'delivered', 'paid' => true, 'note' => 'Payé en espèces'])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $api()->postJson('/api/sites/orders/'.$o->id.'/status', ['status' => 'cancelled'])->assertStatus(422);
        $api()->postJson('/api/sites/orders/'.$po->id.'/status', ['status' => 'cancelled'])->assertStatus(422);
        $api()->postJson('/api/sites/orders/'.$o0->id.'/status', ['status' => 'cancelled'])->assertOk();
        $this->assertSame('cancelled', SalesDocument::withoutGlobalScopes()->find($o0->document_id)->status);
        $this->public();
        $this->get('/site/boutique-awa-mode/commande/'.$o0->token)->assertSee('Cette commande a été annulée');
        $this->get('/site/boutique-awa-mode/commande/'.$o->token)->assertSee('Livrée');
    }

    private function tokenOf($user): string
    {
        return $user->createToken('t')->plainTextToken;
    }

    public function test_blog_articles_formatting_publication_home_section_sitemap_and_custom_domain(): void
    {
        Storage::fake('public');
        ['api' => $api, 'site' => $site] = $this->env();
        $api()->postJson('/api/sites/posts', ['body' => 'x'])->assertStatus(422);
        $p = $api()->post('/api/sites/posts', ['title' => 'Comment porter le wax au bureau', 'excerpt' => 'Nos conseils pour un style élégant.', 'cover' => UploadedFile::fake()->image('wax.jpg'),
            'body' => "## Les bases\n\nChoisissez des **motifs sobres** et une *coupe droite*.\n\n- Une veste\n- Un pantalon\n\nVoir [notre boutique](https://exemple.ci/boutique).\n\n<script>alert(1)</script>"])->assertCreated()->json('data');
        $this->assertSame(['comment-porter-le-wax-au-bureau', false], [$p['slug'], $p['published']]);
        $this->assertNotNull($p['cover_url']);
        $p2 = $api()->post('/api/sites/posts', ['title' => 'Comment porter le wax au bureau', 'body' => 'Bis', 'published' => '1'])->assertCreated()->json('data');
        $this->assertSame('comment-porter-le-wax-au-bureau-2', $p2['slug']);
        $this->public();
        $this->get('/site/boutique-awa-mode/blog/'.$p['slug'])->assertNotFound(); // brouillon
        $api()->post('/api/sites/posts/'.$p['id'], ['published' => '1'])->assertOk()->assertJsonPath('data.published', true);
        $this->public();
        $html = $this->get('/site/boutique-awa-mode/blog/'.$p['slug'])->assertOk();
        $html->assertSee('<h2>Les bases</h2>', false)->assertSee('<strong>motifs sobres</strong>', false)->assertSee('<em>coupe droite</em>', false)->assertSee('<li>Une veste</li>', false)
            ->assertSee('<a href="https://exemple.ci/boutique" target="_blank" rel="noopener nofollow">notre boutique</a>', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false)->assertSee('<title>Comment porter le wax au bureau — Awa Mode</title>', false)->assertSee('À lire aussi');
        $this->assertSame(1, SitePost::withoutGlobalScopes()->find($p['id'])->views);
        $this->get('/site/boutique-awa-mode/blog')->assertOk()->assertSee('Comment porter le wax au bureau')->assertSee('Nos conseils pour un style élégant.');
        // Section « Derniers articles » à l'accueil (ajoutée par le client), lien Blog dans le menu, plan du site
        app()->instance('tenant', Site::withoutGlobalScopes()->find($site->id)->company);
        $api()->putJson('/api/sites/site', ['sections' => ['about', 'blog', 'contact']])->assertOk();
        $this->public();
        $this->get('/site/boutique-awa-mode')->assertSee('Derniers articles')->assertSee('/site/boutique-awa-mode/blog/'.$p['slug'], false)->assertSee('>Blog</a>', false);
        $this->get('/site/boutique-awa-mode/sitemap.xml')->assertOk()->assertSee('/site/boutique-awa-mode/blog/'.$p['slug'], false);
        // Domaine du client : boutique et blog servis à la racine
        Site::withoutGlobalScopes()->whereKey($site->id)->update(['custom_domain' => 'awamode.ci', 'domain_status' => 'active']);
        $this->get('http://awamode.ci/blog')->assertOk()->assertSee('href="/blog/'.$p['slug'].'"', false);
        $this->get('http://awamode.ci/blog/'.$p['slug'])->assertOk()->assertSee('Les bases');
        app('url')->forceRootUrl('http://localhost');
        $api()->deleteJson('/api/sites/posts/'.$p2['id'])->assertOk();
        $this->assertSame(1, SitePost::withoutGlobalScopes()->count());
        $this->assertCount(1, $api()->getJson('/api/sites/posts')->json('data'));
    }
}
