<?php

namespace Tests\Feature;

use App\Modules\Ventes\Models\SalesDocument;
use Tests\TestCase;

class InvoiceVatTest extends TestCase
{
    public function test_vat_settings_payment_method_and_receipt_box(): void
    {
        [$company, , $token] = $this->makeCompany('Akwaba');
        $push = function (array $data) use ($token) {
            $id = $this->uuid();
            $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $id,
                'data' => $data + ['type' => 'invoice', 'date' => now()->toDateString()]]]])->assertOk()->assertJsonPath('results.0.status', 'applied');

            return SalesDocument::withoutGlobalScopes()->with('lines')->find($id);
        };
        $line = ['id' => $this->uuid(), 'description' => 'Prestation', 'quantity' => 1, 'unit_price' => 55000, 'tax_rate' => 18];

        // Assujetti (par défaut) : TVA 18 %, mode de règlement et case décharge enregistrés
        $d = $push(['payment_method' => 'mobile_money', 'receipt_box' => true, 'bank_account' => 'ECOBANK|122397', 'lines' => [$line]]);
        $this->assertEquals(64900, $d->total);
        $this->assertSame('mobile_money', $d->payment_method);
        $this->assertTrue($d->receipt_box);
        $this->assertSame('ECOBANK|122397', $d->bank_account);

        // Mode de règlement inconnu : refusé
        $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(),
            'data' => ['type' => 'invoice', 'date' => now()->toDateString(), 'payment_method' => 'bitcoin', 'lines' => [array_merge($line, ['id' => $this->uuid()])]]]]])->assertJsonPath('results.0.status', 'rejected');

        // Réglages de la société : pays, non assujetti, régime, case décharge
        $this->api($token)->putJson('/api/company', ['country' => 'SN', 'settings' => ['vat_subject' => false, 'tax_regime' => 'Entreprenant', 'invoice_receipt_box' => true, 'invoice_amount_words' => true, 'legal_form' => 'SARL', 'capital' => 1000000, 'default_payment_method' => 'cash']])->assertOk();
        $s = $company->fresh()->settings;
        $this->assertFalse($s['vat_subject']);
        $this->assertSame('Entreprenant', $s['tax_regime']);
        $this->assertSame('SARL', $s['legal_form']);
        $this->assertSame('cash', $s['default_payment_method']);
        $this->assertSame('SN', $company->fresh()->country);

        // Non assujetti : la TVA envoyée est ignorée, total = HT
        $d = $push(['lines' => [array_merge($line, ['id' => $this->uuid()])]]);
        $this->assertEquals(55000, $d->total);
        $this->assertEquals(0, $d->tax_total);
        $this->assertEquals(0, $d->lines->first()->tax_rate);
    }

    public function test_deposit_invoice_and_deduction(): void
    {
        [, , $token] = $this->makeCompany('Akwaba');
        $api = fn () => $this->api($token);
        $push = fn (array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(),
            'data' => $data + ['date' => now()->toDateString(), 'status' => 'validated']]]])->assertOk()->json('results.0');
        $client = $api()->postJson('/api/core/partners', ['name' => 'Client Acompte', 'type' => 'customer'])->assertCreated()->json('data.id');

        // Facture d'acompte de 30 % (30 000 HT + TVA 18 %) : numérotée ACP, payable, en 4191
        $dep = $this->uuid();
        $r = $push(['type' => 'deposit', 'partner_id' => $client, 'lines' => [['id' => $this->uuid(), 'description' => 'Acompte 30 %', 'quantity' => 1, 'unit_price' => 30000, 'tax_rate' => 18]]], $dep);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertStringStartsWith('ACP', $r['record']['number']);
        $this->assertEquals(35400, $r['record']['total']);
        $acc = fn (string $id) => \App\Modules\Compta\Models\Entry::withoutGlobalScopes()->where('source_type', 'document')->where('source_id', $id)->with('lines.account')->first()
            ?->lines->groupBy('account.code')->map(fn ($g) => round($g->sum('debit') - $g->sum('credit')));
        $e = $acc($dep);
        $this->assertNotNull($e, 'écriture de l\'acompte');
        $this->assertEquals(-30000, $e['4191']);
        $this->assertEquals(35400, $e['411']);
        $api()->postJson('/api/ventes/payments', ['document_id' => $dep, 'date' => now()->toDateString(), 'amount' => 35400, 'method' => 'cash'])->assertCreated();

        // Facture finale : 100 000 HT, acompte déduit (ligne négative liée) → reste 82 600 TTC
        $inv = $this->uuid();
        $r = $push(['type' => 'invoice', 'partner_id' => $client, 'lines' => [
            ['id' => $this->uuid(), 'description' => 'Prestation complète', 'quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 18],
            ['id' => $this->uuid(), 'description' => 'Déduction acompte', 'quantity' => -1, 'unit_price' => 30000, 'tax_rate' => 18, 'deposit_id' => $dep],
        ]], $inv);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertEquals(82600, $r['record']['total']);
        $this->assertSame($dep, collect($r['record']['lines'])->firstWhere('quantity', -1)['deposit_id']);
        $e = $acc($inv);
        $this->assertEquals(30000, $e['4191']); // l'avance est soldée
        $this->assertEquals(82600, $e['411']);

        // Second usage du même acompte, ou acompte d'un autre client : refusés
        $deduct = fn ($partner) => ['type' => 'invoice', 'partner_id' => $partner, 'lines' => [
            ['id' => $this->uuid(), 'description' => 'X', 'quantity' => 1, 'unit_price' => 50000, 'tax_rate' => 18],
            ['id' => $this->uuid(), 'description' => 'Déduction', 'quantity' => -1, 'unit_price' => 30000, 'tax_rate' => 18, 'deposit_id' => $dep]]];
        $this->assertSame('rejected', $push($deduct($client))['status']);
        $autre = $api()->postJson('/api/core/partners', ['name' => 'Autre client', 'type' => 'customer'])->json('data.id');
        $this->assertSame('rejected', $push($deduct($autre))['status']);
    }

    public function test_document_templates_settings_and_premium_modules(): void
    {
        [$company, , $token] = $this->makeCompany('Akwaba');
        $tpl = ['id' => 'tpl_abc', 'name' => 'Mon modèle', 'style' => ['primary' => '#3B2F8F', 'header' => 'band'], 'blocks' => [['type' => 'header'], ['type' => 'text', 'text' => 'Bonjour'], ['type' => 'lines']], 'titles' => ['quote' => 'OFFRE']];
        $this->api($token)->putJson('/api/company', ['settings' => ['doc_templates' => [$tpl], 'doc_template_defaults' => ['sales_documents.quote' => 'tpl_abc', '*' => 'classique']]])->assertOk();
        $s = $company->fresh()->settings;
        $this->assertSame('Bonjour', $s['doc_templates'][0]['blocks'][1]['text']);
        $this->assertSame('tpl_abc', $s['doc_template_defaults']['sales_documents.quote']);
        // Bloc sans type : refusé
        $this->api($token)->putJson('/api/company', ['settings' => ['doc_templates' => [['id' => 'x', 'name' => 'X', 'blocks' => [['text' => 'a']]]]]])->assertStatus(422);

        // Modèle choisi sur un document
        $id = $this->uuid();
        $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $id,
            'data' => ['type' => 'quote', 'date' => now()->toDateString(), 'template' => 'prestige', 'lines' => [['id' => $this->uuid(), 'description' => 'X', 'quantity' => 1, 'unit_price' => 1000]]]]]])->assertJsonPath('results.0.status', 'applied');
        $this->assertSame('prestige', \App\Modules\Ventes\Models\SalesDocument::withoutGlobalScopes()->find($id)->template);

        // Modèles premium et studio : achat définitif dans la boutique, jamais réalignés sur un abonnement
        foreach (['modele_prestige' => 10000, 'modele_horizon' => 10000, 'modele_offre' => 10000, 'modele_ivoire' => 15000, 'modele_executive' => 20000, 'modele_onyx' => 20000, 'modele_royal' => 20000, 'modele_atelier' => 15000, 'modele_lagon' => 15000, 'modele_geometrie' => 15000, 'modele_monochrome' => 10000, 'modele_kente' => 15000, 'modele_oblique' => 15000, 'modele_azur' => 10000, 'modele_savane' => 15000, 'modele_sante' => 10000, 'modele_emeraude' => 15000, 'modele_corail' => 10000, 'modele_minuit' => 15000, 'modeles_collection' => 60000, 'modeles_studio' => 15000] as $key => $price) {
            $m = \App\Models\Module::where('key', $key)->first();
            $this->assertNotNull($m, $key);
            $this->assertSame(['one_time' => (float) $price], $m->offers());
        }
    }
}
