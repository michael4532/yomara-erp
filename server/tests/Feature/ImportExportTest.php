<?php

namespace Tests\Feature;

use App\Modules\ImportExport\ImportService;
use App\Modules\ImportExport\Sheet;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    protected function file(string $name, array $headers, array $rows): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid().'_'.$name;
        if (str_ends_with($name, '.csv')) {
            // CSV « Excel français » : point-virgule, Windows-1252
            $csv = implode("\r\n", array_map(fn ($r) => implode(';', $r), array_merge([$headers], $rows)));
            file_put_contents($path, mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));
        } else {
            Sheet::write($path, $headers, $rows);
        }

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_import_partners_products_stock_invoices_with_mapping_dry_run_errors_and_exports(): void
    {
        [, , $token] = $this->makeCompany('Quincaillerie du Port');
        $api = fn () => $this->api($token);
        $api()->postJson('/api/stock/warehouses', ['name' => 'Magasin Treichville', 'is_default' => true])->assertCreated();
        $ent = $api()->getJson('/api/import_export/entities')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['partners', 'products', 'stock', 'invoices'], array_column($ent['imports'], 'key'));
        $api()->get('/api/import_export/template/products')->assertOk()->assertDownload('modele_import_products.xlsx');

        // Valeurs « à la française »
        $this->assertSame(1500.5, ImportService::number('1 500,50'));
        $this->assertSame(1234567.0, ImportService::number('1.234.567'));
        $this->assertSame('2026-09-15', ImportService::date('15/09/2026'));
        $this->assertSame('2026-09-15', ImportService::date(46280));

        // 1) Clients en CSV Windows-1252 à point-virgule, en-têtes de l'ancien logiciel
        $f = $this->file('clients.csv', ['Raison sociale', 'Catégorie', 'Tél.', 'Commune', 'N° CC'], [
            ['Hôtel Ivoire', 'Client', '27 22 48 26 00', 'Cocody', '0012345K'], ['Société Générale Électricité', 'fournisseur', '', 'Plateau', ''],
            ['', 'client', '07 00', '', ''], ['Bâtiment & Co', 'les deux', '05 55 55 55', 'Yopougon', ''],
        ]);
        $a = $api()->post('/api/import_export/analyze', ['entity' => 'partners', 'file' => $f])->assertOk()->json('data');
        $this->assertSame(4, $a['rows']);
        $this->assertEquals(['name' => 0, 'type' => 1, 'phone' => 2, 'city' => 3, 'tax_id' => 4], $a['mapping']);
        $run = fn (int $job, array $o) => $api()->postJson("/api/import_export/jobs/$job/run", $o + ['mapping' => $a['mapping'], 'mode' => 'upsert', 'key' => 'name']);
        $dry = $run($a['job_id'], ['dry_run' => true])->assertOk()->json('data');
        $this->assertSame([3, 1, true], [$dry['created'], $dry['failed'], $dry['dry_run']]);
        $this->assertSame(['row' => 4, 'message' => 'Nom manquant.'], $dry['errors'][0]);
        $this->assertCount(0, $api()->getJson('/api/core/partners')->json('data')); // vérification à blanc : rien n'est enregistré
        $run($a['job_id'], ['atomic' => true])->assertOk()->assertJsonPath('data.rolled_back', true);
        $this->assertCount(0, $api()->getJson('/api/core/partners')->json('data'));
        $run($a['job_id'], [])->assertOk()->assertJsonPath('data.created', 3);
        $partners = collect($api()->getJson('/api/core/partners')->json('data'))->keyBy('name');
        $this->assertSame(['customer', 'supplier', 'both'], [$partners['Hôtel Ivoire']['type'], $partners['Société Générale Électricité']['type'], $partners['Bâtiment & Co']['type']]);
        $this->assertSame('0012345K', $partners['Hôtel Ivoire']['tax_id']);
        $api()->get("/api/import_export/jobs/{$a['job_id']}/errors")->assertOk();
        // Réimport : mise à jour, pas de doublon
        $run($a['job_id'], [])->assertOk()->assertJsonPath('data.updated', 3);
        $this->assertCount(3, $api()->getJson('/api/core/partners')->json('data'));

        // 2) Articles en Excel : prix TTC converti en HT, catégories créées, stock initial
        $f = $this->file('articles.xlsx', ['Code article', 'Libellé', 'Famille', 'PV TTC', 'PA', 'TVA', 'Qté'], [
            ['CIM50', 'Ciment 50 kg', 'Matériaux', '5 900', '4 800', '18%', 120], ['PEINT20', 'Peinture blanche 20 L', 'Peinture', 35400, 25000, 0.18, '15'],
            ['POSE', 'Pose carrelage (m²)', 'Services', 2000, '', 0, ''], ['CIM50', 'Doublon', '', 'abc', '', '', ''],
        ]);
        $a = $api()->post('/api/import_export/analyze', ['entity' => 'products', 'file' => $f])->assertOk()->json('data');
        $this->assertEquals(['sku' => 0, 'name' => 1, 'category' => 2, 'sale_price_ttc' => 3, 'purchase_price' => 4, 'tax_rate' => 5, 'stock' => 6], $a['mapping']);
        $r = $api()->postJson("/api/import_export/jobs/{$a['job_id']}/run", ['mapping' => $a['mapping'], 'mode' => 'upsert', 'key' => 'sku'])->assertOk()->json('data');
        $this->assertSame([3, 1], [$r['created'], $r['failed']]);
        $this->assertStringContainsString('n\'est pas un nombre', $r['errors'][0]['message']);
        $products = collect($api()->getJson('/api/core/products')->json('data'))->keyBy('sku');
        $this->assertEquals([5000, 18], [$products['CIM50']['sale_price'], $products['CIM50']['tax_rate']]);
        $this->assertEquals(30000, $products['PEINT20']['sale_price']);
        $stock = fn () => collect($api()->getJson('/api/stock/levels')->json('data'))->groupBy('product_id')->map->sum('quantity');
        $this->assertEquals([120, 15], [$stock()[$products['CIM50']['id']], $stock()[$products['PEINT20']['id']]]);

        // 3) Inventaire : quantités comptées → ajustements
        $f = $this->file('inventaire.xlsx', ['Référence', 'Quantité comptée'], [['CIM50', 112], ['PEINT20', 15], ['INCONNU', 3]]);
        $a = $api()->post('/api/import_export/analyze', ['entity' => 'stock', 'file' => $f])->assertOk()->json('data');
        $r = $api()->postJson("/api/import_export/jobs/{$a['job_id']}/run", ['mapping' => $a['mapping'], 'mode' => 'upsert', 'key' => 'sku'])->assertOk()->json('data');
        $this->assertSame([1, 1, 1], [$r['updated'], $r['skipped'], $r['failed']]);
        $this->assertEquals(112, $stock()[$products['CIM50']['id']]);

        // 4) Factures impayées de l'ancien logiciel
        $f = $this->file('impayes.xlsx', ['Client', 'N° facture', 'Date', 'Échéance', 'Montant TTC', 'Réglé'], [
            ['Hôtel Ivoire', 'F-2026-0812', '12/08/2026', '11/09/2026', '850 000', '350 000'], ['Nouveau client SARL', 'F-2026-0901', '01/09/2026', '', 120000, 0],
        ]);
        $a = $api()->post('/api/import_export/analyze', ['entity' => 'invoices', 'file' => $f])->assertOk()->json('data');
        $go = fn () => $api()->postJson("/api/import_export/jobs/{$a['job_id']}/run", ['mapping' => $a['mapping'], 'mode' => 'create', 'key' => 'reference'])->assertOk()->json('data');
        $this->assertSame(2, $go()['created']);
        $this->assertSame(2, $go()['skipped']); // déjà reprises
        $inv = collect($api()->getJson('/api/ventes/documents')->json('data'))->where('type', 'invoice')->keyBy('reference');
        $this->assertSame(['validated', 'unpaid', 500000.0], [$inv['F-2026-0812']['status'], $inv['F-2026-0812']['payment_status'], (float) $inv['F-2026-0812']['total']]);
        $this->assertSame('2026-08-12', $inv['F-2026-0812']['date']);
        $this->assertTrue($inv['F-2026-0812']['is_opening']);
        // Comptabilité : à-nouveaux 411 / 471, pas de chiffre d'affaires
        $rows = collect($api()->getJson('/api/compta/balance?to='.now()->addDay()->toDateString())->assertOk()->json('data.rows'))->keyBy('code');
        $this->assertEquals(620000, (float) $rows['411']['balance']);
        $this->assertEquals(-620000, (float) $rows['471']['balance']);
        $this->assertFalse($rows->keys()->contains(fn ($k) => str_starts_with($k, '70')));

        // Exports
        foreach (['partners', 'products', 'stock', 'stock_moves', 'sales', 'sales_lines', 'payments'] as $e) {
            $api()->get("/api/import_export/export/$e")->assertOk();
        }
        $res = $api()->get('/api/import_export/export/products');
        [$headers, $rows] = Sheet::read($res->baseResponse->getFile()->getPathname().'');
        $this->assertSame('Désignation', $headers[0]);
        $this->assertCount(3, $rows);
        $api()->get('/api/import_export/export/partners?format=csv')->assertOk();
    }

    public function test_reimporting_an_export_does_not_duplicate_items_without_reference(): void
    {
        [, , $token] = $this->makeCompany('Boutique Aller-Retour');
        $api = fn () => $this->api($token);
        $api()->postJson('/api/core/products', ['name' => 'Sac de riz', 'sale_price' => 15000, 'type' => 'product'])->assertCreated(); // sans référence ni code-barres
        $api()->postJson('/api/core/products', ['name' => 'Huile 1 L', 'sku' => 'HU1', 'sale_price' => 1500, 'type' => 'product'])->assertCreated();
        $api()->postJson('/api/core/partners', ['name' => 'Client sans code', 'type' => 'customer'])->assertCreated();
        // Stock réparti sur deux dépôts : l'export donne le total, le réimport ne doit pas le doubler
        $huile = collect($api()->getJson('/api/core/products')->json('data'))->firstWhere('sku', 'HU1');
        foreach (['Dépôt A' => 30, 'Dépôt B' => 12] as $wh => $q) {
            $w = $api()->postJson('/api/stock/warehouses', ['name' => $wh])->assertCreated()->json('data.id');
            $api()->postJson('/api/stock/moves', ['product_id' => $huile['id'], 'warehouse_id' => $w, 'quantity' => $q, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();
        }

        foreach (['products' => 'sku', 'partners' => 'code'] as $entity => $key) {
            $res = $api()->get("/api/import_export/export/$entity?format=xlsx")->assertOk();
            $path = sys_get_temp_dir().'/'.uniqid().'.xlsx';
            file_put_contents($path, $res->streamedContent());
            $a = $api()->post('/api/import_export/analyze', ['entity' => $entity, 'file' => new UploadedFile($path, "$entity.xlsx", null, null, true)])->assertOk()->json('data');
            $r = $api()->postJson("/api/import_export/jobs/{$a['job_id']}/run", ['mapping' => $a['mapping'], 'mode' => 'upsert', 'key' => $key])->assertOk()->json('data');
            $this->assertSame(0, $r['created'], $entity);
            $this->assertSame(0, $r['failed'], $entity.' '.json_encode($r['errors'] ?? []));
        }
        $this->assertCount(2, $api()->getJson('/api/core/products')->json('data'));
        $this->assertCount(1, $api()->getJson('/api/core/partners')->json('data'));
        $this->assertEquals(42, collect($api()->getJson('/api/stock/levels')->json('data'))->where('product_id', $huile['id'])->sum('quantity'));
    }

}
