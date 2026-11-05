<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\AppNotification;
use App\Modules\Core\Models\Partner;
use Carbon\Carbon;
use Illuminate\Mail\Transport\ArrayTransport;
use Tests\TestCase;

class RecoveryTest extends TestCase
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

    public function test_recovery_case_promises_plan_notice_bailiff_payments_write_off_and_dashboard(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        [, , $token] = $this->makeCompany('Distribution Lagune');
        $api = fn () => $this->api($token);
        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.l.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'l@l.ci', 'password' => 'x', 'from_email' => 'compta@lagune.ci', 'from_name' => 'Distribution Lagune',
        ]])->assertOk();
        $client = $api()->postJson('/api/core/partners', ['name' => 'Supermarché Bonprix', 'type' => 'customer', 'email' => 'direction@bonprix.ci', 'phone' => '0707070707'])->json('data.id');
        $client2 = $api()->postJson('/api/core/partners', ['name' => 'Boutique Disparue', 'type' => 'customer'])->json('data.id');
        $client3 = $api()->postJson('/api/core/partners', ['name' => 'Bon Payeur SARL', 'type' => 'customer'])->json('data.id');
        $invoice = function (string $partner, int $daysAgo, float $amount) use ($api) {
            $d = $api()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $partner, 'date' => now()->subDays($daysAgo)->toDateString(), 'due_date' => now()->subDays($daysAgo - 30)->toDateString(),
                'lines' => [['description' => 'Marchandises', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0]]])->assertCreated()->json('data');

            return $api()->postJson("/api/ventes/documents/{$d['id']}/validate")->assertOk()->json('data');
        };
        $i1 = $invoice($client, 150, 400000);
        $i2 = $invoice($client, 110, 200000);
        $invoice($client2, 200, 90000);
        $invoice($client3, 40, 50000); // en retard de 10 jours seulement : pas encore en contentieux

        // Clients à mettre en recouvrement (retard ≥ 60 jours)
        $cand = $api()->getJson('/api/relances/recovery/candidates')->assertOk()->json('data');
        $this->assertSame(['Supermarché Bonprix', 'Boutique Disparue'], array_column($cand, 'name'));
        $this->assertEquals(600000, $cand[0]['amount']);

        // Ouverture du dossier : relances automatiques suspendues
        $case = $api()->postJson('/api/relances/cases', ['partner_id' => $client, 'note' => 'Client injoignable depuis septembre.'])->assertCreated()->json('data');
        $this->assertStringStartsWith('REC-2026-', $case['number']);
        $this->assertEquals(600000, $case['balance']);
        $this->assertCount(2, $case['invoices']);
        $this->assertTrue(Partner::find($client)->dunning_paused);
        $api()->postJson('/api/relances/cases', ['partner_id' => $client])->assertStatus(422);
        $this->assertSame(['Boutique Disparue'], array_column($api()->getJson('/api/relances/recovery/candidates')->json('data'), 'name'));
        $id = $case['id'];

        // Appel puis promesse ; encaissement partiel
        $api()->postJson("/api/relances/cases/$id/actions/call")->assertStatus(422);
        $api()->postJson("/api/relances/cases/$id/actions/call", ['body' => 'Joint le gérant : difficultés de trésorerie, propose 150 000 vendredi.', 'outcome' => 'reached'])->assertOk();
        $case = $api()->postJson("/api/relances/cases/$id/actions/promise", ['amount' => 150000, 'promise_date' => '2026-11-14', 'body' => 'Promesse du gérant'])->assertOk()->json('data');
        $this->assertSame('promise', $case['status']);
        $this->assertSame(['date' => '2026-11-14', 'amount' => 150000], $case['promise']);
        $case = $api()->postJson("/api/relances/cases/$id/actions/payment", ['amount' => 100000, 'method' => 'mobile_money', 'reference' => 'OM-55871'])->assertOk()->json('data');
        $this->assertEquals(500000, $case['balance']);
        $this->assertEquals(100000, $case['recovered']);
        $this->assertEquals(100000, $api()->getJson("/api/ventes/documents/{$i1['id']}")->json('data.amount_paid'));
        $api()->postJson("/api/relances/cases/$id/actions/payment", ['amount' => 900000])->assertStatus(422);

        // Promesse non tenue (100 000 sur 150 000) : constatée par le contrôle quotidien
        Carbon::setTestNow('2026-11-16 08:00:00');
        $this->artisan('erp:recovery-check')->assertExitCode(0);
        $case = $api()->getJson("/api/relances/cases/$id")->json('data');
        $this->assertSame('open', $case['status']);
        $this->assertSame('broken', collect($case['actions'])->firstWhere('type', 'promise')['promise_status']);
        $this->assertTrue(AppNotification::where('title', 'Promesse de paiement non tenue')->exists());

        // Échéancier : 4 mensualités ; la première payée
        $case = $api()->postJson("/api/relances/cases/$id/actions/plan", ['count' => 4, 'first_date' => '2026-11-30', 'every' => 'month'])->assertOk()->json('data');
        $this->assertSame('plan', $case['status']);
        $this->assertEquals([125000, 125000, 125000, 125000], array_column($case['installments'], 'amount'));
        $this->assertSame(['2026-11-30', '2026-12-30', '2027-01-30', '2027-02-28'], array_column($case['installments'], 'due_date'));
        $case = $api()->postJson("/api/relances/cases/$id/actions/payment", ['amount' => 125000, 'method' => 'bank_transfer', 'date' => '2026-11-29'])->assertOk()->json('data');
        $this->assertEquals([125000, 0, 0, 0], array_column($case['installments'], 'paid'));
        $this->assertEquals(['due_date' => '2026-12-30', 'amount' => 125000], $case['next_installment']);

        // Échéance de décembre manquée → mise en demeure envoyée par e-mail avec le PDF, puis huissier
        Carbon::setTestNow('2027-01-05 08:00:00');
        $this->artisan('erp:recovery-check')->assertExitCode(0);
        $this->assertTrue(AppNotification::where('title', 'Échéance de recouvrement impayée')->exists());
        $case = $api()->postJson("/api/relances/cases/$id/actions/notice", ['days' => 8, 'send' => true])->assertOk()->json('data');
        $this->assertSame('notice', $case['status']);
        $this->assertSame('2027-01-13', $case['notice_deadline']);
        $mail = collect($this->mails->messages())->last()->getOriginalMessage();
        $this->assertSame('direction@bonprix.ci', $mail->getTo()[0]->getAddress());
        $this->assertCount(1, $mail->getAttachments());
        $pdf = $api()->get("/api/relances/cases/$id/notice")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        Carbon::setTestNow('2027-01-15 08:00:00');
        $this->artisan('erp:recovery-check')->assertExitCode(0);
        $this->assertTrue(AppNotification::where('title', 'Mise en demeure restée sans effet')->exists());
        $case = $api()->postJson("/api/relances/cases/$id/actions/bailiff", ['bailiff' => 'Me Koné, huissier à Abidjan Plateau', 'amount' => 35000])->assertOk()->json('data');
        $this->assertSame('bailiff', $case['status']);
        $this->assertEquals(35000, $case['fees']);

        // Paiement du solde chez l'huissier : dossier recouvré, relances rétablies
        $case = $api()->postJson("/api/relances/cases/$id/actions/payment", ['amount' => 375000, 'method' => 'cheque'])->assertOk()->json('data');
        $this->assertSame('recovered', $case['status']);
        $this->assertEquals(100, $case['rate']);
        $this->assertFalse(Partner::find($client)->dunning_paused);
        $api()->postJson("/api/relances/cases/$id/actions/call", ['body' => 'x'])->assertStatus(422);

        // Créance irrécouvrable : passage en perte, écriture 651 / 411
        $c2 = $api()->postJson('/api/relances/cases', ['partner_id' => $client2])->json('data');
        $api()->postJson("/api/relances/cases/{$c2['id']}/actions/write_off")->assertStatus(422);
        $c2 = $api()->postJson("/api/relances/cases/{$c2['id']}/actions/write_off", ['body' => 'Boutique fermée, gérant introuvable.'])->assertOk()->json('data');
        $this->assertSame('written_off', $c2['status']);
        $this->assertEquals(90000, $c2['written_off']);
        $od = collect($api()->getJson('/api/compta/entries?journal=OD&from=2027-01-01&to=2027-12-31')->json('data'))->firstWhere('reference', $c2['number']);
        $this->assertEquals(['651' => 90000, '411' => -90000], collect($od['lines'])->mapWithKeys(fn ($l) => [$l['account']['code'] => $l['debit'] ?: -$l['credit']])->all());

        // Tableau de bord du recouvrement
        $d = $api()->getJson('/api/relances/recovery/dashboard?from=2026-01-01&to=2027-01-31')->assertOk()->json('data');
        $this->assertSame(0, $d['kpis']['open']);
        $this->assertEquals(600000, $d['kpis']['recovered']);
        $this->assertEquals(90000, $d['kpis']['written_off']);
        $this->assertSame(1, $d['kpis']['promises_broken']);
        $this->assertSame(['recovered', 'written_off'], array_column($d['by_status'], 'status'));
        $this->assertCount(12, $d['months']);
        $this->assertCount(2, $api()->getJson('/api/relances/cases?status=closed')->json('data'));
    }
}
