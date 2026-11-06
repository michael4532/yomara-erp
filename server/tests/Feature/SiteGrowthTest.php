<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\AppNotification;
use App\Modules\Sites\Models\SiteCart;
use App\Modules\Sites\Models\SiteCoupon;
use App\Modules\Sites\Models\SiteOrder;
use App\Modules\Sites\Models\SiteReview;
use App\Modules\Sites\SiteService;
use App\Modules\Ventes\Models\SalesDocument;
use Carbon\Carbon;
use Illuminate\Mail\Transport\ArrayTransport;
use Tests\TestCase;

class SiteGrowthTest extends TestCase
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
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function public(): void
    {
        $this->flushHeaders();
        app()->forgetInstance('tenant');
    }

    private function env(): array
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        [$company, $admin, $token] = $this->makeCompany('Cosmétiques Nina');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        app()->instance('tenant', $company);
        $site = app(SiteService::class)->create($company, 'beaute-eclat', 'Nina');
        app(SiteService::class)->publish($site, true);
        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.n.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'n@n.ci', 'password' => 'x', 'from_email' => 'n@n.ci', 'from_name' => 'Nina',
        ]])->assertOk();
        $api()->putJson('/api/sites/shop/settings', ['enabled' => true, 'pickup' => true, 'zones' => [['name' => 'Abidjan', 'fee' => 2000]], 'payment_cod' => true, 'payment_online' => false])->assertOk();
        $savon = $this->uuid();
        $push('products', ['name' => 'Savon au karité', 'sale_price' => 2500, 'tax_rate' => 0, 'type' => 'product', 'track_stock' => false], $savon);
        $huile = $this->uuid();
        $push('products', ['name' => 'Huile de coco 250 ml', 'sale_price' => 5000, 'tax_rate' => 0, 'type' => 'product', 'track_stock' => false], $huile);

        return compact('company', 'admin', 'api', 'site', 'savon', 'huile');
    }

    private function cart(array $items): string
    {
        return json_encode(array_map(fn ($id, $q) => ['id' => $id, 'quantity' => $q], array_keys($items), $items));
    }

    public function test_promo_codes_percent_amount_free_shipping_limits_and_once_per_customer(): void
    {
        ['api' => $api, 'savon' => $savon, 'huile' => $huile] = $this->env();
        $api()->postJson('/api/sites/coupons', ['code' => 'x', 'type' => 'percent', 'value' => 10])->assertStatus(422);
        $api()->postJson('/api/sites/coupons', ['code' => 'TROP', 'type' => 'percent', 'value' => 150])->assertStatus(422);
        $c = $api()->postJson('/api/sites/coupons', ['code' => 'bienvenue', 'type' => 'percent', 'value' => 10, 'min_order' => 10000, 'once_per_customer' => true, 'max_uses' => 2, 'description' => 'Première commande'])->assertCreated()->json('data');
        $this->assertSame('BIENVENUE', $c['code']);
        $api()->postJson('/api/sites/coupons', ['code' => 'BIENVENUE', 'type' => 'amount', 'value' => 1000])->assertStatus(422)->assertJsonPath('message', 'Ce code existe déjà.');
        $api()->postJson('/api/sites/coupons', ['code' => 'LIVRAISON', 'type' => 'shipping'])->assertCreated();
        $api()->postJson('/api/sites/coupons', ['code' => 'MOINS3000', 'type' => 'amount', 'value' => 3000])->assertCreated();
        $api()->postJson('/api/sites/coupons', ['code' => 'NOEL', 'type' => 'percent', 'value' => 20, 'starts_on' => '2026-12-20', 'ends_on' => '2026-12-26'])->assertCreated();

        // Vérification en direct dans la boutique
        $this->public();
        $this->get('/site/cosmetiques-nina/boutique')->assertOk()->assertSee('Code promo');
        $this->getJson('/site/cosmetiques-nina/coupon?code=bienvenue&total=5000')->assertStatus(422)->assertJsonPath('message', 'Code « BIENVENUE » valable dès 10 000 F d\'achat.');
        $this->getJson('/site/cosmetiques-nina/coupon?code=BIENVENUE&total=20000')->assertOk()->assertJsonPath('discount', 2000)->assertJsonPath('label', '-10 %');
        $this->getJson('/site/cosmetiques-nina/coupon?code=NOEL&total=20000')->assertStatus(422)->assertJsonPath('message', 'Code promo « NOEL » invalide ou expiré.');
        $this->getJson('/site/cosmetiques-nina/coupon?code=LIVRAISON&total=1000')->assertOk()->assertJsonPath('free_shipping', true);

        // Commande avec -10 % : remise en pourcentage sur les lignes du document de vente
        $base = ['name' => 'Aya Koffi', 'phone' => '07 11 22 33 44', 'email' => 'aya@exemple.ci', 'delivery' => 'Abidjan', 'address' => 'Cocody', 'payment' => 'cod'];
        $this->post('/site/cosmetiques-nina/commande', $base + ['coupon' => 'bienvenue', 'cart' => $this->cart([$savon => 2, $huile => 2])])->assertRedirectContains('nouveau=1');
        $o = SiteOrder::withoutGlobalScopes()->latest('id')->first();
        $this->assertEquals(['BIENVENUE', 1500, 15000, 2000, 15500], [$o->coupon_code, $o->discount, $o->subtotal, $o->delivery_fee, $o->total]);
        $doc = SalesDocument::withoutGlobalScopes()->with('lines')->find($o->document_id);
        $this->assertEquals([10, 10, 0], $doc->lines->pluck('discount_percent')->map(fn ($v) => (float) $v)->all());
        $this->assertStringContainsString('code promo BIENVENUE', $doc->notes);
        $this->assertSame(1, SiteCoupon::withoutGlobalScopes()->where('code', 'BIENVENUE')->value('uses'));
        $this->get('/site/cosmetiques-nina/commande/'.$o->token)->assertSee('Code promo BIENVENUE')->assertSee('−1 500');
        // Une fois par client (même téléphone), quota atteint, code invalide
        $this->post('/site/cosmetiques-nina/commande', array_merge($base, ['email' => null, 'phone' => '+225 0711223344', 'coupon' => 'BIENVENUE', 'cart' => $this->cart([$huile => 3])]))->assertRedirectContains('d%C3%A9j%C3%A0+utilis%C3%A9');
        $this->post('/site/cosmetiques-nina/commande', array_merge($base, ['phone' => '0566778899', 'email' => 'b@exemple.ci', 'coupon' => 'BIENVENUE', 'cart' => $this->cart([$huile => 3])]))->assertRedirectContains('nouveau=1');
        $this->post('/site/cosmetiques-nina/commande', array_merge($base, ['phone' => '0566778800', 'email' => 'c@exemple.ci', 'coupon' => 'BIENVENUE', 'cart' => $this->cart([$huile => 3])]))->assertRedirectContains('puis%C3%A9');
        $this->post('/site/cosmetiques-nina/commande', array_merge($base, ['coupon' => 'FAUX', 'cart' => $this->cart([$huile => 1])]))->assertRedirectContains('invalide');
        // Montant fixe et livraison offerte
        $this->post('/site/cosmetiques-nina/commande', array_merge($base, ['phone' => '0101010101', 'coupon' => 'moins3000', 'cart' => $this->cart([$huile => 2])]))->assertRedirectContains('nouveau=1');
        $this->assertEquals([3000, 9000], [SiteOrder::withoutGlobalScopes()->latest('id')->first()->discount, SiteOrder::withoutGlobalScopes()->latest('id')->first()->total]);
        $this->post('/site/cosmetiques-nina/commande', array_merge($base, ['phone' => '0202020202', 'coupon' => 'LIVRAISON', 'cart' => $this->cart([$savon => 1])]))->assertRedirectContains('nouveau=1');
        $this->assertEquals([0, 0, 2500, 'LIVRAISON'], [SiteOrder::withoutGlobalScopes()->latest('id')->first()->delivery_fee, SiteOrder::withoutGlobalScopes()->latest('id')->first()->discount, SiteOrder::withoutGlobalScopes()->latest('id')->first()->total, SiteOrder::withoutGlobalScopes()->latest('id')->first()->coupon_code]);
        // Suivi dans l'ERP : utilisations et chiffre d'affaires par code
        $coupons = collect($api()->getJson('/api/sites/shop')->assertOk()->json('data.coupons'))->keyBy('code');
        $this->assertEquals([2, 3000], [$coupons['BIENVENUE']['uses'], $coupons['BIENVENUE']['discounts']]);
        $api()->postJson('/api/sites/coupons/'.$coupons['NOEL']['id'], ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $api()->deleteJson('/api/sites/coupons/'.$coupons['NOEL']['id'])->assertOk();
    }

    public function test_abandoned_cart_saved_reminded_restored_and_recovered(): void
    {
        ['api' => $api, 'savon' => $savon, 'huile' => $huile] = $this->env();
        $this->public();
        $this->postJson('/site/cosmetiques-nina/panier', ['phone' => '12', 'items' => [['id' => $savon, 'quantity' => 1]]])->assertStatus(422);
        $t = $this->postJson('/site/cosmetiques-nina/panier', ['name' => 'Mariam', 'phone' => '07 99 88 77 66', 'email' => 'mariam@exemple.ci', 'items' => [['id' => $savon, 'quantity' => 2], ['id' => $huile, 'quantity' => 1]]])->assertOk()->json('token');
        $t2 = $this->postJson('/site/cosmetiques-nina/panier', ['token' => $t, 'phone' => '07 99 88 77 66', 'email' => 'mariam@exemple.ci', 'items' => [['id' => $savon, 'quantity' => 3], ['id' => $huile, 'quantity' => 1]]])->json('token');
        $this->assertSame($t, $t2); // même panier mis à jour
        $t3 = $this->postJson('/site/cosmetiques-nina/panier', ['phone' => '+225 0799887766', 'items' => [['id' => $savon, 'quantity' => 3], ['id' => $huile, 'quantity' => 1]]])->json('token');
        $this->assertNull($t3); // autre navigateur, même client : pas de doublon, et le jeton du panier existant n'est pas divulgué
        $this->postJson('/site/cosmetiques-nina/panier', ['phone' => '0799887766', 'items' => [['id' => $savon, 'quantity' => 1]]])->assertOk()->assertJsonPath('token', null)->assertJsonMissing(['name' => 'Mariam']);
        $this->postJson('/site/cosmetiques-nina/panier', ['phone' => '+225 0799887766', 'items' => [['id' => $savon, 'quantity' => 3], ['id' => $huile, 'quantity' => 1]]])->assertOk();
        $this->assertSame(1, SiteCart::withoutGlobalScopes()->count());
        $cart = SiteCart::withoutGlobalScopes()->first();
        $this->assertEquals([12500, 'Mariam'], [$cart->total, $cart->name]);
        // Rien avant le délai (2 h), puis une seule relance par e-mail
        $this->artisan('erp:shop-followups')->assertSuccessful();
        $this->assertNull($cart->fresh()->reminded_at);
        Carbon::setTestNow('2026-11-03 12:30:00');
        $this->artisan('erp:shop-followups')->assertSuccessful();
        $this->artisan('erp:shop-followups')->assertSuccessful();
        $mails = collect($this->mails->messages()->values())->map->getOriginalMessage()->filter(fn ($m) => str_starts_with($m->getSubject(), 'Votre panier vous attend'));
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('/site/cosmetiques-nina/boutique?panier='.$t, $mails->first()->getHtmlBody());
        $this->assertStringContainsString('3 × Savon au karité', $mails->first()->getHtmlBody());
        $this->assertSame('email', $cart->fresh()->reminder_channel);
        // Lien de relance : panier et coordonnées restaurés
        $this->public();
        $this->get('/site/cosmetiques-nina/boutique?panier='.$t)->assertOk()->assertSee('"token":"'.$t.'"', false)->assertSee('"phone":"+225 0799887766"', false);
        // Commande passée : panier rattaché, compté comme récupéré
        $this->post('/site/cosmetiques-nina/commande', ['name' => 'Mariam', 'phone' => '0799887766', 'delivery' => 'pickup', 'payment' => 'cod', 'cart_token' => $t, 'cart' => $this->cart([$savon => 3, $huile => 1])])->assertRedirectContains('nouveau=1');
        $cart->refresh();
        $this->assertNotNull($cart->order_id);
        $this->assertNotNull($cart->recovered_at);
        $carts = $api()->getJson('/api/sites/shop')->json('data.carts');
        $this->assertSame('recovered', $carts[0]['state']);
        // Panier sans e-mail et sans WhatsApp configuré : non relancé automatiquement, lien WhatsApp pour une relance manuelle
        $this->public();
        $this->postJson('/site/cosmetiques-nina/panier', ['phone' => '05 12 12 12 12', 'items' => [['id' => $huile, 'quantity' => 1]]])->assertOk();
        Carbon::setTestNow('2026-11-03 16:00:00');
        $this->artisan('erp:shop-followups')->assertSuccessful();
        $c2 = collect($api()->getJson('/api/sites/shop')->json('data.carts'))->firstWhere('phone', '05 12 12 12 12');
        $this->assertEquals(['abandoned', true], [$c2['state'], str_contains($c2['whatsapp_url'], 'wa.me/2250512121212')]);
    }

    public function test_verified_reviews_requested_after_delivery_moderated_and_displayed(): void
    {
        ['api' => $api, 'admin' => $admin, 'savon' => $savon, 'huile' => $huile] = $this->env();
        $this->public();
        $this->post('/site/cosmetiques-nina/commande', ['name' => 'Grâce Assi', 'phone' => '0707070707', 'email' => 'grace@exemple.ci', 'delivery' => 'pickup', 'payment' => 'cod', 'cart' => $this->cart([$savon => 1, $huile => 1])]);
        $o = SiteOrder::withoutGlobalScopes()->latest('id')->first();
        // Pas d'avis avant la livraison
        $this->get('/site/cosmetiques-nina/avis/'.$o->token)->assertOk()->assertSee('une fois la commande livrée');
        $this->post('/site/cosmetiques-nina/avis/'.$o->token, ['rating' => 5])->assertRedirectContains('erreur=');
        $api()->postJson('/api/sites/orders/'.$o->id.'/status', ['status' => 'delivered', 'paid' => true])->assertOk();
        $this->public();
        $this->get('/site/cosmetiques-nina/commande/'.$o->token)->assertSee('Donner mon avis');
        // Demande d'avis le lendemain, une seule fois
        $this->artisan('erp:shop-followups')->assertSuccessful();
        Carbon::setTestNow('2026-11-04 11:00:00');
        $this->artisan('erp:shop-followups')->assertSuccessful();
        $this->artisan('erp:shop-followups')->assertSuccessful();
        $asks = collect($this->mails->messages()->values())->map->getOriginalMessage()->filter(fn ($m) => str_starts_with($m->getSubject(), 'Votre avis sur votre commande'));
        $this->assertCount(1, $asks);
        $this->assertStringContainsString('/site/cosmetiques-nina/avis/'.$o->token.'?note=5', $asks->first()->getHtmlBody());
        // Avis : note globale et par article, publié directement
        $this->public();
        $this->get('/site/cosmetiques-nina/avis/'.$o->token.'?note=4')->assertOk()->assertSee('Savon au karité')->assertSee('id="r4" name="rating" value="4" checked', false);
        $this->post('/site/cosmetiques-nina/avis/'.$o->token, ['rating' => 5, 'comment' => 'Produits naturels, très bonne odeur !', 'name' => 'Grâce A.', 'products' => [$savon => 5, $huile => 4, $this->uuid() => 1]])->assertRedirectContains('merci=1');
        $rv = SiteReview::withoutGlobalScopes()->first();
        $this->assertEquals(['published', 5, [$savon => 5, $huile => 4], 'Grâce A.'], [$rv->status, $rv->rating, $rv->products, $rv->name]);
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Nouvel avis%')->count());
        $this->post('/site/cosmetiques-nina/avis/'.$o->token, ['rating' => 4, 'comment' => 'Produits naturels, très bonne odeur !'])->assertRedirect();
        $this->assertSame(1, SiteReview::withoutGlobalScopes()->count()); // modifié, pas de doublon
        $this->assertSame(4, SiteReview::withoutGlobalScopes()->first()->rating);
        // Affichage : note de la boutique, étoiles des produits, témoignage « Achat vérifié » à l'accueil
        $this->get('/site/cosmetiques-nina/boutique')->assertSee('4,0/5 · 1 avis vérifiés')->assertSee('5,0 (1)');
        $this->get('/site/cosmetiques-nina')->assertSee('Produits naturels, très bonne odeur !')->assertSee('Achat vérifié');
        // Modération dans l'ERP : réponse publique, masquage
        $rid = $api()->getJson('/api/sites/shop')->json('data.reviews.0.id');
        $api()->postJson('/api/sites/reviews/'.$rid, ['reply' => 'Merci Grâce, à bientôt !'])->assertOk()->assertJsonPath('data.reply', 'Merci Grâce, à bientôt !');
        $this->public();
        $this->get('/site/cosmetiques-nina')->assertSee('Merci Grâce, à bientôt !');
        $api()->postJson('/api/sites/reviews/'.$rid, ['status' => 'hidden'])->assertOk();
        $this->public();
        $this->get('/site/cosmetiques-nina/boutique')->assertDontSee('avis vérifiés');
        // Publication après vérification
        $api()->putJson('/api/sites/shop/settings', ['reviews_auto_publish' => false])->assertOk();
        $this->public();
        $this->post('/site/cosmetiques-nina/commande', ['name' => 'Eric', 'phone' => '0505050505', 'delivery' => 'pickup', 'payment' => 'cod', 'cart' => $this->cart([$savon => 1])]);
        $o2 = SiteOrder::withoutGlobalScopes()->latest('id')->first();
        $api()->postJson('/api/sites/orders/'.$o2->id.'/status', ['status' => 'delivered'])->assertOk();
        $this->public();
        $this->post('/site/cosmetiques-nina/avis/'.$o2->token, ['rating' => 2, 'comment' => 'Livraison lente'])->assertRedirect();
        $this->assertSame('pending', SiteReview::withoutGlobalScopes()->where('order_id', $o2->id)->value('status'));
        $this->get('/site/cosmetiques-nina/avis/'.$o2->token.'?merci=1')->assertSee('sera publié après vérification');
    }
}
