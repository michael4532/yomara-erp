<?php

namespace Tests\Feature;

use App\Modules\Etiquettes\Barcodes;
use Tests\TestCase;

class EtiquettesTest extends TestCase
{
    public function test_barcodes_plu_templates_prints_and_uniqueness(): void
    {
        [, , $token] = $this->makeCompany('Supermarché Bonprix');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['lb_templates']);
        $mk = fn (array $p) => $api()->postJson('/api/core/products', $p + ['sale_price' => 1000, 'tax_rate' => 18])->assertCreated()->json('data');
        $coca = $mk(['name' => 'Coca-Cola 33cl', 'barcode' => '5449000000996']);
        $riz = $mk(['name' => 'Riz brisé (vrac)', 'unit' => 'kg']);
        $savon = $mk(['name' => 'Savon']);

        // Clé EAN-13
        $this->assertTrue(Barcodes::isValidEan13('5449000000996'));
        $this->assertFalse(Barcodes::isValidEan13('5449000000997'));
        $this->assertSame('2100001012502', Barcodes::weight('00001', 1.25));

        // Codes internes pour les articles sans code-barres, PLU pour les articles au kilo
        $api()->postJson('/api/etiquettes/barcodes')->assertOk()->assertJson(['data' => ['barcodes' => 2, 'plu' => 1]]);
        $p = fn ($id) => $api()->getJson("/api/core/products/$id")->json('data');
        $this->assertSame('5449000000996', $p($coca['id'])['barcode']);
        $this->assertSame('2000000000015', $p($riz['id'])['barcode']);
        $this->assertSame('2000000000022', $p($savon['id'])['barcode']);
        $this->assertSame('00001', $p($riz['id'])['plu']);
        $api()->postJson('/api/etiquettes/barcodes')->assertOk()->assertJson(['data' => ['barcodes' => 0, 'plu' => 0]]);
        // Unicité
        $api()->putJson("/api/core/products/{$savon['id']}", ['barcode' => '5449000000996'])->assertStatus(422)->assertJsonFragment(['message' => 'Ce code-barres est déjà utilisé par un autre article.']);
        $api()->putJson("/api/core/products/{$savon['id']}", ['plu' => '00001'])->assertStatus(422);

        // Modèle personnalisé : contrôle du débordement
        $api()->postJson('/api/etiquettes/templates', ['name' => 'Trop grand', 'kind' => 'sheet', 'page_w' => 210, 'page_h' => 297, 'cols' => 4, 'rows' => 8, 'label_w' => 70, 'label_h' => 37])->assertStatus(422);
        $api()->postJson('/api/etiquettes/templates', ['name' => 'Rayon 3×8', 'kind' => 'sheet', 'style' => 'shelf', 'page_w' => 210, 'page_h' => 297, 'cols' => 3, 'rows' => 8, 'label_w' => 70, 'label_h' => 37, 'fields' => ['name', 'price', 'barcode']])->assertCreated();

        // Historique d'impression : une ligne par produit, total cumulé
        $api()->postJson('/api/etiquettes/prints', ['id' => $coca['id'], 'product_id' => $coca['id'], 'price' => 1180, 'count' => 24])->assertCreated();
        $r = $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'lb_prints', 'op' => 'upsert', 'record_id' => $coca['id'], 'data' => ['product_id' => $coca['id'], 'price' => 1200, 'count' => 6]]]])->json('results.0');
        $this->assertSame('applied', $r['status']);
        $this->assertSame([30, 1200.0], [$r['record']['total'], (float) $r['record']['price']]);
    }
}
