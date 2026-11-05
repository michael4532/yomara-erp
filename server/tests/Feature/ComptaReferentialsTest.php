<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Modules\Compta\Referentials;
use App\Services\CompanySetup;
use App\Support\ModuleRegistry;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Référentiels comptables : SYSCOHADA (normal, SMT), SYCEBNL, PCG (France), CGNC (Maroc). */
class ComptaReferentialsTest extends TestCase
{
    private function company(string $name, string $country): array
    {
        ['company' => $c, 'user' => $u] = app(CompanySetup::class)->create(['name' => $name, 'country' => $country],
            ['name' => 'Admin', 'email' => Str::slug($name).'-'.Str::random(4).'@test.ci', 'password' => 'secret123'], Plan::where('code', 'entreprise')->first());
        app(ModuleRegistry::class)->flush();
        $token = $u->createToken('t')->plainTextToken;
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();

        return [$c, $api];
    }

    /** Vente de 100 000 HT (TVA 20 %), encaissée en banque ; achat de marchandises de 40 000 HT, payé en espèces. */
    private function activity($api, float $vat = 20): array
    {
        $cust = $api()->postJson('/api/core/partners', ['name' => 'Client Martin', 'type' => 'customer'])->json('data.id');
        $sup = $api()->postJson('/api/core/partners', ['name' => 'Grossiste Dupont', 'type' => 'supplier'])->json('data.id');
        $prod = $api()->postJson('/api/core/products', ['name' => 'Article A', 'sale_price' => 50000, 'purchase_price' => 20000, 'tax_rate' => $vat])->json('data.id');
        $res = $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $docId = $this->uuid(),
            'data' => ['type' => 'invoice', 'partner_id' => $cust, 'date' => now()->toDateString(), 'status' => 'validated',
                'lines' => [['id' => $this->uuid(), 'product_id' => $prod, 'description' => 'Article A', 'quantity' => 2, 'unit_price' => 50000, 'tax_rate' => $vat, 'discount_percent' => 0]]],
        ]]])->assertOk()->json('results.0');
        $this->assertSame('applied', $res['status'], json_encode($res));
        $api()->postJson('/api/ventes/payments', ['document_id' => $docId, 'date' => now()->toDateString(), 'amount' => 100000 * (1 + $vat / 100), 'method' => 'bank_transfer'])->assertCreated();
        $doc = $api()->postJson('/api/achats/documents', ['type' => 'supplier_invoice', 'partner_id' => $sup, 'date' => now()->toDateString(),
            'lines' => [['product_id' => $prod, 'description' => 'Article A', 'quantity' => 2, 'unit_price' => 20000, 'tax_rate' => $vat]]])->assertCreated()->json('data');
        $api()->postJson("/api/achats/documents/{$doc['id']}/validate")->assertOk();

        return [$cust, $sup];
    }

    private function codes($api): array
    {
        $out = [];
        foreach ($api()->getJson('/api/compta/entries?per_page=200&to='.now()->addDay()->toDateString())->json('data') as $e) {
            foreach ($e['lines'] as $l) {
                $out[$l['account']['code']] = round(($out[$l['account']['code']] ?? 0) + $l['debit'] - $l['credit'], 2);
            }
        }

        return $out;
    }

    public function test_france_company_uses_pcg_accounts_and_statements(): void
    {
        [$c, $api] = $this->company('Négoce Lyonnais', 'FR');
        $setup = $api()->getJson('/api/compta/setup')->assertOk()->json();
        $this->assertSame('pcg', $setup['referential']['key']);
        $codes = array_column($setup['accounts'], 'code');
        foreach (['607', '707', '706', '44571', '44566', '512', '530', '411', '401'] as $k) {
            $this->assertContains($k, $codes);
        }
        $this->assertEquals(['customers' => '411', 'sales_goods' => '707', 'sales_services' => '706', 'purchases_goods' => '607', 'vat_collected' => '44571', 'vat_deductible' => '44566', 'bank' => '512', 'cash' => '530'],
            array_intersect_key($setup['mapping'], array_flip(['customers', 'sales_goods', 'sales_services', 'purchases_goods', 'vat_collected', 'vat_deductible', 'bank', 'cash'])));
        $this->assertSame('6064', collect($setup['purchase_kinds'])->firstWhere('key', 'office')['account']);
        $this->assertTrue($setup['can_change_referential']);

        [$cust] = $this->activity($api);
        $g = $this->codes($api);
        $this->assertEquals(['707' => -100000, '44571' => -20000, '411' => 0, '512' => 120000, '607' => 40000, '44566' => 8000, '401' => -48000],
            array_intersect_key($g, array_flip(['707', '44571', '411', '512', '607', '44566', '401'])));
        $this->assertArrayNotHasKey('701', $g);

        // États au modèle PCG
        $inc = $api()->getJson('/api/compta/statements/income')->assertOk()->json('data');
        $this->assertSame('Compte de résultat (PCG)', $inc['title']);
        $rows = collect($inc['rows'])->keyBy('key');
        $this->assertEquals([100000, 40000, 60000, 60000], [$rows['P1']['n'], $rows['C1']['n'], $rows['XE']['n'], $rows['XN']['n']]);
        $this->assertSame('', $rows['P1']['ref']);
        $sheet = $api()->getJson('/api/compta/statements/sheet')->assertOk()->json('data');
        $this->assertTrue($sheet['balanced'], json_encode($sheet));
        $a = collect($sheet['assets'])->keyBy('key');
        $l = collect($sheet['liabilities'])->keyBy('key');
        $this->assertEquals([120000, 60000, 48000], [$a['A10']['net'], $l['L7']['n'], $l['L14']['n']]);
        $this->assertEquals([20000, 8000], [$l['L15']['n'], $a['A8']['net']]); // TVA collectée au passif, TVA déductible à l'actif
        $cf = $api()->getJson('/api/compta/statements/cashflow')->assertOk()->json('data');
        $this->assertTrue($cf['check']);
        $vat = $api()->getJson('/api/compta/vat')->assertOk()->json('data');
        $this->assertEquals([20000, 8000, 12000], [$vat['collected'], $vat['deductible'], $vat['due']]);
        $dash = $api()->getJson('/api/compta/dashboard')->assertOk()->json('data');
        $this->assertEquals([100000, 60000, 60000], [$dash['kpis']['revenue'], $dash['kpis']['margin'], $dash['kpis']['result']]);
        $this->assertSame('pcg', $dash['referential']['key']);
        $tiers = $api()->getJson('/api/compta/tiers?side=suppliers')->assertOk()->json();
        $this->assertEquals(48000, collect($tiers['rows'] ?? $tiers['data']['rows'])->sum('balance') * -1);
        $income = $api()->getJson('/api/compta/income')->assertOk()->json('data') ?? $api()->getJson('/api/compta/income')->json();
        $this->assertSame('Ventes (chiffre d\'affaires)', $income['produits'][0]['label']);

        // Le référentiel ne change plus après les premières écritures
        $api()->putJson('/api/compta/settings', ['referential' => 'syscohada'])->assertStatus(422);
    }

    public function test_morocco_company_uses_cgnc_and_cpc(): void
    {
        [, $api] = $this->company('Atlas Distribution', 'MA');
        $this->assertSame('cgnc', $api()->getJson('/api/compta/setup')->json('referential.key'));
        $this->activity($api);
        $g = $this->codes($api);
        $this->assertEquals(['7111' => -100000, '4455' => -20000, '3421' => 0, '5141' => 120000, '6111' => 40000, '34552' => 8000, '4411' => -48000],
            array_intersect_key($g, array_flip(['7111', '4455', '3421', '5141', '6111', '34552', '4411'])));
        $inc = collect($api()->getJson('/api/compta/statements/income')->json('data.rows'))->keyBy('key');
        $this->assertEquals([100000, 40000, 60000, 60000], [$inc['I1']['n'], $inc['C1']['n'], $inc['XIII']['n'], $inc['XXIII']['n']]);
        $sheet = $api()->getJson('/api/compta/statements/sheet')->json('data');
        $this->assertTrue($sheet['balanced'], json_encode($sheet));
        $l = collect($sheet['liabilities'])->keyBy('key');
        // État créditeur (TVA facturée) au passif, État débiteur (TVA récupérable) à l'actif, sans compensation
        $this->assertEquals([60000, 48000, 20000, 8000], [$l['L8']['n'], $l['L13']['n'], $l['L17']['n'], collect($sheet['assets'])->keyBy('key')['A10']['net']]);
        $this->assertTrue($api()->getJson('/api/compta/statements/cashflow')->json('data.check'));
        $vat = $api()->getJson('/api/compta/vat')->assertOk()->json('data');
        $this->assertEquals([20000, 8000, 12000], [$vat['collected'], $vat['deductible'], $vat['due']], json_encode($vat));
    }

    public function test_ohada_switch_to_sycebnl_and_smt_before_first_entry(): void
    {
        [$c, $api] = $this->company('Association Lumière', 'CI');
        $setup = $api()->getJson('/api/compta/setup')->json();
        $this->assertSame('syscohada', $setup['referential']['key']);
        $this->assertSame('2442', \App\Modules\Immobilisations\Models\AssetCategory::where('name', 'Matériel informatique')->value('gl_asset'));

        // SYCEBNL : plan des entités à but non lucratif ; catégories d'immobilisations conservées (mêmes numéros)
        $setup = $api()->putJson('/api/compta/settings', ['referential' => 'sycebnl'])->assertOk()->json();
        $this->assertSame('sycebnl', $setup['referential']['key']);
        $acc = collect($setup['accounts'])->keyBy('code');
        $this->assertSame(['Cotisations des adhérents', 'Adhérents', 'Clients-usagers', 'Fonds affectés à un projet spécifique'], [$acc['701']['name'], $acc['411']['name'], $acc['412']['name'], $acc['165']['name']]);
        $this->assertSame(['412', '7051', '7053'], [$setup['mapping']['customers'], $setup['mapping']['sales_goods'], $setup['mapping']['sales_services']]);

        // PCG puis SMT : catégories converties, retour au SYSCOHADA restitue les numéros
        $api()->putJson('/api/compta/settings', ['referential' => 'pcg'])->assertOk();
        $this->assertSame(['21832', '28183', '6811'], array_values(\App\Modules\Immobilisations\Models\AssetCategory::where('name', 'Matériel informatique')->first()->only(['gl_asset', 'gl_depreciation', 'gl_expense'])));
        $api()->putJson('/api/compta/settings', ['referential' => 'syscohada_smt'])->assertOk();
        $this->assertSame('2442', \App\Modules\Immobilisations\Models\AssetCategory::where('name', 'Matériel informatique')->value('gl_asset'));
        $this->assertSame('syscohada_smt', Referentials::of($c->fresh()));

        // SMT : compte de résultat recettes / dépenses et état des recettes et dépenses
        $this->activity($api, 18);
        $inc = collect($api()->getJson('/api/compta/statements/income')->json('data.rows'))->keyBy('key');
        $this->assertEquals([100000, 40000, 60000], [$inc['XR']['n'], $inc['XD']['n'], $inc['XN']['n']]);
        $sheet = $api()->getJson('/api/compta/statements/sheet')->json('data');
        $this->assertTrue($sheet['balanced'], json_encode($sheet));
        $cash = $api()->getJson('/api/compta/statements/cashflow')->json('data');
        $this->assertSame('État des recettes et des dépenses', $cash['title']);
        $rows = collect($cash['rows'])->keyBy('key');
        $this->assertEquals([118000, 118000], [$rows['XR']['amount'], $rows['ZH']['amount']]);
        $this->assertTrue($cash['check']);
        $api()->putJson('/api/compta/settings', ['referential' => 'sycebnl'])->assertStatus(422);
    }

    public function test_sycebnl_statements(): void
    {
        [, $api] = $this->company('ONG Espoir', 'SN');
        $api()->putJson('/api/compta/settings', ['referential' => 'sycebnl'])->assertOk();
        $this->activity($api, 18);
        $g = $this->codes($api);
        $this->assertEquals(['7051' => -100000, '412' => 0, '601' => 40000], array_intersect_key($g, array_flip(['7051', '412', '601'])));
        $inc = $api()->getJson('/api/compta/statements/income')->json('data');
        $this->assertSame('Compte de résultat (SYCEBNL)', $inc['title']);
        $rows = collect($inc['rows'])->keyBy('key');
        $this->assertEquals([100000, 40000, 60000], [$rows['R4']['n'], $rows['C1']['n'], $rows['XI']['n']]);
        $sheet = $api()->getJson('/api/compta/statements/sheet')->json('data');
        $this->assertTrue($sheet['balanced'], json_encode($sheet));
        $this->assertEquals(60000, collect($sheet['liabilities'])->keyBy('key')['CJ']['n']);
        $this->assertTrue($api()->getJson('/api/compta/statements/cashflow')->json('data.check'));
        $this->assertEquals(100000, $api()->getJson('/api/compta/dashboard')->json('data.kpis.revenue'));
    }

    public function test_translation_helpers(): void
    {
        $this->assertSame('6064', Referentials::code('pcg', '6055'));
        $this->assertSame('641', Referentials::code('pcg', '661'));
        $this->assertSame('6171', Referentials::code('cgnc', '661'));
        $this->assertSame('64', Referentials::code('pcg', '66'));
        $this->assertSame('6812', Referentials::code('syscohada', '6812'));
        $this->assertSame('6811', Referentials::code('pcg', '6812')); // sous-compte sans équivalent : compte parent
        $this->assertSame('2442', Referentials::translate('21832', 'pcg', 'syscohada'));
        $this->assertSame('2355', Referentials::translate('21832', 'pcg', 'cgnc'));
        $this->assertSame('pcg', Referentials::forCountry('fr'));
        $this->assertSame('syscohada', Referentials::forCountry('SN'));
        // Chaque compte de référence utilisé par les écritures automatiques existe dans les plans PCG et CGNC
        foreach (['pcg', 'cgnc'] as $ref) {
            $chart = Referentials::accounts($ref);
            foreach (array_merge(array_values(\App\Modules\Compta\Chart::DEFAULT_MAPPING), array_column(\App\Modules\Compta\Chart::PURCHASE_KINDS, 1), ['471', '4451', '676', '776', '651', '81', '82', '681', '4286', '661', '664', '641', '422', '421', '431', '447', '442', '481', '485', '622', '637', '632', '585', '162', '671', '758', '461']) as $k) {
                $this->assertArrayHasKey(Referentials::code($ref, $k), $chart, "$ref : $k");
            }
        }
    }
}
