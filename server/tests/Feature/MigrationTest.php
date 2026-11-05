<?php

namespace Tests\Feature;

use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Migration depuis Dolibarr, Odoo, ERPNext et fichiers d'export (Zoho, Sage…) : tiers, articles, stock, factures, règlements, relance sans doublon. */
class MigrationTest extends TestCase
{
    protected function dolibarr(): void
    {
        $paid = ['LIQ' => 0];
        Http::fake(function ($req) use (&$paid) {
            $u = $req->url();
            if ($req->header('DOLAPIKEY')[0] ?? null) {
                if ($req->header('DOLAPIKEY')[0] !== 'cle-ok') {
                    return Http::response(['error' => ['code' => 401]], 401);
                }
            }
            $page = (int) ($req->data()['page'] ?? 0);
            $list = fn (array $rows) => $page > 0 ? Http::response(['error' => ['code' => 404]], 404) : Http::response($rows);

            return match (true) {
                str_contains($u, '/api/index.php/status') => Http::response(['success' => ['code' => 200, 'dolibarr_version' => '22.0.4']]),
                str_contains($u, '/setup/company') => Http::response(['name' => 'Sodiket']),
                str_contains($u, '/thirdparties') => $list([
                    ['id' => '1', 'name' => 'Anthony Loison EI', 'client' => '1', 'fournisseur' => '0', 'code_client' => 'CU2610-0001', 'email' => 'anthony@exemple.fr', 'phone' => '+33 6 00 00 00 00', 'town' => 'Lyon', 'tva_intra' => 'FR12345678901'],
                    ['id' => '2', 'name' => 'OVH Cloud', 'client' => '0', 'fournisseur' => '1', 'email' => 'facturation@ovh.example'],
                ]),
                str_contains($u, '/products') => $list([
                    ['id' => '7', 'ref' => 'CM', 'label' => 'Community Manager', 'type' => '1', 'price' => '46.61017', 'tva_tx' => '18.000', 'status' => '1'],
                    ['id' => '8', 'ref' => 'CAM-01', 'label' => 'Caméra 4K', 'type' => '0', 'price' => '300', 'cost_price' => '210', 'tva_tx' => '18', 'stock_reel' => '4', 'barcode' => '3700000000017'],
                ]),
                str_contains($u, '/invoices/31/payments') => Http::response([['amount' => '55.00000000', 'type' => 'VIR', 'date' => '2026-10-28 00:00:00', 'ref' => 'PAY2610-0027']]),
                str_contains($u, '/invoices/32/payments') => Http::response([['amount' => '100', 'type' => 'LIQ', 'date' => '2026-10-20 00:00:00']]),
                str_contains($u, '/payments') => Http::response([]),
                str_contains($u, '/supplierinvoices') => $list([
                    ['id' => '5', 'ref' => 'SI2610-0001', 'ref_supplier' => 'OVH-998', 'socid' => '2', 'date' => 1790000000, 'type' => '0', 'status' => '1', 'total_ttc' => '23.6',
                        'lines' => [['description' => 'Hébergement VPS', 'qty' => '1', 'subprice' => '20', 'tva_tx' => '18']]],
                ]),
                str_contains($u, '/invoices') => $list([
                    ['id' => '31', 'ref' => 'IN2610-0029', 'socid' => '1', 'date' => 1793145600, 'date_lim_reglement' => 1793232000, 'type' => '0', 'status' => '2', 'total_ttc' => '55.00',
                        'lines' => [['fk_product' => '7', 'product_label' => 'Community Manager', 'desc' => 'Community Manager', 'qty' => '1', 'subprice' => '46.61017', 'tva_tx' => '18', 'remise_percent' => '0']]],
                    ['id' => '32', 'ref' => 'IN2610-0030', 'socid' => '1', 'date' => 1792540800, 'type' => '0', 'status' => '1', 'total_ttc' => '354',
                        'lines' => [['fk_product' => '8', 'product_label' => 'Caméra 4K', 'qty' => '1', 'subprice' => '300', 'tva_tx' => '18']]],
                    ['id' => '33', 'ref' => 'AV2610-0001', 'socid' => '1', 'date' => 1792540800, 'type' => '2', 'status' => '1', 'total_ttc' => '-11.8',
                        'lines' => [['desc' => 'Geste commercial', 'qty' => '1', 'subprice' => '-10', 'tva_tx' => '18']]],
                    ['id' => '34', 'ref' => '(PROV34)', 'socid' => '1', 'date' => 1792540800, 'type' => '0', 'status' => '3', 'total_ttc' => '10', 'lines' => []],
                ]),
                default => Http::response(['error' => 'not faked: '.$u], 500),
            };
        });
    }

    public function test_dolibarr_migration_with_currency_conversion_and_rerun_without_duplicates(): void
    {
        [$company, , $token] = $this->makeCompany('Sodiket CI');
        $api = fn () => $this->api($token);
        $this->dolibarr();
        $idx = $api()->getJson('/api/migration')->assertOk()->json();
        $this->assertSame(['dolibarr', 'odoo', 'erpnext'], array_column($idx['sources'], 'key'));
        $this->assertGreaterThanOrEqual(10, count($idx['vendors']));

        $api()->postJson('/api/migration/connect', ['source' => 'dolibarr', 'url' => 'https://erp.sodiket.example', 'api_key' => 'mauvaise'])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Dolibarr refuse la clé d\'API (vérifiez la clé et les droits de l\'utilisateur).']);
        $job = $api()->postJson('/api/migration/connect', ['source' => 'dolibarr', 'url' => 'https://erp.sodiket.example/', 'api_key' => 'cle-ok'])->assertOk()->json();
        $this->assertSame('Sodiket 22.0.4', $job['label']);
        $this->assertEquals(['partners' => 2, 'products' => 2, 'sales' => 4, 'purchases' => 1], $job['probe']['counts']);
        $this->assertArrayNotHasKey('secret', $job);
        $this->assertStringNotContainsString('cle-ok', (string) DB::table('mig_jobs')->where('id', $job['id'])->value('secret')); // chiffré

        // Migration (file d'attente synchrone en test), conversion EUR → F CFA
        $done = $api()->postJson("/api/migration/jobs/{$job['id']}/run", ['what' => ['partners', 'products', 'stock', 'sales', 'purchases'], 'rate' => 655.957])->assertOk();
        $j = $api()->getJson("/api/migration/jobs/{$job['id']}")->assertOk()->json();
        $this->assertSame('done', $j['status'], json_encode($j));
        $this->assertEquals(['created' => 2], $j['counts']['partners']);
        $this->assertEquals(['created' => 2], $j['counts']['products']);
        $this->assertEquals(['created' => 3, 'skipped' => 1], $j['counts']['sales']);
        $this->assertEquals(['created' => 1], $j['counts']['purchases']);

        $cam = DB::table('products')->where('company_id', $company->id)->where('sku', 'CAM-01')->first();
        $this->assertEquals([196787.1, 137750.97, '3700000000017'], [(float) $cam->sale_price, (float) $cam->purchase_price, $cam->barcode]);
        $this->assertEquals(4, (float) DB::table('stock_moves')->where('product_id', $cam->id)->sum('quantity'));
        $partner = DB::table('partners')->where('company_id', $company->id)->where('name', 'Anthony Loison EI')->first();
        $this->assertEquals(['customer', 'FR12345678901', 'anthony@exemple.fr'], [$partner->type, $partner->tax_id, $partner->email]);

        $inv = SalesDocument::where('number', 'IN2610-0029')->first();
        $this->assertEquals(['validated', 'paid', 'Dolibarr', 36078.0], [$inv->status, $inv->payment_status, $inv->migrated_from, round((float) $inv->total, 2)]);
        $this->assertEquals(1, DB::table('payments')->where('document_id', $inv->id)->where('method', 'bank_transfer')->count());
        $open = SalesDocument::where('number', 'IN2610-0030')->first();
        $this->assertEquals(['partial', 65596], [$open->payment_status, round((float) $open->amount_paid, 2)]);
        $this->assertSame(0, DB::table('stock_moves')->where('product_id', $cam->id)->where('type', '!=', 'initial')->count()); // la facture historique ne sort pas de stock
        $av = SalesDocument::where('number', 'AV2610-0001')->first();
        $this->assertEquals(['credit_note', 7740], [$av->type, round((float) $av->total, 2)]);
        $this->assertEquals(1, DB::table('trade_documents')->where('company_id', $company->id)->where('direction', 'purchase')->where('number', 'SI2610-0001')->count());
        // Les factures reprises comptent dans l'historique du tableau de bord
        $this->assertGreaterThan(0, $api()->getJson('/api/dashboard/insights')->assertOk()->json('year.ytd'));

        // Relance : aucun doublon, les règlements arrivés entre-temps complètent la facture ouverte
        $before = DB::table('trade_documents')->where('company_id', $company->id)->count();
        $api()->postJson('/api/migration/connect', ['source' => 'dolibarr', 'url' => 'https://erp.sodiket.example', 'api_key' => 'cle-ok'])->assertOk();
        $j2 = DB::table('mig_jobs')->where('company_id', $company->id)->max('id');
        $api()->postJson("/api/migration/jobs/$j2/run", ['what' => ['partners', 'products', 'sales'], 'rate' => 655.957])->assertOk();
        $this->assertSame($before, DB::table('trade_documents')->where('company_id', $company->id)->count());
        $this->assertSame(2, DB::table('partners')->where('company_id', $company->id)->count());
        $this->assertEquals(['updated' => 2], $api()->getJson("/api/migration/jobs/$j2")->json('counts.partners'));
    }

    public function test_odoo_and_erpnext_connectors(): void
    {
        [$company, , $token] = $this->makeCompany('Négoce Migré');
        $api = fn () => $this->api($token);
        Http::fake(function ($req) {
            $u = $req->url();
            if (str_contains($u, '/jsonrpc')) {
                $p = $req->data()['params'];
                if ($p['service'] === 'common') {
                    return Http::response(['result' => $p['method'] === 'login' ? ($p['args'][2] === 'odoo-key' ? 7 : false) : ['server_version' => '17.0']]);
                }
                [, , , $model, $method, $args] = $p['args'];
                $kw = (array) ($p['args'][6] ?? []);
                if (($kw['offset'] ?? 0) > 0) {
                    return Http::response(['result' => []]);
                }
                $r = match ("$model.$method") {
                    'res.partner.search_count' => 2, 'product.product.search_count' => 1, 'account.move.search_count' => 1,
                    'res.company.search_read' => [['name' => 'Ancienne Odoo SARL']],
                    'res.partner.search_read' => [['id' => 3, 'name' => 'Pharmacie du Plateau', 'ref' => false, 'email' => 'contact@pharma.ci', 'phone' => '0707', 'mobile' => false, 'street' => 'Plateau', 'city' => 'Abidjan', 'vat' => 'CI-1234', 'customer_rank' => 2, 'supplier_rank' => 0, 'comment' => false]],
                    'product.product.search_read' => [['id' => 11, 'name' => 'Gel hydroalcoolique 500 ml', 'default_code' => 'GEL500', 'barcode' => false, 'categ_id' => [2, 'Hygiène'], 'type' => 'product', 'list_price' => 2500, 'standard_price' => 1500, 'taxes_id' => [1], 'uom_id' => [1, 'Unités'], 'qty_available' => 40, 'active' => true]],
                    'account.tax.read' => [['amount' => 18, 'amount_type' => 'percent']],
                    'account.move.search_read' => [['id' => 90, 'name' => 'INV/2026/00012', 'move_type' => 'out_invoice', 'partner_id' => [3, 'Pharmacie du Plateau'], 'invoice_date' => '2026-09-15', 'invoice_date_due' => '2026-10-15', 'amount_total' => 59000, 'amount_residual' => 9000, 'state' => 'posted', 'currency_id' => [1, 'XOF'], 'ref' => false]],
                    'account.move.line.search_read' => [['move_id' => [90, 'INV/2026/00012'], 'name' => 'Gel hydroalcoolique 500 ml', 'product_id' => [11, 'Gel'], 'quantity' => 20, 'price_unit' => 2500, 'discount' => 0, 'tax_ids' => [1]]],
                    default => null,
                };

                return Http::response(['result' => $r]);
            }
            if (str_contains($u, 'frappe.client.get_count')) {
                return Http::response(['message' => 1]);
            }
            $doc = fn ($d) => Http::response(['data' => $d]);
            $start = (int) ($req->data()['limit_start'] ?? 0);

            return match (true) {
                $start > 0 => $doc([]),
                str_contains($u, 'resource/Company') => $doc([['name' => 'ERPNext Démo']]),
                str_contains($u, 'resource/Customer') => $doc([['name' => 'CUST-0001', 'customer_name' => 'Hôtel Ivoire', 'email_id' => 'resa@hotel.ci', 'mobile_no' => '0101', 'tax_id' => null]]),
                str_contains($u, 'resource/Supplier') => $doc([]),
                str_contains($u, 'resource/Bin') => $doc([['item_code' => 'NAP-01', 'actual_qty' => 12]]),
                str_contains($u, 'resource/Item') => $doc([['name' => 'NAP-01', 'item_code' => 'NAP-01', 'item_name' => 'Nappe blanche', 'item_group' => 'Linge', 'stock_uom' => 'Nos', 'standard_rate' => 8000, 'valuation_rate' => 5000, 'is_stock_item' => 1, 'disabled' => 0]]),
                str_contains($u, 'Sales%20Invoice/SINV-0001') || str_contains($u, 'Sales Invoice/SINV-0001') => $doc(['name' => 'SINV-0001', 'customer' => 'CUST-0001', 'customer_name' => 'Hôtel Ivoire', 'posting_date' => '2026-08-01',
                    'due_date' => '2026-08-31', 'grand_total' => 94400, 'outstanding_amount' => 94400, 'is_return' => 0, 'docstatus' => 1, 'items' => [['item_code' => 'NAP-01', 'item_name' => 'Nappe blanche', 'qty' => 10, 'rate' => 8000]], 'taxes' => [['rate' => 18]]]),
                str_contains($u, 'Sales%20Invoice') || str_contains($u, 'Sales Invoice') => $doc([['name' => 'SINV-0001']]),
                str_contains($u, 'Purchase') => $doc([]),
                default => Http::response(['exc' => 'not faked '.$u], 500),
            };
        });

        $api()->postJson('/api/migration/connect', ['source' => 'odoo', 'url' => 'https://odoo.example', 'api_key' => 'odoo-key'])->assertStatus(422); // base et identifiant manquants
        $api()->postJson('/api/migration/connect', ['source' => 'odoo', 'url' => 'https://odoo.example', 'database' => 'prod', 'login' => 'admin@x.ci', 'api_key' => 'faux'])->assertStatus(422);
        $o = $api()->postJson('/api/migration/connect', ['source' => 'odoo', 'url' => 'https://odoo.example', 'database' => 'prod', 'login' => 'admin@x.ci', 'api_key' => 'odoo-key'])->assertOk()->json();
        $this->assertSame('Ancienne Odoo SARL 17.0', $o['label']);
        $api()->postJson("/api/migration/jobs/{$o['id']}/run", ['what' => ['partners', 'products', 'stock', 'sales']])->assertOk();
        $this->assertSame('done', $api()->getJson("/api/migration/jobs/{$o['id']}")->json('status'));
        $gel = DB::table('products')->where('company_id', $company->id)->where('sku', 'GEL500')->first();
        $this->assertEquals([2500, 18, 40], [(float) $gel->sale_price, (float) $gel->tax_rate, (float) DB::table('stock_moves')->where('product_id', $gel->id)->sum('quantity')]);
        $this->assertSame('Hygiène', DB::table('product_categories')->where('id', $gel->category_id)->value('name'));
        $inv = SalesDocument::where('number', 'INV/2026/00012')->first();
        $this->assertEquals([59000, 50000, 'partial', $gel->id], [(float) $inv->total, (float) $inv->amount_paid, $inv->payment_status, $inv->lines()->value('product_id')]);

        $e = $api()->postJson('/api/migration/connect', ['source' => 'erpnext', 'url' => 'https://erp.example', 'api_key' => 'k', 'api_secret' => 's'])->assertOk()->json();
        $api()->postJson("/api/migration/jobs/{$e['id']}/run", ['what' => ['partners', 'products', 'stock', 'sales']])->assertOk();
        $this->assertSame('done', $api()->getJson("/api/migration/jobs/{$e['id']}")->json('status'));
        $s = SalesDocument::where('number', 'SINV-0001')->first();
        $this->assertEquals([94400, 'unpaid', 'Hôtel Ivoire'], [(float) $s->total, $s->payment_status, DB::table('partners')->where('id', $s->partner_id)->value('name')]);
        $this->assertEquals(12, (float) DB::table('stock_moves')->where('product_id', DB::table('products')->where('company_id', $company->id)->where('sku', 'NAP-01')->value('id'))->sum('quantity'));
    }

    public function test_export_files_from_other_software(): void
    {
        [$company, , $token] = $this->makeCompany('Boutique Zoho');
        $api = fn () => $this->api($token);
        $csv = function (string $name, array $rows) {
            $path = tempnam(sys_get_temp_dir(), 'mig').'.csv';
            $f = fopen($path, 'w');
            foreach ($rows as $r) {
                fputcsv($f, $r);
            }
            fclose($f);

            return new UploadedFile($path, $name, 'text/csv', null, true);
        };
        $contacts = $csv('Contacts.csv', [['Display Name', 'Company Name', 'EmailID', 'Phone', 'Billing City', 'Contact Type'],
            ['Maquis Le Baobab', 'Maquis Le Baobab', 'baobab@ex.ci', '0505', 'Yamoussoukro', 'customer'], ['Solibra', 'Solibra', 'ventes@solibra.ci', '2721', 'Abidjan', 'vendor']]);
        $items = $csv('Item.csv', [['Item Name', 'SKU', 'Rate', 'Purchase Rate', 'Product Type', 'Tax Percentage'], ['Bière 65 cl', 'BIERE65', '1 000', '650', 'goods', '18'], ['Livraison', 'LIV', '2000', '', 'service', '0']]);
        $invoices = $csv('Invoice.csv', [['Invoice Number', 'Invoice Date', 'Due Date', 'Customer Name', 'Item Name', 'SKU', 'Quantity', 'Item Price', 'Item Tax %', 'Total', 'Balance', 'Invoice Status'],
            ['INV-000041', '2026-09-02', '2026-10-02', 'Maquis Le Baobab', 'Bière 65 cl', 'BIERE65', '48', '1000', '18', '59000', '0', 'Paid'],
            ['INV-000041', '2026-09-02', '2026-10-02', 'Maquis Le Baobab', 'Livraison', 'LIV', '1', '2000', '18', '59000', '0', 'Paid'],
            ['INV-000042', '2026-10-05', '2026-11-04', 'Maquis Le Baobab', 'Bière 65 cl', 'BIERE65', '24', '1000', '18', '28320', '28320', 'Sent'],
            ['INV-000043', '2026-10-06', '', 'Maquis Le Baobab', 'Bière 65 cl', 'BIERE65', '1', '1000', '18', '1180', '1180', 'Void']]);

        $bad = $csv('vide.csv', [['Colonne A', 'Colonne B'], ['x', 'y']]);
        $api()->post('/api/migration/files', ['vendor' => 'zoho', 'partners' => $bad], ['Accept' => 'application/json'])->assertStatus(422);
        $job = $api()->post('/api/migration/files', ['vendor' => 'zoho', 'partners' => $contacts, 'products' => $items, 'sales' => $invoices], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertEquals(['partners' => 2, 'products' => 2, 'sales' => 3], $job['probe']['counts']);
        $this->assertSame('Contact Type', $job['probe']['columns']['partners']['type']);
        $api()->postJson("/api/migration/jobs/{$job['id']}/run", ['what' => ['partners', 'products', 'sales']])->assertOk();
        $j = $api()->getJson("/api/migration/jobs/{$job['id']}")->json();
        $this->assertSame('done', $j['status'], json_encode($j));
        $this->assertEquals(['created' => 2, 'skipped' => 1], $j['counts']['sales']);
        $this->assertSame('supplier', DB::table('partners')->where('company_id', $company->id)->where('name', 'Solibra')->value('type'));
        $this->assertSame([], $j['warnings']);
        $this->assertSame('service', DB::table('products')->where('company_id', $company->id)->where('sku', 'LIV')->value('type'));
        $a = SalesDocument::with('lines')->where('number', 'INV-000041')->first();
        $this->assertEquals([59000, 'paid', 2, 'Zoho Books / Zoho Invoice'], [(float) $a->total, $a->payment_status, $a->lines->count(), $a->migrated_from]);
        $this->assertNotNull($a->lines->first()->product_id);
        $this->assertSame('unpaid', SalesDocument::where('number', 'INV-000042')->value('payment_status'));
        $this->assertSame([], DB::table('mig_jobs')->where('id', $job['id'])->value('secret') ? ['secret'] : []);
    }
}
