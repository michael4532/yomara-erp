<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Product;
use App\Modules\Sites\Models\SiteOrder;
use App\Modules\Sites\Models\SiteReview;
use App\Modules\Sites\ShopService;
use App\Modules\Sites\SiteService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteProductPagesTest extends TestCase
{
    private function public(): void
    {
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        app('url')->forceRootUrl('http://localhost');
    }

    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Mode Akwaba');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        app()->instance('tenant', $company);
        $site = app(SiteService::class)->create($company, 'boutique-eclat', 'Akwaba');
        app(SiteService::class)->publish($site, true);
        $api()->putJson('/api/sites/shop/settings', ['enabled' => true, 'pickup' => true, 'payment_cod' => true, 'payment_online' => false])->assertOk();

        $push('product_categories', ['name' => 'Vêtements'], $vet = $this->uuid());
        $push('product_categories', ['name' => 'Accessoires'], $acc = $this->uuid());
        $tpl = $this->uuid();
        $push('products', ['name' => 'T-shirt Yomara', 'sku' => 'TSY', 'type' => 'product', 'sale_price' => 5000, 'tax_rate' => 0, 'category_id' => $vet, 'is_template' => true,
            'description' => 'Le t-shirt du quotidien.', 'variant_attributes' => [['name' => 'Taille', 'values' => ['S', 'M', 'XL']], ['name' => 'Couleur', 'values' => ['Blanc', 'Noir']]],
            'price_extras' => ['Taille' => ['XL' => 1000]]], $tpl);
        $vars = collect($api()->postJson("/api/variantes/templates/$tpl/generate")->assertCreated()->json('data'))->keyBy(fn ($v) => $v['variant_values']['Taille'].'/'.$v['variant_values']['Couleur']);
        $wh = $api()->postJson('/api/stock/warehouses', ['name' => 'Boutique', 'is_default' => true])->json('data.id');
        $push('stock_moves', ['product_id' => $vars['S/Blanc']['id'], 'warehouse_id' => $wh, 'quantity' => 3, 'type' => 'adjustment', 'date' => now()->toDateString()]);
        $push('stock_moves', ['product_id' => $vars['XL/Noir']['id'], 'warehouse_id' => $wh, 'quantity' => 2, 'type' => 'adjustment', 'date' => now()->toDateString()]);
        $push('products', ['name' => 'Casquette brodée', 'sale_price' => 3000, 'tax_rate' => 0, 'type' => 'product', 'category_id' => $vet, 'track_stock' => false], $cap = $this->uuid());
        $push('products', ['name' => 'Sac en raphia', 'sale_price' => 8000, 'tax_rate' => 0, 'type' => 'product', 'category_id' => $acc, 'track_stock' => false], $sac = $this->uuid());
        $push('products', ['name' => 'Bracelet perles', 'sale_price' => 2000, 'tax_rate' => 0, 'type' => 'product', 'category_id' => $acc, 'track_stock' => false], $brac = $this->uuid());
        $push('products', ['name' => 'Robe sans variante', 'sale_price' => 9000, 'tax_rate' => 0, 'type' => 'product', 'is_template' => true,
            'variant_attributes' => [['name' => 'Taille', 'values' => ['S']]]], $this->uuid());

        return compact('company', 'api', 'push', 'site', 'tpl', 'vars', 'cap', 'sac', 'brac');
    }

    public function test_catalog_groups_variants_and_product_page_selects_them(): void
    {
        ['api' => $api, 'site' => $site, 'tpl' => $tpl, 'vars' => $vars, 'cap' => $cap, 'sac' => $sac, 'brac' => $brac] = $this->env();

        // Catalogue : une carte par modèle, prix « à partir de », variantes regroupées ; modèle sans variante masqué
        $cat = collect(app(ShopService::class)->catalog($site));
        $this->assertSame(['Bracelet perles', 'Casquette brodée', 'Sac en raphia', 'T-shirt Yomara'], $cat->pluck('name')->all());
        $card = $cat->firstWhere('id', $tpl);
        $this->assertTrue($card['is_template']);
        $this->assertTrue($card['price_from']);
        $this->assertEquals(5000, $card['price']);
        $this->assertCount(6, $card['variants']);
        $this->assertEquals(5, $card['stock']);
        $this->assertEquals(6000, collect($card['variants'])->firstWhere('id', $vars['XL/Noir']['id'])['price']);
        $this->assertCount(9, ShopService::buyables($cat->all())); // 3 produits simples + 6 variantes

        $this->public();
        $this->get('/site/mode-akwaba/boutique')->assertOk()->assertSee('À partir de')->assertSee('3 tailles')->assertSee('2 couleurs')->assertSee('Choisir')
            ->assertSee("/site/mode-akwaba/produit/$tpl/t-shirt-yomara", false)->assertDontSee('T-shirt Yomara — S / Blanc')->assertDontSee('Robe sans variante');

        // Fiche produit : sélecteur de variantes, données structurées, produits liés (même catégorie d'abord)
        $page = $this->get("/site/mode-akwaba/produit/$tpl/t-shirt-yomara")->assertOk();
        $page->assertSee('<h1>T-shirt Yomara</h1>', false)->assertSee('data-v="XL"', false)->assertSee('Couleur')->assertSee('"@type":"Product"', false)
            ->assertSee('Vous aimerez aussi')->assertSee('Casquette brodée')->assertSee('Le t-shirt du quotidien.')->assertSee('rel="canonical"', false);
        $this->assertStringContainsString('"Taille":"S","Couleur":"Blanc"', $page->getContent()); // première variante en stock présélectionnée
        $this->get('/site/mode-akwaba/produit/'.$vars['XL/Noir']['id'])->assertOk()->assertSee('FIRST={"Taille":"XL","Couleur":"Noir"}', false);
        $this->get("/site/mode-akwaba/produit/$cap/casquette-brodee")->assertOk()->assertSee('Ajouter au panier');
        $this->get('/site/mode-akwaba/produit/'.$this->uuid())->assertNotFound();
        $this->get('/site/mode-akwaba/sitemap.xml')->assertOk()->assertSee("/produit/$tpl/t-shirt-yomara", false);

        // Commande : le modèle n'est pas vendable, chaque variante a son prix et son stock
        $cart = fn (array $items) => json_encode(array_map(fn ($id, $q) => ['id' => $id, 'quantity' => $q], array_keys($items), $items));
        $base = ['name' => 'Fatou Diallo', 'phone' => '05 44 33 22 11', 'delivery' => 'pickup', 'payment' => 'cod'];
        $this->post('/site/mode-akwaba/commande', $base + ['cart' => $cart([$tpl => 1])])->assertRedirectContains('plus+disponible');
        $this->post('/site/mode-akwaba/commande', $base + ['cart' => $cart([$vars['M/Noir']['id'] => 1])])->assertRedirectContains('rupture');
        $this->post('/site/mode-akwaba/commande', $base + ['cart' => $cart([$vars['XL/Noir']['id'] => 3])])->assertRedirectContains('plus+que+2');
        $this->post('/site/mode-akwaba/commande', $base + ['cart' => $cart([$vars['XL/Noir']['id'] => 2, $vars['S/Blanc']['id'] => 1])])->assertRedirectContains('/commande/');
        $o = SiteOrder::withoutGlobalScopes()->latest('id')->first();
        $this->assertEquals(17000, $o->total);
        $this->assertSame(['T-shirt Yomara — XL / Noir', 'T-shirt Yomara — S / Blanc'], array_column($o->items, 'name'));

        // Avis : la note d'une variante compte pour sa fiche
        SiteReview::withoutGlobalScopes()->create(['company_id' => $site->company_id, 'site_id' => $site->id, 'order_id' => $o->id, 'name' => 'Fatou D.', 'rating' => 5,
            'comment' => 'Très bonne qualité, taille parfaite.', 'products' => [$vars['XL/Noir']['id'] => 4], 'status' => 'published']);
        $this->assertEquals(4, app(ShopService::class)->ratings($site)['products'][$tpl]['average']);
        $this->get("/site/mode-akwaba/produit/$tpl")->assertOk()->assertSee('1 avis')->assertSee('Très bonne qualité, taille parfaite.')->assertSee('Avis clients');
    }

    public function test_erp_manages_product_pages_photos_and_related_products(): void
    {
        Storage::fake('public');
        ['api' => $api, 'site' => $site, 'tpl' => $tpl, 'vars' => $vars, 'cap' => $cap, 'sac' => $sac, 'brac' => $brac] = $this->env();

        $list = collect($api()->getJson('/api/sites/shop/products')->assertOk()->json('data'));
        $this->assertCount(4, $list);
        $row = $list->firstWhere('id', $tpl);
        $this->assertSame(6, $row['variants']);
        $this->assertSame(['S', 'M', 'XL', 'Blanc', 'Noir'], $row['attribute_values']);
        $this->assertStringEndsWith("/produit/$tpl/t-shirt-yomara", $row['url']);

        // Fiche : description longue, badge, prix barré, produits liés (variantes et inconnus ignorés)
        $r = $api()->postJson("/api/sites/shop/products/$tpl", ['description' => "## Matière\n100 % coton bio, tissé à Abidjan.", 'badge' => 'Nouveau', 'compare_at' => 7500,
            'related' => [$sac, $vars['S/Blanc']['id'], $brac, $this->uuid(), $tpl]])->assertOk()->json('data');
        $this->assertSame([$sac, $brac], $r['page']['related']);
        $this->assertSame('Nouveau', $r['page']['badge']);
        $api()->postJson('/api/sites/shop/products/'.$vars['S/Blanc']['id'], ['badge' => 'Promo'])->assertStatus(422);

        // Photos : ajout avec étiquette de couleur, ordre, suppression ; 10 au plus
        $a = $api()->post("/api/sites/shop/products/$tpl/photos", ['file' => UploadedFile::fake()->image('blanc.jpg', 800, 800), 'tag' => 'Blanc'])->assertOk()->json('data.page.photos');
        $b = $api()->post("/api/sites/shop/products/$tpl/photos", ['file' => UploadedFile::fake()->image('noir.jpg', 800, 800), 'tag' => 'Noir'])->assertOk()->json('data.page.photos');
        $this->assertCount(2, $b);
        $this->assertSame('Noir', $b[1]['tag']);
        Storage::disk('public')->assertExists($b[1]['path']);
        $api()->post("/api/sites/shop/products/$tpl/photos", ['file' => UploadedFile::fake()->create('doc.pdf', 10)])->assertStatus(422);
        $o = $api()->postJson("/api/sites/shop/products/$tpl", ['photo_order' => [$b[1]['path'], $b[0]['path']], 'photo_tags' => [$b[0]['path'] => '']])->assertOk()->json('data.page.photos');
        $this->assertSame([$b[1]['path'], $b[0]['path']], array_column($o, 'path'));
        $this->assertNull($o[1]['tag']);

        $this->public();
        $page = $this->get("/site/mode-akwaba/produit/$tpl")->assertOk();
        $page->assertSee('<h2>Matière</h2>', false)->assertSee('100 % coton bio')->assertSee('Nouveau')->assertSee('7 500 F CFA')->assertSee(basename($b[1]['path']));
        $rel = substr($page->getContent(), strpos($page->getContent(), 'Vous aimerez aussi'));
        $this->assertTrue(strpos($rel, 'Sac en raphia') < strpos($rel, 'Bracelet perles') && strpos($rel, 'Bracelet perles') < strpos($rel, 'Casquette brodée'));
        $this->get('/site/mode-akwaba/boutique')->assertOk()->assertSee('Nouveau')->assertSee('7 500 F CFA');

        $this->flushHeaders();
        $api()->postJson("/api/sites/shop/products/$tpl/photos/remove", ['path' => $b[1]['path']])->assertOk()->assertJsonCount(1, 'data.page.photos');
        Storage::disk('public')->assertMissing($b[1]['path']);
        // Sans badge manuel, la réduction s'affiche en pourcentage
        $api()->postJson("/api/sites/shop/products/$cap", ['compare_at' => 4000])->assertOk();
        $this->assertSame('−25 %', collect(app(ShopService::class)->catalog($site))->firstWhere('id', $cap)['badge']);
    }
}
