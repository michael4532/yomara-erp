<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Fournisseurs\InvoiceReader;
use App\Modules\Fournisseurs\Models\SupplierInvoice;
use App\Modules\Projets\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceReadingProjectCostsTest extends TestCase
{
    private function pdf(string $number, string $order, float $ht, string $date = '24/10/2026'): string
    {
        $f = fn ($v) => number_format($v, 0, ',', ' ');
        $p = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $p->setPrintHeader(false);
        $p->setPrintFooter(false);
        $p->AddPage();
        $p->SetFont('dejavusans', '', 10);
        $p->writeHTML('<h2>SOTICI MATÉRIAUX SARL</h2><p>Zone industrielle de Yopougon · Tél. +225 27 23 46 00 11 · factures@sotici.ci<br>NCC : 1523456 K · RCCM CI-ABJ-2012-B-4410</p>'
            .'<h1>FACTURE N° '.$number.'</h1><p>Date de facture : '.$date.'<br>Votre commande : '.$order.'</p>'
            .'<table border="1" cellpadding="4"><tr><th>Désignation</th><th>Qté</th><th>P.U.</th><th>Montant</th></tr><tr><td>Ciment CPJ 45 (sac)</td><td>'.($ht / 4500).'</td><td>4 500</td><td>'.$f($ht).'</td></tr></table>'
            .'<p>Total HT : '.$f($ht).' F CFA<br>TVA 18 % : '.$f($ht * 0.18).' F CFA<br><b>Net à payer : '.$f($ht * 1.18).' F CFA</b></p>');

        return $p->Output('', 'S');
    }

    private function ok(array $r): array
    {
        $this->assertSame('applied', $r['status'], json_encode($r));

        return $r['record'] ?? [];
    }

    public function test_invoice_reading_three_way_match_and_project_profitability(): void
    {
        Storage::fake('local');
        [$company, $admin, $token] = $this->makeCompany('BTP Lagune');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');

        $cement = $api()->postJson('/api/core/products', ['name' => 'Ciment CPJ 45 (sac)', 'sale_price' => 6000, 'purchase_price' => 4500, 'tax_rate' => 18, 'unit' => 'sac'])->json('data.id');
        $api()->postJson('/api/stock/warehouses', ['name' => 'Dépôt', 'is_default' => true])->assertCreated();
        $sup = $api()->postJson('/api/core/partners', ['name' => 'Sotici Matériaux', 'type' => 'supplier', 'tax_id' => '1523456K', 'email' => 'factures@sotici.ci'])->json('data.id');
        $other = $api()->postJson('/api/core/partners', ['name' => 'Bâtir Plus', 'type' => 'supplier'])->json('data.id');
        $client = $api()->postJson('/api/core/partners', ['name' => 'SCI Riviera', 'type' => 'customer'])->json('data.id');

        // Projet avec budget ; coût horaire de la main-d'œuvre (général + par personne)
        $pid = $this->uuid();
        $this->ok($push('prj_projects', ['name' => 'Villa Riviera 3', 'partner_id' => $client, 'billing_mode' => 'time', 'hourly_rate' => 20000, 'budget_amount' => 2000000, 'budget_hours' => 50], $pid));
        $api()->putJson('/api/projets/cost-settings', ['hourly_cost' => 5000, 'user_costs' => [$admin->id => 8000, 999999 => 1]])->assertOk()->assertJsonPath('data.user_costs', [(string) $admin->id => 8000]);
        $this->ok($push('prj_timesheets', ['project_id' => $pid, 'user_id' => $admin->id, 'date' => now()->toDateString(), 'hours' => 10]));

        // Bon de commande rattaché au projet ; projet inconnu refusé
        $line = ['id' => $this->uuid(), 'product_id' => $cement, 'description' => 'Ciment CPJ 45 (sac)', 'quantity' => 100, 'unit_price' => 4500, 'tax_rate' => 18, 'discount_percent' => 0];
        $this->assertSame('Projet introuvable.', $push('purchase_documents', ['type' => 'purchase_order', 'partner_id' => $sup, 'date' => now()->toDateString(), 'project_id' => $this->uuid(), 'lines' => [$line]])['message']);
        $poId = $this->uuid();
        $this->ok($push('purchase_documents', ['type' => 'purchase_order', 'partner_id' => $sup, 'date' => now()->toDateString(), 'project_id' => $pid, 'lines' => [$line]], $poId));
        $po = $api()->postJson("/api/achats/documents/$poId/validate")->assertOk()->json('data');
        $this->assertEquals([531000, $pid], [(float) $po['total'], $po['project_id']]);
        $api()->postJson('/api/achats/receipts', ['order_id' => $poId, 'date' => now()->toDateString(), 'lines' => [['order_line_id' => $po['lines'][0]['id'], 'quantity' => 60]]])->assertCreated();

        // 1. Lecture d'une facture PDF : n°, date, montants, NCC → fournisseur et bon de commande retrouvés
        $file = fn (string $content, string $name = 'facture.pdf') => UploadedFile::fake()->createWithContent($name, $content);
        $read = $api()->post('/api/portail_fournisseurs/invoices/read', ['file' => $file($this->pdf('SM-26-0881', $po['number'], 270000))])->assertOk()->json('data');
        $this->assertEquals(['SM-26-0881', '2026-10-24', 318600.0, 270000.0, 48600.0, $sup, $poId, true], [$read['number'], $read['date'], (float) $read['total'], (float) $read['subtotal'], (float) $read['tax'], $read['partner_id'], $read['order_id'], $read['readable']]);
        $api()->post('/api/portail_fournisseurs/invoices/read', ['file' => $file('x', 'f.docx')])->assertStatus(422);

        // 2. Import par l'acheteur : tout est repris du document ; rapprochement = conforme à la réception partielle (60 / 100)
        $inv = $api()->post('/api/portail_fournisseurs/invoices', ['partner_id' => $sup, 'file' => $file($this->pdf('SM-26-0881', $po['number'], 270000))])->assertCreated()->json('data');
        $this->assertEquals(['SM-26-0881', 318600.0, $poId, 'upload', 'received', []], [$inv['number'], (float) $inv['amount'], $inv['order_id'], $inv['source'], $inv['status'], $inv['extracted']['warnings']]);
        $this->assertEquals(['partial', 531000.0, 318600.0, 318600.0, 0.0], [$inv['match']['status'], $inv['match']['ordered'], $inv['match']['received'], $inv['match']['expected'], $inv['match']['gap']]);
        $m = $api()->getJson('/api/portail_fournisseurs/invoices/'.$inv['id'].'/match')->assertOk()->json('data');
        $this->assertEquals([100.0, 60.0, 'partial'], [$m['lines'][0]['ordered'], $m['lines'][0]['received'], $m['lines'][0]['state']]);
        $api()->post('/api/portail_fournisseurs/invoices', ['partner_id' => $sup, 'file' => $file($this->pdf('SM-26-0881', $po['number'], 270000))])->assertStatus(422)->assertJsonFragment(['message' => 'Sotici Matériaux a déjà déposé la facture n° SM-26-0881.']);

        $this->travel(1)->minutes();
        // 3. Saisie divergente du document et facturation au-delà de la commande : écarts signalés
        $inv2 = $api()->post('/api/portail_fournisseurs/invoices', ['partner_id' => $sup, 'number' => 'SM-26-0882', 'amount' => 531000, 'date' => '2026-10-20', 'order_id' => $poId,
            'file' => $file($this->pdf('SM-26-0881', $po['number'], 270000))])->assertCreated()->json('data');
        $this->assertSame('over', $inv2['match']['status']);
        $this->assertCount(3, $inv2['extracted']['warnings']); // n°, date, montant
        $this->assertStringContainsString('Montant lu : 318 600', implode(' | ', $inv2['extracted']['warnings']));
        $inv3 = $api()->post('/api/portail_fournisseurs/invoices', ['partner_id' => $other, 'number' => 'BP-1', 'amount' => 10000, 'date' => now()->toDateString(), 'file' => $file($this->pdf('SM-26-0990', 'BCF-0000-00000', 9000))])->assertCreated()->json('data');
        $this->assertSame('no_order', $inv3['match']['status']);
        $this->assertStringContainsString('émis par Sotici Matériaux', implode(' | ', $inv3['extracted']['warnings']));
        $api()->putJson('/api/portail_fournisseurs/settings', ['match_tolerance' => 2.5])->assertOk()->assertJsonPath('data.match_tolerance', 2.5);

        // 4. Acceptation : facture fournisseur sur les quantités reçues, rattachée au projet
        $this->ok($push('sp_invoices', ['status' => 'accepted'], $inv['id']));
        $fi = PurchaseDocument::with('lines')->find(SupplierInvoice::find($inv['id'])->supplier_invoice_id);
        $this->assertEquals([60.0, 318600.0, $pid, $poId], [(float) $fi->lines[0]->quantity, (float) $fi->total, $fi->project_id, $fi->source_id]);
        $api()->postJson("/api/achats/documents/{$fi->id}/validate")->assertOk();
        $this->ok($push('sp_invoices', ['status' => 'rejected', 'reject_reason' => 'Dépasse la commande'], $inv2['id']));

        // 5. Rentabilité : main-d'œuvre 10 h × 8 000, achats 270 000 HT, engagement restant 180 000
        $c = $api()->getJson("/api/projets/projects/$pid/costs")->assertOk()->json('data');
        $this->assertEquals([10.0, 80000.0, 270000.0, 180000.0, 350000.0, 530000.0, 26.5, 1470000.0, 0.0],
            [$c['hours'], $c['labour'], $c['purchases'], $c['commitments'], $c['actual_cost'], $c['committed_cost'], $c['budget_used'], $c['budget_left'], $c['revenue']]);
        $this->assertEquals(530000 + 40 * 8000, $c['forecast_cost']); // 40 h restantes au coût moyen constaté
        $this->assertEquals(1000000, $c['expected_revenue']); // 50 h × 20 000

        // Facturation du temps : la facture client porte le projet → chiffre d'affaires et marge
        $sale = $api()->postJson("/api/projets/projects/$pid/invoice")->assertOk()->json("invoice");
        $this->assertSame($pid, $sale['project_id']);
        $api()->postJson("/api/ventes/documents/{$sale['id']}/validate")->assertOk();
        $c = $api()->getJson("/api/projets/projects/$pid/costs")->assertOk()->json('data');
        $this->assertEquals([200000.0, -150000.0], [$c['revenue'], $c['margin']]);
        // Avoir copié depuis la facture : projet conservé, CA réduit
        $credit = $api()->postJson("/api/ventes/documents/{$sale['id']}/convert", ['type' => 'credit_note'])->assertSuccessful()->json('data');
        $this->assertSame($pid, $credit['project_id']);

        // 6. Alertes budget : 80 % puis 100 % (au chef de projet), une seule fois par seuil
        $this->ok($push('prj_projects', ['budget_amount' => 600000], $pid));
        $api()->getJson("/api/projets/projects/$pid/costs")->assertOk();
        $api()->getJson("/api/projets/projects/$pid/costs")->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('title', 'Budget à 80 % : Villa Riviera 3')->count());
        $po2 = $this->uuid();
        $this->ok($push('purchase_documents', ['type' => 'purchase_order', 'partner_id' => $other, 'date' => now()->toDateString(), 'project_id' => $pid, 'lines' => [array_merge($line, ['id' => $this->uuid(), 'quantity' => 30])]], $po2));
        $api()->postJson("/api/achats/documents/$po2/validate")->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('title', 'Budget dépassé : Villa Riviera 3')->count());
        $this->assertSame(100, (int) Project::find($pid)->cost_alerted);
        $this->artisan('erp:projets-budget')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('title', 'like', 'Budget dépassé%')->count());
    }

    public function test_reader_parsing_and_ocr(): void
    {
        $x = app(InvoiceReader::class)->extract("ETS KOFFI & FILS\nN° CC : 0912345 A\nFacture n° : 2026/118\nAbidjan, le 3 septembre 2026\nRéf. commande BCF-2026-00042\nTotal HT 1.250.000\nTVA (18%) 225.000\nTotal TTC 1.475.000 FCFA");
        $this->assertEquals(['2026/118', '2026-09-03', 1475000.0, 1250000.0, 225000.0, ['BCF-2026-00042']], [$x['number'], $x['date'], $x['total'], $x['subtotal'], $x['tax'], $x['orders']]);
        $this->assertSame(1234.5, InvoiceReader::amount('1 234,50'));
        $this->assertSame(1234.5, InvoiceReader::amount('1,234.50'));

        // Facture scannée (image) : lue par OCR quand tesseract est installé
        $r = app(InvoiceReader::class);
        if (! $r->ocrAvailable() || ! trim((string) shell_exec('command -v pdftoppm'))) {
            $this->markTestSkipped('tesseract / pdftoppm absents');
        }
        $dir = sys_get_temp_dir().'/ocr'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/f.pdf", $this->pdf('SM-26-0777', 'BCF-2026-00009', 450000));
        shell_exec("pdftoppm -r 200 -png -singlefile $dir/f.pdf $dir/f");
        $o = $r->read((string) file_get_contents("$dir/f.png"), 'png');
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
        $this->assertEquals(['SM-26-0777', 531000.0, ['BCF-2026-00009']], [$o['number'], (float) $o['total'], $o['orders']]);
    }
}
