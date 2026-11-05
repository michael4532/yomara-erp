<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\AppNotification;
use App\Modules\Relances\Models\Reminder;
use App\Modules\Tresorerie\Momo\MomoMessage;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use Carbon\Carbon;
use Illuminate\Mail\Transport\ArrayTransport;
use Tests\TestCase;

class MomoUpcomingTest extends TestCase
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

    public function test_mobile_money_sms_matching_installment_payment_page_and_pre_due_reminders(): void
    {
        Carbon::setTestNow('2026-11-02 08:00:00'); // lundi
        [$company, $admin, $token] = $this->makeCompany('Quincaillerie Adjamé');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $terms = collect($api()->getJson('/api/core/payment-terms')->json('data'))->pluck('id', 'name');
        $partner = fn (string $name, array $x = []) => $api()->postJson('/api/core/partners', ['name' => $name, 'type' => 'customer'] + $x)->assertCreated()->json('data.id');
        $a = $partner('Kouassi Jean', ['phone' => '07 07 12 34 56', 'email' => 'kouassi@exemple.ci', 'payment_term_id' => $terms['Acompte 30 % à la commande, solde à 30 jours']]);
        $b = $partner('Société Kouamé');
        $c = $partner('Maquis Le Bon Coin', ['phone' => '+225 05 05 44 33 22']);
        $inv = function (string $p, float $ht, string $date = '2026-11-02', array $x = []) use ($api) {
            $d = $api()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $p, 'date' => $date, 'lines' => [['description' => 'Matériel', 'quantity' => 1, 'unit_price' => $ht, 'tax_rate' => 18]]] + $x)->assertCreated()->json('data');

            return $api()->postJson("/api/ventes/documents/{$d['id']}/validate")->assertOk()->json('data');
        };
        $i1 = $inv($a, 1000000); // 354 000 aujourd'hui, 826 000 le 02/12
        $i2 = $inv($b, 100000);
        $c1 = $inv($c, 50000, '2026-10-20');
        $c2 = $inv($c, 50000, '2026-10-25');

        // Jeton du compte Mobile Money (réservé à ce type de compte)
        $api()->getJson('/api/tresorerie/summary')->assertOk(); // comptes par défaut
        $accounts = collect($api()->getJson('/api/tresorerie/accounts')->json('data'))->keyBy('kind');
        $mm = $accounts['mobile_money']['id'];
        $this->assertArrayNotHasKey('momo_token', $accounts['mobile_money']);
        $api()->postJson("/api/tresorerie/accounts/{$accounts['bank']['id']}/momo-token")->assertStatus(422);
        $url = $api()->postJson("/api/tresorerie/accounts/$mm/momo-token")->assertOk()->json('data.url');
        $this->assertSame($url, $api()->postJson("/api/tresorerie/accounts/$mm/momo-token")->json('data.url')); // stable
        $hook = parse_url($url, PHP_URL_PATH);
        $sms = function (array $payload) use ($hook) {
            $this->flushHeaders();
            app()->forgetInstance('tenant');

            return $this->postJson($hook, $payload);
        };

        // 1. Orange Money : numéro du client + montant de l'échéance → encaissement automatique
        $r = $sms(['from' => 'OrangeMoney', 'message' => 'Vous avez recu 354000 FCFA du 0707123456 (KOUASSI JEAN). Ref: CI2611.0801.A1234. Nouveau solde: 1 254 000 FCFA.'])->assertOk()->json();
        $this->assertEquals(['matched', 354000, $i1['number']], [$r['status'], $r['amount'], $r['document']]);
        $pay = SalesPayment::withoutGlobalScopes()->where('document_id', $i1['id'])->first();
        $this->assertEquals([354000, 'mobile_money', $mm, '2026-11-02'], [(float) $pay->amount, $pay->method, $pay->treasury_account_id, $pay->date->toDateString()]);
        $this->assertStringContainsString('Orange Money CI2611.0801.A1234', $pay->reference);
        // Même SMS renvoyé → doublon
        $this->assertSame('duplicate', $sms(['from' => 'OrangeMoney', 'message' => 'Vous avez recu 354000 FCFA du 0707123456 (KOUASSI JEAN). Ref: CI2611.0801.A1234. Nouveau solde: 1 254 000 FCFA.'])->json('status'));

        // 2. MTN : n° de facture cité dans le message ; le numéro du client est retenu sur sa fiche
        $r = $sms(['sender' => 'MTN MoMo', 'text' => "Vous avez reçu 118 000 FCFA de SOCIETE KOUAME (2250101010101). Transaction ID: 99887766. Message: {$i2['number']}. Solde: 2 100 000 FCFA", 'sentStamp' => Carbon::parse('2026-11-02 07:30')->getTimestampMs()])->assertOk()->json();
        $this->assertEquals(['matched', $i2['number']], [$r['status'], $r['document']]);
        $this->assertSame('paid', SalesDocument::withoutGlobalScopes()->find($i2['id'])->payment_status);
        $this->assertSame('0101010101', $api()->getJson("/api/core/partners/$b")->json('data.phone'));

        // 3. Wave : deux factures de même montant pour ce client → à traiter, avec suggestions
        $r = $sms(['from' => 'Wave', 'text' => 'Vous avez reçu 59.000F de Maquis Bon Coin (05 05 44 33 22). Nouveau solde : 1.313.000F. ID: T_ABC123XYZ'])->assertOk()->json();
        $this->assertSame('pending', $r['status']);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'Encaissement Mobile Money à rapprocher')->exists());
        // 4. Montant inconnu → à traiter, sans suggestion ; SMS sortant ; SMS vide ; jeton inconnu
        $sms(['from' => 'Wave', 'text' => 'Vous avez reçu 25.000F de Inconnu Paul (07 99 99 99 99). Nouveau solde : 1.338.000F.'])->assertOk()->assertJsonPath('status', 'pending');
        $sms(['from' => 'Wave', 'text' => 'Vous avez envoyé 10.000F à Jean (07 00 00 00 00). Frais : 100F. Nouveau solde : 1.328.000F'])->assertOk()->assertJsonPath('status', 'outgoing');
        $sms(['from' => 'Wave', 'text' => ''])->assertStatus(422);
        $this->flushHeaders();
        $this->postJson('/api/hooks/momo/'.str_repeat('x', 40), ['text' => 'Vous avez reçu 1000F de X (0700000000)'])->assertNotFound();

        $list = $api()->getJson('/api/tresorerie/momo?status=pending')->assertOk()->json();
        $this->assertCount(2, $list['data']);
        $this->assertEquals(['matched' => 2, 'pending' => 2, 'duplicate' => 1, 'outgoing' => 1], $list['counts']);
        $wave = collect($list['data'])->firstWhere('amount', 59000);
        $this->assertCount(2, $wave['candidates']);
        $this->assertSame($c1['number'], $wave['candidates'][0]['number']); // la plus ancienne d'abord
        $this->assertSame('Maquis Le Bon Coin', $wave['partner_name']);
        $api()->postJson("/api/tresorerie/momo/{$wave['id']}/assign", ['document_id' => $c1['id']])->assertOk()->assertJsonPath('data.status', 'matched');
        $api()->postJson("/api/tresorerie/momo/{$wave['id']}/assign", ['document_id' => $c2['id']])->assertStatus(422);
        $unknown = collect($list['data'])->firstWhere('amount', 25000);
        $this->assertSame([], $unknown['candidates']);
        $api()->postJson("/api/tresorerie/momo/{$unknown['id']}/operation", ['category' => 'other_income', 'label' => 'Remboursement avance Paul'])->assertOk()->assertJsonPath('data.status', 'matched');
        $this->assertEquals(354000 + 118000 + 59000 + 25000, collect($api()->getJson('/api/tresorerie/summary')->json('data'))->firstWhere('id', $mm)['balance']);
        // Saisie manuelle d'un SMS et essai de lecture
        $api()->postJson('/api/tresorerie/momo', ['account_id' => $mm, 'text' => 'Vous avez recu un transfert de 59000 FCFA du 0505443322. Ref: MV250612345.'])->assertCreated()->assertJsonPath('data.status', 'matched'); // seule facture restante de ce montant
        $this->assertSame('paid', SalesDocument::withoutGlobalScopes()->find($c2['id'])->payment_status);
        $t = $api()->postJson('/api/tresorerie/momo/test', ['sender' => 'MTN', 'text' => 'Vous avez reçu 590 000 FCFA de KONE MOUSSA (2250505123456). Transaction ID: 8812345671.'])->assertOk()->json('data');
        $this->assertEquals(['in', 590000, '0505123456', 'KONE MOUSSA', '8812345671', 'mtn'], [$t['direction'], $t['amount'], $t['phone'], $t['name'], $t['reference'], $t['provider']]);

        // ---------------------------------------------------------------- paiement de l'échéance en ligne
        $i3 = $inv($b, 500000, '2026-10-01', ['payment_term_id' => $terms['50 % à 30 jours, 50 % à 60 jours']]); // 295 000 le 31/10 (échu) + 295 000 le 30/11
        $doc = SalesDocument::withoutGlobalScopes()->find($i3['id']);
        app()->instance('tenant', $company);
        $this->assertEquals([295000, 590000], [$doc->amountDueNow(), $doc->balance()]);
        $link = app(\App\Payments\InvoicePayments::class)->link($doc);
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get('/pay/'.$link->token)->assertOk()->assertSee('Échéance à régler')->assertSee('295 000 F CFA')->assertSee('Échéancier');

        // ---------------------------------------------------------------- rappels avant échéance
        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.q.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'c@q.ci', 'password' => 'x', 'from_email' => 'c@q.ci', 'from_name' => 'Quincaillerie',
        ]])->assertOk();
        $this->assertEquals(['enabled' => true, 'days' => 3], array_intersect_key($api()->getJson('/api/relances/settings')->json('data.before'), ['enabled' => 1, 'days' => 1]));
        Carbon::setTestNow('2026-11-29 09:00:00'); // dimanche : rien
        $this->artisan('erp:dunning')->assertSuccessful();
        $this->assertSame(0, Reminder::withoutGlobalScopes()->where('level', 0)->count());
        Carbon::setTestNow('2026-11-30 09:00:00'); // lundi : échéance du 02/12 dans la fenêtre de 3 jours
        $this->artisan('erp:dunning')->assertSuccessful();
        $rem = Reminder::withoutGlobalScopes()->where('level', 0)->where('partner_id', $a)->get();
        $this->assertCount(1, $rem);
        $this->assertEquals(['Rappel avant échéance', 'sent', ['email' => 'ok'], '2026-12-02', 826000], [$rem[0]->level_name, $rem[0]->status, $rem[0]->channels, $rem[0]->documents[0]['due'], $rem[0]->documents[0]['balance']]);
        $mail = collect($this->mails->messages())->last()->toString();
        $this->assertStringContainsString('826 000 F CFA le 02/12/2026', quoted_printable_decode($mail));
        $this->artisan('erp:dunning')->assertSuccessful();
        $this->assertSame(1, Reminder::withoutGlobalScopes()->where('level', 0)->where('partner_id', $a)->count()); // une seule fois par échéance
        // Désactivable
        $api()->putJson('/api/relances/settings', ['before' => ['enabled' => false, 'days' => 5, 'body' => 'x {factures}']])->assertOk()->assertJsonPath('data.before.enabled', false);
    }
}
