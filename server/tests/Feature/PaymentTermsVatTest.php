<?php

namespace Tests\Feature;

use App\Modules\Ventes\Models\SalesDocument;
use Carbon\Carbon;
use Tests\TestCase;

class PaymentTermsVatTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_payment_terms_schedules_dunning_vat_forecast_and_priorities(): void
    {
        Carbon::setTestNow('2026-11-04 09:00:00');
        [$company, , $token] = $this->makeCompany('Distribution Yamoussoukro');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');

        // Conditions par défaut créées au premier passage
        $terms = collect($api()->getJson('/api/core/payment-terms')->assertOk()->json('data'))->keyBy('name');
        $this->assertCount(7, $terms);
        $this->assertTrue($terms['30 jours']['is_default_sale'] && $terms['30 jours']['is_default_purchase']);
        $this->assertSame('30 % à réception, 70 % à 30 jours', $terms['Acompte 30 % à la commande, solde à 30 jours']['description']);
        $this->assertStringContainsString('100 %', $push('payment_terms', ['name' => 'Faux', 'lines' => [['percent' => 50, 'days' => 0], ['percent' => 40, 'days' => 30]]])['message']);
        $custom = $this->uuid();
        $this->assertSame('applied', $push('payment_terms', ['name' => 'Acompte 40 %, solde 30 j fin de mois', 'lines' => [['percent' => 60, 'days' => 30, 'end_of_month' => true], ['percent' => 40, 'days' => 0]]], $custom)['status']);
        $this->assertSame('40 % à réception, 60 % à 30 jours fin de mois', $api()->getJson("/api/core/payment-terms/$custom")->json('data.description'));
        $this->assertStringContainsString('par défaut', $push('payment_terms', ['active' => false], $terms['30 jours']['id'])['message']);

        $partner = fn (string $name, array $x = []) => $api()->postJson('/api/core/partners', ['name' => $name, 'type' => 'both'] + $x)->assertCreated()->json('data.id');
        $a = $partner('Client Échéancier', ['payment_term_id' => $custom]);
        $b = $partner('Client Lent');
        $c = $partner('Client Septembre');
        $sup = $partner('Fournisseur 45 FDM', ['supplier_payment_term_id' => $terms['45 jours fin de mois']['id']]);
        $api()->postJson('/api/core/partners', ['name' => 'X', 'payment_term_id' => $this->uuid()])->assertStatus(422);
        $doc = function (string $module, string $type, string $p, float $ht, string $date, array $x = []) use ($api) {
            $d = $api()->postJson("/api/$module/documents", ['type' => $type, 'partner_id' => $p, 'date' => $date, 'lines' => [['description' => 'Article', 'quantity' => 1, 'unit_price' => $ht, 'tax_rate' => 18]]] + $x)->assertCreated()->json('data');

            return $type === 'order' && ($x['draft'] ?? false) ? $d : $api()->postJson("/api/$module/documents/{$d['id']}/validate")->assertOk()->json('data');
        };

        // Historique : B a payé avec 40 jours de retard ; C en avance ; TVA d'août, septembre, octobre
        $old = $doc('ventes', 'invoice', $b, 50000, '2026-08-01', ['due_date' => '2026-08-31']);
        $api()->postJson('/api/ventes/payments', ['document_id' => $old['id'], 'date' => '2026-10-10', 'amount' => 59000, 'method' => 'cash'])->assertCreated();
        $sep = $doc('ventes', 'invoice', $c, 500000, '2026-09-01');
        $this->assertSame('2026-10-01', $sep['due_date']); // condition par défaut : 30 jours
        $api()->postJson('/api/ventes/payments', ['document_id' => $sep['id'], 'date' => '2026-09-20', 'amount' => 590000, 'method' => 'cash'])->assertCreated();
        $late = $doc('ventes', 'invoice', $b, 100000, '2026-10-01');
        $si = $doc('achats', 'supplier_invoice', $sup, 200000, '2026-10-10');
        $this->assertEquals([$terms['45 jours fin de mois']['id'], '2026-11-30'], [$si['payment_term_id'], $si['due_date']]); // 24/11 → fin de mois

        // Facture à échéancier : 40 % à réception, 60 % fin décembre
        $f1 = $doc('ventes', 'invoice', $a, 1000000, '2026-11-04');
        $this->assertSame($custom, $f1['payment_term_id']);
        $this->assertEquals([['date' => '2026-11-04', 'percent' => 40, 'amount' => 472000], ['date' => '2026-12-31', 'percent' => 60, 'amount' => 708000]], $f1['schedule']);
        $this->assertSame('2026-12-31', $f1['due_date']);
        $this->assertStringContainsString('ne peut plus être modifié', $push('sales_documents', ['payment_term_id' => $terms['60 jours']['id']], $f1['id'])['message']);
        // Condition choisie sur le document : elle prime sur celle du client
        $f2 = $doc('ventes', 'invoice', $a, 100000, '2026-11-04', ['payment_term_id' => $terms['Comptant (à réception)']['id']]);
        $this->assertEquals(['2026-11-04', null], [$f2['due_date'], $f2['schedule'][1] ?? null]);
        // Commande → facture : condition reprise
        $o = $doc('ventes', 'order', $a, 50000, '2026-11-04', ['draft' => true]);
        $this->assertSame($custom, $o['payment_term_id']);
        $f3 = $api()->postJson("/api/ventes/documents/{$o['id']}/convert", ['type' => 'invoice'])->assertSuccessful()->json('data');
        $this->assertSame($custom, $f3['payment_term_id']);
        $api()->postJson("/api/ventes/documents/{$f3['id']}/validate")->assertOk();

        // ---------------------------------------------------------------- relances sur échéances
        Carbon::setTestNow('2026-11-10 09:00:00');
        $p = $api()->getJson("/api/relances/partners/$a")->assertOk()->json('data');
        $inv1 = collect($p['invoices'])->firstWhere('id', $f1['id']);
        $this->assertEquals(['2026-11-04', 472000, 1180000, 6], [$inv1['due_date'], $inv1['overdue'], $inv1['balance'], $inv1['days']]);
        $aging = collect($api()->getJson('/api/relances/aging')->json('data.rows'))->keyBy('partner_id');
        $this->assertEquals(708000 + 35400, $aging[$a]['not_due']); // 60 % de f1 + 60 % de f3
        $this->assertEquals(472000 + 118000 + 23600, $aging[$a]['d30']);
        $api()->postJson('/api/ventes/payments', ['document_id' => $f1['id'], 'date' => '2026-11-10', 'amount' => 472000, 'method' => 'bank_transfer'])->assertCreated();
        $this->assertNull(collect($api()->getJson("/api/relances/partners/$a")->json('data.invoices'))->firstWhere('id', $f1['id']));
        $this->assertEquals(0, SalesDocument::find($f1['id'])->overdueAmount());

        // ---------------------------------------------------------------- prévision : échéances et TVA
        $fc = $api()->getJson('/api/tresorerie/forecast')->assertOk()->json('data');
        $this->assertSame('2026-11-09', $fc['weeks'][0]['week']);
        $find = fn (array $weeks, string $needle) => collect($weeks)->flatMap(fn ($w) => collect($w['details'])->map(fn ($d) => $d + ['week' => $w['week']]))->filter(fn ($d) => str_contains($d['label'], $needle))->values();
        $this->assertEquals([['2026-12-28', 708000]], $find($fc['weeks'], 'Facture '.$f1['number'])->map(fn ($d) => [$d['week'], $d['amount']])->all());
        $this->assertEquals(['2026-12-07', 118000], [$find($fc['weeks'], $late['number'])[0]['week'], $find($fc['weeks'], $late['number'])[0]['amount']]); // 31/10 + 40 j de retard habituel
        $this->assertEquals(['2026-11-30', -236000], [$find($fc['weeks'], 'Fournisseur')[0]['week'], $find($fc['weeks'], 'Fournisseur')[0]['amount']]);
        // TVA : octobre en crédit (18 000 − 36 000), novembre réel 207 000 > moyenne 27 000 → 189 000 le 15/12 ; décembre estimé 27 000 le 15/01
        $vat = $find($fc['weeks'], 'TVA');
        $this->assertEquals([['TVA novembre 2026 (estimée)', '2026-12-15', -189000], ['TVA décembre 2026 (estimée)', '2027-01-15', -27000]], $vat->map(fn ($d) => [$d['label'], $d['date'], $d['amount']])->all());
        $api()->putJson('/api/tresorerie/forecast-settings', ['vat_day' => 20])->assertOk();
        $this->assertSame('2026-12-20', $find($api()->getJson('/api/tresorerie/forecast')->json('data.weeks'), 'TVA')[0]['date']);
        $api()->putJson('/api/tresorerie/forecast-settings', ['vat_enabled' => false])->assertOk();
        $this->assertCount(0, $find($api()->getJson('/api/tresorerie/forecast')->json('data.weeks'), 'TVA'));

        // ---------------------------------------------------------------- relances prioritaires
        // Gros règlement fournisseur le 30/11 : le point bas tombe avant l'encaissement habituel de B (10/12)
        $doc('achats', 'supplier_invoice', $sup, 4000000, '2026-11-05', ['due_date' => '2026-11-30']);
        $pr = $api()->getJson('/api/relances/priorities')->assertOk()->json('data');
        $api()->putJson('/api/tresorerie/forecast-settings', ['threshold' => $pr['lowest'] + 50000])->assertOk();
        $pr = $api()->getJson('/api/relances/priorities')->assertOk()->json('data');
        $rows = collect($pr['rows'])->keyBy('partner_id');
        $this->assertSame($b, $pr['rows'][0]['partner_id']);
        $this->assertEquals([118000, 118000.0, true, 40, '2026-12-10', 10], [$rows[$b]['overdue'], $rows[$b]['low_gain'], $rows[$b]['fixes_threshold'], $rows[$b]['usual_delay'], $rows[$b]['expected_date'], $rows[$b]['days_late']]);
        $this->assertEquals([0.0, false], [$rows[$a]['low_gain'], $rows[$a]['fixes_threshold']]); // A paie habituellement à l'échéance : rien à gagner
        $this->assertEquals(118000 + 23600, $rows[$a]['overdue']);
        // Scénario prudent (+30 j) : A paierait le 04/12, la semaine du point bas → rien à gagner ; B (09/01) relève le point bas prudent
        $this->assertEquals([0, 118000], [$rows[$a]['low_gain_pessimistic'], $rows[$b]['low_gain_pessimistic']]);
    }
}
