<?php

namespace Tests\Feature;

use App\Models\ModuleOrder;
use App\Services\SiteTraffic;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Activation manuelle par l'éditeur : prix au choix, mode de paiement, reçu PDF envoyé au client et téléchargeable. */
class ManualGrantReceiptTest extends TestCase
{
    public function test_grant_with_custom_price_payment_method_and_pdf_receipt(): void
    {
        [, , $sa] = $this->makeCompany('Yomara Technologies', 'entreprise', true);
        SiteTraffic::editorCompany()->update(['address' => 'Yopougon', 'city' => 'Abidjan', 'phone' => '+225 01 40 55 90 50', 'email' => 'contact@yomara.ci', 'trade_register' => 'CI-ABJ-2024-B-1234', 'tax_id' => '2400000X']);
        [$c, , $t] = $this->makeCompany('Boutique Awa', 'starter');
        $c->update(['email' => 'compta@awa.ci']);
        config(['mail.default' => 'array']);
        Mail::purge('array');

        // Prix proposé par défaut dans la fenêtre : celui du module ; l'éditeur le change (ici plus cher)
        $mods = collect($this->api($sa)->getJson('/api/admin/companies/'.$c->id.'/modules')->assertOk()->json('data'))->keyBy('key');
        $this->assertArrayHasKey('price_yearly', $mods['crm']);
        $this->assertArrayHasKey('billing_mode', $mods['crm']);
        $r = $this->api($sa)->postJson('/api/admin/companies/'.$c->id.'/grant', [
            'type' => 'module', 'key' => 'crm', 'months' => 3, 'amount' => 22500, 'payment_method' => 'wave', 'reference' => 'WV-778899',
        ])->assertOk();
        $this->assertStringContainsString('Reçu envoyé', $r->json('message'));
        $o = ModuleOrder::where('company_id', $c->id)->latest('id')->first();
        $this->assertSame(22500.0, $o->amount);
        $this->assertSame('wave', $o->payment_method);
        $this->assertSame('WV-778899', $o->payer_reference);
        $this->assertSame('Wave', $o->paymentLabel());

        // E-mail au client : paiement confirmé, mode de paiement, reçu PDF joint
        $mails = $this->capturedMails();
        $toClient = collect($mails)->first(fn ($m) => in_array('compta@awa.ci', $m['to'], true));
        $this->assertNotNull($toClient, 'aucun e-mail au client');
        $this->assertStringContainsString('Paiement reçu', $toClient['subject']);
        $this->assertStringContainsString('Wave', $toClient['html']);
        $this->assertSame('Recu-'.$o->number.'.pdf', $toClient['attachments'][0]['name']);
        $this->assertStringStartsWith('%PDF', $toClient['attachments'][0]['data']);

        // Reçu téléchargeable : par l'éditeur, par le client lui-même ; pas par une autre société
        $pdf = $this->api($sa)->get('/api/admin/store/orders/'.$o->id.'/receipt')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        if (getenv('RECEIPT_OUT')) {
            file_put_contents(getenv('RECEIPT_OUT'), $pdf->getContent());
        }
        $this->api($t)->get('/api/store/orders/'.$o->id.'/receipt')->assertOk();
        [, , $other] = $this->makeCompany('Autre', 'starter');
        $this->api($other)->get('/api/store/orders/'.$o->id.'/receipt')->assertNotFound();

        // Geste commercial : « Offert », montant à zéro même si un montant est saisi
        $this->api($sa)->postJson('/api/admin/companies/'.$c->id.'/grant', ['type' => 'module', 'key' => 'crm', 'months' => 1, 'amount' => 5000, 'payment_method' => 'free'])->assertOk();
        $this->assertSame(0.0, ModuleOrder::where('company_id', $c->id)->latest('id')->first()->amount);
        // Sans envoi du reçu
        $this->api($sa)->postJson('/api/admin/companies/'.$c->id.'/grant', ['type' => 'module', 'key' => 'crm', 'months' => 1, 'amount' => 5000, 'payment_method' => 'cash', 'send_receipt' => false])->assertOk();
        // Prix négocié gardé pour les renouvellements : 18 000 F pour 3 mois → 6 000 F/mois pour ce client
        $this->api($sa)->postJson('/api/admin/companies/'.$c->id.'/grant', ['type' => 'module', 'key' => 'crm', 'months' => 3, 'amount' => 18000, 'payment_method' => 'orange_money', 'keep_price' => true])->assertOk();
        $this->assertSame(6000.0, \App\Models\CompanyModule::where(['company_id' => $c->id, 'module_key' => 'crm'])->first()->price_override);
        // Mode de paiement inconnu refusé
        $this->api($sa)->postJson('/api/admin/companies/'.$c->id.'/grant', ['type' => 'module', 'key' => 'crm', 'months' => 1, 'payment_method' => 'bitcoin'])->assertStatus(422);
    }

    /** E-mails partis par le transport « array » : destinataires, sujet, HTML, pièces jointes. */
    protected function capturedMails(): array
    {
        $out = [];
        foreach (Mail::mailer('array')->getSymfonyTransport()->messages() as $sm) {
            $m = $sm->getOriginalMessage();
            $out[] = [
                'to' => array_map(fn ($a) => $a->getAddress(), $m->getTo()),
                'subject' => $m->getSubject(),
                'html' => (string) $m->getHtmlBody(),
                'attachments' => array_map(fn ($a) => ['name' => $a->getFilename(), 'data' => $a->getBody()], $m->getAttachments()),
            ];
        }

        return $out;
    }

    public function test_amount_in_words(): void
    {
        $this->assertSame('sept cent soixante-quatre mille six cent quarante', \App\Support\FrenchNumber::words(764640));
        $this->assertSame('quatre-vingts', \App\Support\FrenchNumber::words(80));
        $this->assertSame('deux millions cinq cent mille', \App\Support\FrenchNumber::words(2500000));
    }
}
