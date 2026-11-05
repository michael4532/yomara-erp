<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Tableau de bord analytique (indicateurs N / N-1, alertes) et tableaux de bord Excel interactifs (génération, filtres, enregistrement, envoi). */
class TableauxExcelTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_insights_and_interactive_excel_workbooks(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        [$company, , $token] = $this->makeCompany('Négoce Insights');
        $api = fn () => $this->api($token);
        $cat = $api()->postJson('/api/core/product-categories', ['name' => 'Ciment'])->json('data.id');
        $cement = $api()->postJson('/api/core/products', ['name' => 'Ciment CPJ 45', 'sale_price' => 10000, 'purchase_price' => 6000, 'tax_rate' => 18, 'category_id' => $cat])->assertCreated()->json('data.id');
        $client = $api()->postJson('/api/core/partners', ['name' => 'Quincaillerie du Port', 'type' => 'customer'])->json('data.id');
        $other = $api()->postJson('/api/core/partners', ['name' => 'Bâtiments Kouassi', 'type' => 'customer'])->json('data.id');
        $invoice = function (string $date, array $lines, ?string $due = null, ?string $partner = null) use ($api, $client) {
            $d = $api()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $partner ?? $client, 'date' => $date, 'due_date' => $due ?? $date, 'lines' => $lines])->assertCreated()->json('data');

            return $api()->postJson("/api/ventes/documents/{$d['id']}/validate")->assertOk()->json('data');
        };
        $free = fn (float $ht) => ['description' => 'Transport et main-d\'œuvre', 'quantity' => 1, 'unit_price' => $ht, 'tax_rate' => 0];
        $oct = $invoice('2026-10-05', [['product_id' => $cement, 'description' => 'Ciment CPJ 45', 'quantity' => 10, 'unit_price' => 10000, 'tax_rate' => 0], $free(50000)]);
        $api()->postJson('/api/ventes/payments', ['document_id' => $oct['id'], 'date' => '2026-10-06', 'amount' => 150000, 'method' => 'mobile_money'])->assertCreated();
        $invoice('2026-09-10', [$free(100000)]);
        $invoice('2026-06-01', [$free(200000)], '2026-07-01', $other); // impayée depuis plus de 60 jours
        $invoice('2025-10-03', [$free(80000)]);

        // Tableau de bord analytique
        $i = $api()->getJson('/api/dashboard/insights')->assertOk()->json();
        $this->assertEquals([150000, 87.5], [$i['kpis']['revenue']['value'], $i['kpis']['revenue']['vs_last_year']]);
        $this->assertEquals([40000, 40], [$i['kpis']['margin']['value'], $i['kpis']['margin']['rate']]);
        $this->assertCount(12, $i['series']);
        $this->assertEquals(['2026-10', 150000, 80000], [end($i['series'])['month'], end($i['series'])['current'], end($i['series'])['previous']]);
        $this->assertSame('critical', $i['alerts'][0]['level']);
        $this->assertStringContainsString('plus de 60 jours', $i['alerts'][0]['text']);
        $this->assertLessThan(100, $i['health']['score']);
        $this->assertEquals('customers', $i['table']['kind']);
        $this->assertEquals(['Quincaillerie du Port', 250000, 'Bâtiments Kouassi', 'Retard de paiement'], [$i['table']['rows'][0]['name'], $i['table']['rows'][0]['revenue'], $i['table']['rows'][1]['name'], $i['table']['rows'][1]['status']]);
        $this->assertEquals(['Prestations & services', 'Ciment'], array_column($i['mix']['parts'], 'label'));

        // Galerie de modèles
        $idx = $api()->getJson('/api/tableaux_excel')->assertOk()->json();
        $tpl = collect($idx['templates'])->keyBy('key');
        $this->assertTrue($tpl['ventes']['available']);
        $this->assertCount(6, $idx['themes']);

        // Classeur « Analyse des ventes » : valeurs pré-calculées puis filtres recalculés par le moteur de formules
        $res = $api()->get('/api/tableaux_excel/generate?template=ventes&theme=nuit&title=Ventes+Négoce')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('content-type'));
        $file = tempnam(sys_get_temp_dir(), 't').'.xlsx';
        file_put_contents($file, $res->streamedContent());
        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);
        $book = $reader->load($file);
        $this->assertSame(['Tableau de bord', 'Données', 'Calculs', 'Listes', 'Mode d\'emploi'], $book->getSheetNames());
        $dash = $book->getSheetByName('Tableau de bord');
        $this->assertSame('Ventes Négoce', $dash->getCell('B2')->getValue());
        $this->assertSame('2026', (string) $dash->getCell('C5')->getValue());
        $this->assertEquals(450000, $dash->getCell('B8')->getOldCalculatedValue()); // CA 2026
        $this->assertEquals(40000, $dash->getCell('F8')->getOldCalculatedValue()); // marge
        $this->assertCount(5, $dash->getChartCollection());
        $this->assertEquals(6, $book->getSheetByName('Données')->getHighestRow()); // en-tête + 5 lignes de vente
        $this->assertSame('list', $dash->getCell('C5')->getDataValidation()->getType());
        $calc = \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($book);
        $dash->setCellValue('C5', '2025');
        $calc->clearCalculationCache();
        $this->assertEquals(80000, $dash->getCell('B8')->getCalculatedValue());
        $dash->setCellValue('C5', '2026');
        $dash->setCellValue('G5', '10 · oct.');
        $calc->clearCalculationCache();
        $this->assertEquals(150000, $dash->getCell('B8')->getCalculatedValue());
        $dash->setCellValue('O5', 'Ciment');
        $calc->clearCalculationCache();
        $this->assertEquals(100000, $dash->getCell('B8')->getCalculatedValue());
        @unlink($file);

        // Tous les modèles se génèrent
        foreach (['direction', 'tresorerie', 'creances', 'stock', 'achats'] as $t) {
            $api()->get("/api/tableaux_excel/generate?template=$t&theme=emeraude")->assertOk();
        }
        $api()->getJson('/api/tableaux_excel/generate?template=inconnu')->assertStatus(422);

        // Tableaux enregistrés et envoi programmé
        $api()->postJson('/api/tableaux_excel/workbooks', ['name' => 'Créances du lundi', 'template' => 'creances', 'frequency' => 'weekly'])->assertStatus(422);
        $w = $api()->postJson('/api/tableaux_excel/workbooks', ['name' => 'Créances du lundi', 'template' => 'creances', 'theme' => 'corail', 'frequency' => 'weekly',
            'recipients' => ['dg@negoce.ci', 'dg@negoce.ci', 'compta@negoce.ci']])->assertOk()->json();
        $this->assertEquals(['dg@negoce.ci', 'compta@negoce.ci'], $w['recipients']);
        $api()->get("/api/tableaux_excel/workbooks/{$w['id']}/download")->assertOk();
        $this->assertEquals(1, DB::table('xl_workbooks')->where('id', $w['id'])->value('downloads'));

        Carbon::setTestNow('2026-10-19 07:10:00'); // lundi
        app()->instance('tenant', $company);
        $r = app(\App\Modules\TableauxExcel\TableauxExcelService::class)->runDue($company);
        $this->assertEquals(['sent' => 0, 'failed' => 1], $r);
        $this->assertSame('failed', DB::table('xl_workbooks')->where('id', $w['id'])->value('last_status'));

        $api()->deleteJson("/api/tableaux_excel/workbooks/{$w['id']}")->assertNoContent();
    }
}
