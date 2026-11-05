<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Modules\Abonnements\Models\Subscription;
use App\Modules\Relances\Models\Reminder;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RelancesAbonnementsTest extends TestCase
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
        parent::tearDown();
    }

    private function setupCompany(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Distribution Kouassi');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.kouassi.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'compta@kouassi.ci', 'password' => 'x', 'from_email' => 'compta@kouassi.ci', 'from_name' => 'Distribution Kouassi',
        ]])->assertOk();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $api()->putJson('/api/messaging/channels/whatsapp', ['provider' => 'meta', 'settings' => ['phone_number_id' => '77', 'access_token' => 'tok']])->assertOk();

        return [$company, $admin, $token, $api];
    }

    private function invoice($api, string $partner, int $daysAgoDue, float $price): string
    {
        $id = $this->uuid();
        $r = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $id, 'data' => [
            'type' => 'invoice', 'partner_id' => $partner, 'date' => now()->subDays($daysAgoDue + 30)->toDateString(), 'due_date' => now()->subDays($daysAgoDue)->toDateString(),
            'status' => 'validated', 'lines' => [['description' => 'Marchandises', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 0]]]]]])->json('results.0');
        $this->assertSame('applied', $r['status'], json_encode($r));

        return $id;
    }

    public function test_dunning_levels_grouped_messages_aging_pause_manual_and_history(): void
    {
        $this->travelTo(now()->next('Tuesday')->setTime(9, 15));
        [, , , $api] = $this->setupCompany();
        $p = fn (array $d) => $api()->postJson('/api/core/partners', $d + ['type' => 'customer'])->assertCreated()->json('data.id');
        $bamba = $p(['name' => 'Supérette Bamba', 'email' => 'bamba@mail.ci', 'phone' => '+225 07 01 02 03 04']);
        $kone = $p(['name' => 'Kiosque Koné', 'phone' => '+225 05 99 88 77 66']);
        $muet = $p(['name' => 'Client sans contact']);
        $nego = $p(['name' => 'Hôtel en négociation', 'email' => 'hotel@mail.ci']);

        $b1 = $this->invoice($api, $bamba, 5, 150000);   // palier 1 (J+3)
        $b2 = $this->invoice($api, $bamba, 20, 80000);   // palier 2 (J+15)
        $this->invoice($api, $bamba, -10, 50000);        // pas encore échue
        $k1 = $this->invoice($api, $kone, 40, 30000);    // palier 3 (J+30)
        $this->invoice($api, $kone, 1, 500);              // sous le montant minimum, échue d'1 jour
        $this->invoice($api, $muet, 50, 20000);
        $this->invoice($api, $nego, 70, 900000);
        $api()->postJson("/api/relances/partners/$nego/pause", ['paused' => true])->assertOk();

        // Balance âgée
        $aging = $api()->getJson('/api/relances/aging')->assertOk()->json('data');
        $row = collect($aging['rows'])->keyBy('name');
        $this->assertEquals(50000, $row['Supérette Bamba']['not_due']);
        $this->assertEquals(230000, $row['Supérette Bamba']['d30']);
        $this->assertEquals(30000, $row['Kiosque Koné']['d60']);
        $this->assertEquals(900000, $row['Hôtel en négociation']['d90']);
        $this->assertTrue($row['Hôtel en négociation']['paused']);
        $this->assertEquals(1230500, $aging['totals']['total']);

        // Tâche du jour : un message groupé par client, au palier le plus élevé
        $this->artisan('erp:dunning')->assertSuccessful();
        $rem = Reminder::withoutGlobalScopes()->get()->keyBy('partner_id');
        $this->assertCount(2, $rem);
        $this->assertSame(2, $rem[$bamba]->level);
        $this->assertSame('Relance', $rem[$bamba]->level_name);
        $this->assertCount(2, $rem[$bamba]->documents);
        $this->assertSame(['email' => 'ok', 'whatsapp' => 'ok'], $rem[$bamba]->channels);
        $this->assertSame(3, $rem[$kone]->level);
        $this->assertSame(['whatsapp' => 'ok'], $rem[$kone]->channels);
        $this->assertCount(1, $rem[$kone]->documents); // facture de 500 F sous le minimum
        $mail = $this->mails->messages()->values()[0]->getOriginalMessage();
        $this->assertStringContainsString('Relance : factures impayées', $mail->getSubject());
        $this->assertStringContainsString('230 000 F CFA', $mail->getTextBody());
        $this->assertStringContainsString('/pay/', $mail->getHtmlBody());
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'procédure de recouvrement'));
        $this->assertSame(2, \DB::table('trade_documents')->where('id', $b2)->value('dunning_level'));
        $this->assertSame(1, \DB::table('trade_documents')->where('id', $b1)->value('dunning_level'));

        // Le lendemain : rien de nouveau, aucun doublon
        $this->travel(1)->day();
        $this->artisan('erp:dunning')->assertSuccessful();
        $this->assertSame(2, Reminder::withoutGlobalScopes()->count());
        // Week-end : pas d'envoi
        $this->travelTo(now()->next('Saturday')->setTime(9, 15));
        $this->artisan('erp:dunning')->assertSuccessful();
        $this->assertSame(2, Reminder::withoutGlobalScopes()->count());
        // Lundi d'après (b1 à J+18, b2 à J+33) : nouvelle relance de Bamba au palier 3, rien pour Koné (déjà au palier 3)
        $this->travelTo(now()->next('Monday')->addWeek()->setTime(9, 15));
        $this->artisan('erp:dunning')->assertSuccessful();
        $this->assertSame(3, Reminder::withoutGlobalScopes()->count());
        $last = Reminder::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame($bamba, $last->partner_id);
        $this->assertSame(3, $last->level);

        // Relance manuelle avec aperçu, client sans contact refusé, paiement → plus de relance
        $prev = $api()->getJson("/api/relances/partners/$kone/preview?level=1")->assertOk()->json('data');
        $this->assertStringContainsString('Kiosque Koné', $prev['text']);
        $api()->postJson("/api/relances/partners/$kone/send", ['level' => 1])->assertOk()->assertJsonPath('data.manual', true);
        $api()->postJson("/api/relances/partners/$muet/send")->assertStatus(422)->assertJsonFragment(['message' => 'Aucun moyen de joindre ce client : renseignez son e-mail ou son téléphone, et configurez un canal d\'envoi.']);
        $api()->postJson('/api/ventes/payments', ['document_id' => $k1, 'date' => now()->toDateString(), 'amount' => 30000, 'method' => 'cash'])->assertCreated();
        $this->assertCount(1, $api()->postJson("/api/relances/partners/$kone/send")->assertOk()->json('data.documents')); // reste la petite facture
        $detail = $api()->getJson("/api/relances/partners/$bamba")->assertOk()->json('data');
        $this->assertCount(3, $detail['invoices']); // la 3e est échue depuis
        $this->assertGreaterThanOrEqual(2, count($detail['history']));
        $this->assertNotEmpty($api()->getJson('/api/relances/history')->json('data'));

        // Paramètres : paliers personnalisés, délais en double refusés
        $api()->putJson('/api/relances/settings', ['levels' => [['days' => 7, 'name' => 'A', 'body' => 'x'], ['days' => 7, 'name' => 'B', 'body' => 'y']]])->assertStatus(422);
        $s = $api()->putJson('/api/relances/settings', ['min_amount' => 0, 'levels' => [['days' => 60, 'name' => 'Mise en demeure', 'subject' => 'Mise en demeure', 'body' => 'Bonjour {client}, total {total}', 'channels' => ['email']]]])->assertOk()->json('data');
        $this->assertCount(1, $s['levels']);
    }

    public function test_subscriptions_generate_invoices_catch_up_send_end_and_mrr(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 15)->setTime(6, 20));
        [, , $token, $api] = $this->setupCompany();
        $client = $api()->postJson('/api/core/partners', ['name' => 'Clinique Les Palmiers', 'type' => 'customer', 'email' => 'compta@palmiers.ci'])->assertCreated()->json('data.id');
        $push = fn (array $m) => $api()->postJson('/api/sync/push', ['mutations' => array_map(fn ($x) => $x + ['id' => $this->uuid(), 'op' => 'upsert'], $m)])->assertOk()->json('results');

        // Contrat de maintenance mensuel démarré au 1er décembre : deux échéances à rattraper
        $sub = $this->uuid();
        $r = $push([['entity' => 'ab_subscriptions', 'record_id' => $sub, 'data' => ['partner_id' => $client, 'name' => 'Maintenance informatique', 'start_date' => '2025-12-01',
            'interval_unit' => 'month', 'interval_count' => 1, 'max_invoices' => 3, 'auto_validate' => true, 'auto_send' => true,
            'lines' => [['description' => 'Forfait maintenance', 'quantity' => 1, 'unit_price' => 250000, 'tax_rate' => 18]]]]])[0];
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertStringStartsWith('ABO-2025', $r['record']['number']);
        $this->assertEquals(250000, $r['record']['period_amount']);
        // Contrat annuel en euros
        $lic = $this->uuid();
        $push([['entity' => 'ab_subscriptions', 'record_id' => $lic, 'data' => ['partner_id' => $client, 'name' => 'Licence logiciel', 'start_date' => '2026-02-01', 'interval_unit' => 'year',
            'currency' => 'EUR', 'auto_validate' => false, 'lines' => [['description' => 'Licence annuelle', 'quantity' => 1, 'unit_price' => 1200, 'tax_rate' => 0]]]]]);
        $stats = $api()->getJson('/api/abonnements/stats')->assertOk()->json('data');
        $this->assertSame(2, $stats['active']);
        $this->assertEquals(round(250000 + 1200 * 655.957 / 12), $stats['mrr']);

        $this->artisan('erp:subscriptions')->assertSuccessful();
        $invoices = $api()->getJson("/api/abonnements/subscriptions/$sub/invoices")->assertOk()->json('data');
        $this->assertCount(2, $invoices);
        $this->assertSame('validated', $invoices[0]['status']);
        $this->assertSame('2026-01-01', $invoices[0]['period_start']);
        $this->assertSame('2026-01-31', $invoices[0]['period_end']);
        $this->assertEquals(295000, $invoices[0]['total']);
        $s = Subscription::withoutGlobalScopes()->find($sub);
        $this->assertSame('2026-02-01', $s->next_date->toDateString());
        $this->assertSame(2, $s->generated_count);
        $this->assertCount(2, $this->mails->messages()); // facture envoyée avec le lien de paiement
        $this->assertStringContainsString('/pay/', $this->mails->messages()->values()[0]->getOriginalMessage()->getHtmlBody());
        $doc = \DB::table('trade_documents')->where('id', $invoices[1]['id'])->first();
        $this->assertStringContainsString('Période du 01/12/2025 au 31/12/2025', $doc->notes);
        $this->assertSame($s->number, $doc->reference);

        // Rien de plus le même jour
        $this->artisan('erp:subscriptions')->assertSuccessful();
        $this->assertSame(2, Subscription::withoutGlobalScopes()->find($sub)->generated_count);

        // Échéance générée en avance : 3e facture = fin du contrat (3 échéances)
        $api()->postJson("/api/abonnements/subscriptions/$sub/generate")->assertOk()->assertJsonPath('data.status', 'validated');
        $this->assertSame('ended', Subscription::withoutGlobalScopes()->find($sub)->status);
        $api()->postJson("/api/abonnements/subscriptions/$sub/generate")->assertStatus(422);
        $this->assertSame('rejected', $push([['entity' => 'ab_subscriptions', 'record_id' => $sub, 'data' => ['status' => 'active']]])[0]['status']);
        $this->assertSame('rejected', $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'ab_subscriptions', 'op' => 'delete', 'record_id' => $sub]]])->json('results.0.status'));

        // Contrat en euros au 1er février : brouillon en devise, non envoyé
        $this->travelTo(now()->setDate(2026, 2, 1));
        $this->artisan('erp:subscriptions')->assertSuccessful();
        $li = $api()->getJson("/api/abonnements/subscriptions/$lic/invoices")->json('data');
        $this->assertCount(1, $li);
        $this->assertSame('draft', $li[0]['status']);
        $this->assertSame('EUR', $li[0]['currency']);
        $this->assertSame('2027-02-01', Subscription::withoutGlobalScopes()->find($lic)->next_date->toDateString());

        // Contrat en pause : pas de facture ; contrat sans client valide refusé
        $push([['entity' => 'ab_subscriptions', 'record_id' => $lic, 'data' => ['status' => 'paused']]]);
        $this->travelTo(now()->setDate(2027, 3, 1));
        $this->artisan('erp:subscriptions')->assertSuccessful();
        $this->assertCount(1, $api()->getJson("/api/abonnements/subscriptions/$lic/invoices")->json('data'));
        $this->assertSame('rejected', $push([['entity' => 'ab_subscriptions', 'record_id' => $this->uuid(), 'data' => ['partner_id' => $this->uuid(), 'name' => 'X', 'start_date' => '2026-01-01', 'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1]]]]])[0]['status']);
    }
}
