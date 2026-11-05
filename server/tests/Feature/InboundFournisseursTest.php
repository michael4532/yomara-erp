<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Fournisseurs\Models\Access;
use App\Modules\Fournisseurs\Models\Bid;
use App\Modules\Fournisseurs\Models\OrderAck;
use App\Modules\Fournisseurs\Models\SupplierInvoice;
use App\Modules\Fournisseurs\Models\Tender;
use App\Modules\Helpdesk\Inbound\InboundService;
use App\Modules\Helpdesk\Inbound\Mailbox;
use App\Modules\Helpdesk\Models\Attachment;
use App\Modules\Helpdesk\Models\Message;
use App\Modules\Helpdesk\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundFournisseursTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Négoce Plus');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        $partner = fn (string $name, array $extra = []) => $api()->postJson('/api/core/partners', ['name' => $name, 'type' => 'customer'] + $extra)->json('data.id');

        return compact('company', 'admin', 'api', 'push', 'partner', 'token');
    }

    private function ok(array $r): array
    {
        $this->assertSame('applied', $r['status'], json_encode($r));

        return $r['record'] ?? [];
    }

    private function eml(string $from, string $subject, string $body, string $messageId, array $extraHeaders = [], bool $attach = false): string
    {
        $h = "From: $from\r\nTo: support@negoce.ci\r\nSubject: $subject\r\nMessage-ID: <$messageId>\r\nMIME-Version: 1.0\r\n";
        foreach ($extraHeaders as $k => $v) {
            $h .= "$k: $v\r\n";
        }
        if (! $attach) {
            return $h."Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$body\r\n";
        }

        return $h."Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n--B\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$body\r\n--B\r\nContent-Type: application/pdf; name=\"releve.pdf\"\r\nContent-Disposition: attachment; filename=\"releve.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-1.4 test')."\r\n--B--\r\n";
    }

    public function test_tickets_from_emails_imap_and_whatsapp_with_threading_and_attachments(): void
    {
        Storage::fake('local');
        ['api' => $api, 'push' => $push, 'partner' => $partner, 'company' => $company] = $this->env();
        $box = $api()->getJson('/api/helpdesk/inbound')->assertOk()->json('data');
        $tok = $box['verify_token'];
        $this->assertStringEndsWith('/api/hooks/mail/'.$tok, $box['mail_webhook']);
        $hook = fn (string $raw) => $this->call('POST', '/api/hooks/mail/'.$tok, [], [], [], ['CONTENT_TYPE' => 'message/rfc822', 'HTTP_ACCEPT' => 'application/json'], $raw);

        // Nouvel e-mail (MIME brut) : ticket, client créé, pièce jointe
        $hook($this->eml('=?UTF-8?Q?A=C3=AFcha_Kon=C3=A9?= <aicha@boutique-aicha.ci>', 'Tr: Relevé de compte faux', "Bonjour,\nle relevé d'octobre est faux.\n\nEnvoyé de mon iPhone", 'm1@boutique-aicha.ci', [], true))
            ->assertOk()->assertJsonPath('results.0.status', 'created');
        $t = Ticket::where('channel', 'email')->first();
        $this->assertEquals(['Relevé de compte faux', 'aicha@boutique-aicha.ci', 'Aïcha Koné', "Bonjour,\nle relevé d'octobre est faux."], [$t->subject, $t->contact_email, $t->partner_name, $t->description]);
        $att = Attachment::where('ticket_id', $t->id)->first();
        $this->assertEquals(['releve.pdf', null], [$att->name, $att->message_id]);
        Storage::disk('local')->assertExists($att->path);
        $hook($this->eml('Aïcha <aicha@boutique-aicha.ci>', 'Relevé', 'x', 'm1@boutique-aicha.ci'))->assertJsonPath('results.0.status', 'duplicate');

        // Réponse rattachée par l'en-tête References, historique cité retiré
        $hook($this->eml('aicha@boutique-aicha.ci', 'Re: Demande enregistrée : Relevé de compte faux', "Voici le bon relevé.\n\nLe lun. 26 oct. 2026 à 09:00, Négoce Plus <support@negoce.ci> a écrit :\n> Bonjour", 'm2@boutique-aicha.ci',
            ['References' => '<ticket-'.$t->id.'@yomara.erp>'], true))->assertJsonPath('results.0.status', 'appended');
        $m = Message::where('ticket_id', $t->id)->where('author_type', 'customer')->first();
        $this->assertEquals(['Voici le bon relevé.', 'm2@boutique-aicha.ci', 'email'], [$m->body, $m->external_id, $m->sent_via]);
        $this->assertSame($m->id, Attachment::where('ticket_id', $t->id)->whereNotNull('message_id')->value('message_id'));
        // N° dans l'objet : même expéditeur → rattaché ; autre domaine → nouveau ticket
        $hook($this->eml('aicha@boutique-aicha.ci', 'Re: ['.$t->number.'] relance', 'Une relance.', 'm3@x'))->assertJsonPath('results.0.status', 'appended');
        $hook($this->eml('pirate@autre.ci', 'Re: ['.$t->number.'] lien', 'Hors fil.', 'm4@x'))->assertJsonPath('results.0.status', 'created');
        $this->assertSame('lien', Ticket::where('contact_email', 'pirate@autre.ci')->value('subject'));
        // Réponses automatiques et robots ignorés
        $hook($this->eml('aicha@boutique-aicha.ci', 'Absence du bureau', 'Je suis absente.', 'm5@x', ['Auto-Submitted' => 'auto-replied']))->assertJsonPath('results.0.status', 'ignored');
        $hook($this->eml('mailer-daemon@mx.ci', 'Undelivered', 'x', 'm6@x'))->assertJsonPath('results.0.status', 'ignored');
        $this->post('/api/hooks/mail/'.str_repeat('x', 40), [])->assertNotFound();

        // Webhook Mailgun (champs de formulaire + fichier) et Postmark (JSON)
        $this->post('/api/hooks/mail/'.$tok, ['sender' => 'kofi@atelier.ci', 'from' => 'Kofi Yao <kofi@atelier.ci>', 'subject' => 'Commande 45', 'body-plain' => "Où en est ma commande ?\n> ancien", 'stripped-text' => 'Où en est ma commande ?',
            'Message-Id' => '<mg1@atelier.ci>', 'attachment-1' => UploadedFile::fake()->createWithContent('bon.pdf', '%PDF-1.4 bon')], ['Accept' => 'application/json'])->assertJsonPath('results.0.status', 'created');
        $k = Ticket::where('contact_email', 'kofi@atelier.ci')->first();
        $this->assertEquals(['Commande 45', 'Kofi Yao', 'Où en est ma commande ?'], [$k->subject, $k->contact_name, $k->description]);
        $this->assertSame(1, Attachment::where('ticket_id', $k->id)->count());
        $this->postJson('/api/hooks/mail/'.$tok, ['From' => 'Awa <awa@resto.ci>', 'FromFull' => ['Email' => 'awa@resto.ci', 'Name' => 'Awa Bamba'], 'Subject' => 'Facture', 'TextBody' => 'Merci de corriger.',
            'MessageID' => 'pm-1', 'Headers' => [['Name' => 'X-Test', 'Value' => '1']], 'Attachments' => [['Name' => 'scan.png', 'ContentType' => 'image/png', 'Content' => base64_encode('PNGDATA')], ['Name' => 'virus.exe', 'Content' => base64_encode('MZ')]]])
            ->assertJsonPath('results.0.status', 'created');
        $aw = Ticket::where('contact_email', 'awa@resto.ci')->first();
        $this->assertEquals(['scan.png'], Attachment::where('ticket_id', $aw->id)->pluck('name')->all());

        // WhatsApp : vérification Meta, message texte, suite de conversation, image téléchargée
        $this->get('/api/hooks/whatsapp/'.$tok.'?hub.mode=subscribe&hub.verify_token='.$tok.'&hub.challenge=4242')->assertOk()->assertSee('4242');
        $this->get('/api/hooks/whatsapp/'.$tok.'?hub.mode=subscribe&hub.verify_token=faux&hub.challenge=1')->assertForbidden();
        $meta = fn (array $msg, string $name = 'Moussa Diallo') => ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['contacts' => [['wa_id' => '2250707070707', 'profile' => ['name' => $name]]], 'messages' => [$msg]]]]]]];
        $this->postJson('/api/hooks/whatsapp/'.$tok, $meta(['from' => '2250707070707', 'id' => 'wamid.1', 'type' => 'text', 'text' => ['body' => "Bonjour, l'imprimante ne marche plus depuis hier"]]))->assertJsonPath('results.0.status', 'created');
        $w = Ticket::where('channel', 'whatsapp')->first();
        $this->assertEquals(['Moussa Diallo', '+2250707070707'], [$w->contact_name, $w->contact_phone]);
        $this->assertStringStartsWith('Bonjour, l\'imprimante', $w->subject);
        Http::fake(['graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/media/abc', 'mime_type' => 'image/jpeg']), 'lookaside.fbsbx.com/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg'])]);
        $this->postJson('/api/hooks/whatsapp/'.$tok, $meta(['from' => '2250707070707', 'id' => 'wamid.2', 'type' => 'image', 'image' => ['id' => 'media-9', 'caption' => 'Voici l\'écran']]))->assertJsonPath('results.0.status', 'appended');
        $this->postJson('/api/hooks/whatsapp/'.$tok, $meta(['from' => '2250707070707', 'id' => 'wamid.2', 'type' => 'text', 'text' => ['body' => 'doublon']]))->assertJsonPath('results.0.status', 'duplicate');
        $img = Attachment::where('ticket_id', $w->id)->first();
        $this->assertEquals('image-wamid2.jpg', $img->name);
        $this->assertSame("Voici l'écran", Message::where('ticket_id', $w->id)->where('author_type', 'customer')->value('body'));
        // UltraMsg et Green API ; messages envoyés par nous ignorés
        $this->postJson('/api/hooks/whatsapp/'.$tok, ['event_type' => 'message_received', 'data' => ['id' => 'u1', 'from' => '2250505050505@c.us', 'pushname' => 'Fanta', 'body' => 'Devis svp', 'type' => 'chat']])->assertJsonPath('results.0.status', 'created');
        $this->postJson('/api/hooks/whatsapp/'.$tok, ['event_type' => 'message_received', 'data' => ['id' => 'u2', 'from' => '2250505050505@c.us', 'fromMe' => true, 'body' => 'réponse de l\'équipe']])->assertJsonPath('results', []);
        $this->postJson('/api/hooks/whatsapp/'.$tok, ['typeWebhook' => 'incomingMessageReceived', 'idMessage' => 'g1', 'senderData' => ['chatId' => '2250505050505@c.us', 'senderName' => 'Fanta'],
            'messageData' => ['typeMessage' => 'textMessage', 'textMessageData' => ['textMessage' => 'Pour 10 chaises']]])->assertJsonPath('results.0.status', 'appended');
        $this->assertSame(1, Ticket::where('contact_phone', 'like', '%0505050505')->count());

        // Pièce jointe de l'équipe, visible du client ; fichier interdit refusé ; réponse du client avec fichier
        $api()->post('/api/helpdesk/tickets/'.$t->id.'/files', ['file' => UploadedFile::fake()->createWithContent('procedure.pdf', '%PDF-1.4 procedure')], ['Accept' => 'application/json'])->assertCreated();
        $api()->post('/api/helpdesk/tickets/'.$t->id.'/files', ['file' => UploadedFile::fake()->createWithContent('outil.exe', 'MZ')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $tk = Ticket::withoutGlobalScopes()->find($t->id);
        $this->get('/support/'.$tk->token)->assertOk()->assertSee('procedure.pdf')->assertSee('releve.pdf');
        $pdf = Attachment::withoutGlobalScopes()->where('name', 'procedure.pdf')->first();
        $this->get('/support/'.$tk->token.'/fichier/'.$pdf->id)->assertOk();
        $this->post('/support/'.$tk->token, ['body' => 'Photo de l\'erreur', 'file' => UploadedFile::fake()->image('erreur.jpg')])->assertRedirect();
        $this->assertTrue(Attachment::withoutGlobalScopes()->where('ticket_id', $t->id)->where('name', 'erreur.jpg')->exists());

        // Boîte IMAP (faux serveur) : connexion, relève, messages marqués lus, relève suivante sans doublon
        $dir = sys_get_temp_dir().'/imap'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/1.eml', $this->eml('Jean <jean@ferme.ci>', 'Livraison incomplète', 'Il manque 2 sacs.', 'imap-1@ferme.ci'));
        file_put_contents($dir.'/2.eml', $this->eml('aicha@boutique-aicha.ci', 'Re: suite', 'Merci, c\'est réglé.', 'imap-2@x', ['In-Reply-To' => '<ticket-'.$t->id.'@yomara.erp>']));
        $port = random_int(20000, 40000);
        $state = $dir.'/state';
        $proc = proc_open([PHP_BINARY, base_path('tests/fixtures/fake_imap.php'), (string) $port, $dir, $state], [], $pipes);
        for ($i = 0; $i < 50 && ! file_exists($state); $i++) {
            usleep(100000);
        }
        $api()->putJson('/api/helpdesk/inbound', ['imap_enabled' => true, 'imap_host' => '127.0.0.1', 'imap_port' => $port, 'imap_encryption' => 'none', 'imap_username' => 'support@negoce.ci', 'imap_password' => 'faux'])->assertOk();
        $api()->postJson('/api/helpdesk/inbound/fetch')->assertStatus(422)->assertJsonPath('message', 'Identifiant ou mot de passe refusé par le serveur.');
        $api()->putJson('/api/helpdesk/inbound', ['imap_password' => 'secret'])->assertOk()->assertJsonPath('data.imap_password_set', true);
        $r = $api()->postJson('/api/helpdesk/inbound/fetch')->assertOk()->json('result');
        $this->assertEquals(['created' => 1, 'appended' => 1, 'ignored' => 0], $r);
        $this->assertSame('Livraison incomplète', Ticket::where('contact_email', 'jean@ferme.ci')->value('subject'));
        $this->assertSame(102, Mailbox::where('company_id', $company->id)->value('last_uid'));
        $this->assertStringContainsString('UID STORE 102 +FLAGS', file_get_contents($state));
        $this->assertEquals(['created' => 0, 'appended' => 0, 'ignored' => 0], $api()->postJson('/api/helpdesk/inbound/fetch')->json('result'));
        proc_terminate($proc);
        $this->assertArrayNotHasKey('imap_password_enc', $api()->getJson('/api/helpdesk/inbound')->json('data'));

        // Nettoyage des réponses
        $this->assertSame('Ok pour moi', InboundService::stripQuoted("Ok pour moi\n\n-----Original Message-----\nFrom: x"));
        $this->assertSame('Merci', InboundService::stripQuoted("Merci\nOn Mon, Oct 26, 2026 at 9:00 AM Support\n<s@x.ci> wrote:\n> hello"));
        $this->assertSame('Relevé', InboundService::cleanSubject('RE: TR: Re: Relevé [SUP-2026-00004]'));
    }

    public function test_supplier_portal_tender_award_order_ack_invoice_and_payments(): void
    {
        Storage::fake('local');
        ['api' => $api, 'push' => $push, 'company' => $company, 'admin' => $admin] = $this->env();
        $sup = fn (string $name) => $api()->postJson('/api/core/partners', ['name' => $name, 'type' => 'supplier', 'email' => strtolower(str_replace(' ', '', $name)).'@fourn.ci'])->json('data.id');
        $a = $sup('Sotici Matériaux');
        $b = $sup('Bâtir Plus');
        $c = $sup('Hors Liste SARL');
        $inv = $api()->postJson('/api/portail_fournisseurs/partners/'.$c.'/invite', ['send' => false])->assertOk()->json('url');
        $tokC = basename($inv);

        // Appel d'offres : brouillon, date limite contrôlée, publication → accès créés
        $tid = $this->uuid();
        $lines = [['id' => 'L1', 'description' => 'Ciment CPJ 45 (sac 50 kg)', 'quantity' => 200, 'unit' => 'sac'], ['id' => 'L2', 'description' => 'Fer à béton 10 mm', 'quantity' => 100, 'unit' => 'barre']];
        $this->ok($push('sp_tenders', ['title' => 'Matériaux chantier Cocody', 'lines' => $lines, 'deadline' => now()->subHour()->toDateTimeString(), 'invited' => [$a, $b]], $tid));
        $this->assertSame('AO-'.now()->year.'-00001', Tender::find($tid)->number);
        $this->assertStringContainsString('futur', $push('sp_tenders', ['status' => 'open'], $tid)['message']);
        $this->ok($push('sp_tenders', ['status' => 'open', 'deadline' => now()->addDays(3)->toDateTimeString()], $tid));
        $this->assertStringContainsString('lignes ne changent plus', $push('sp_tenders', ['lines' => $lines], $tid)['message']);
        $this->assertSame(2, Access::whereIn('partner_id', [$a, $b])->count());
        $tokA = Access::where('partner_id', $a)->value('token');
        $tokB = Access::where('partner_id', $b)->value('token');

        // Offres déposées sur le portail ; fournisseur non invité refusé ; révision
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get('/fournisseur/'.$tokA)->assertOk()->assertSee('Matériaux chantier Cocody')->assertSee('À répondre');
        $this->get('/fournisseur/'.$tokA.'/ao/'.$tid)->assertOk()->assertSee('Ciment CPJ 45');
        $this->post('/fournisseur/'.$tokA.'/ao/'.$tid, ['price' => ['L1' => '5 200', 'L2' => '4500'], 'delivery_days' => 5, 'comment' => 'Livraison sur chantier incluse'])->assertRedirect('/fournisseur/'.$tokA.'/ao/'.$tid);
        $this->post('/fournisseur/'.$tokB.'/ao/'.$tid, ['price' => ['L1' => '5000', 'L2' => ''], 'delivery_days' => 2])->assertRedirect();
        $this->post('/fournisseur/'.$tokC.'/ao/'.$tid, ['price' => ['L1' => '1']])->assertSessionHas('error', 'Vous n\'êtes pas invité à cet appel d\'offres.');
        $this->get('/fournisseur/'.$tokC.'/ao/'.$tid)->assertNotFound();
        $this->post('/fournisseur/'.$tokB.'/ao/'.$tid, ['price' => ['L1' => '', 'L2' => '']])->assertSessionHas('error', 'Indiquez au moins un prix.');
        $this->post('/fournisseur/'.$tokA.'/ao/'.$tid, ['price' => ['L1' => '5100', 'L2' => '4500'], 'delivery_days' => 5])->assertRedirect();
        $bidA = Bid::withoutGlobalScopes()->where('partner_id', $a)->first();
        $this->assertEquals([5100 * 200 + 4500 * 100, 1, 5], [$bidA->total, $bidA->revisions, $bidA->delivery_days]);
        $this->assertSame(3, AppNotification::withoutGlobalScopes()->where('user_id', $admin->id)->where('type', 'fournisseurs')->count());
        $this->assertSame('rejected', $push('sp_bids', ['total' => 1])['status']);

        // Comparaison, clôture à la date limite, attribution en bon de commande
        $cmp = $api()->getJson('/api/portail_fournisseurs/tenders/'.$tid.'/compare')->assertOk()->json('data');
        $this->assertEquals([$b, $a, $a], [$cmp['rows'][0]['best'], $cmp['rows'][1]['best'], $cmp['cheapest']]);
        Carbon::setTestNow(now()->addDays(4));
        app()->instance('tenant', $company->fresh());
        $this->assertSame(1, app(\App\Modules\Fournisseurs\FournisseursService::class)->closeExpired($company->fresh()));
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->post('/fournisseur/'.$tokB.'/ao/'.$tid, ['price' => ['L1' => '4000']])->assertSessionHas('error');
        $res = $api()->postJson('/api/portail_fournisseurs/tenders/'.$tid.'/award', ['partner_id' => $a])->assertOk()->json();
        $po = PurchaseDocument::with('lines')->find($res['order']['id']);
        $this->assertEquals(['purchase_order', 'draft', $a, Tender::find($tid)->number, 2], [$po->type, $po->status, $po->partner_id, $po->reference, $po->lines->count()]);
        $this->assertEquals([5100, 4500], $po->lines->sortBy('sort')->pluck('unit_price')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals(['awarded', 'lost'], [Bid::where('partner_id', $a)->value('status'), Bid::where('partner_id', $b)->value('status')]);
        $api()->postJson('/api/portail_fournisseurs/tenders/'.$tid.'/award', ['partner_id' => $b])->assertStatus(422);

        // Le fournisseur confirme la commande validée avec une date de livraison
        $this->ok($push('purchase_documents', ['status' => 'validated'], $po->id));
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get('/fournisseur/'.$tokB.'/commandes/'.$po->id)->assertNotFound();
        $this->get('/fournisseur/'.$tokA.'/commandes/'.$po->id)->assertOk()->assertSee($po->number)->assertSee('Ciment CPJ 45');
        $this->post('/fournisseur/'.$tokA.'/commandes/'.$po->id, ['decision' => 'accept'])->assertSessionHas('error');
        $this->post('/fournisseur/'.$tokA.'/commandes/'.$po->id, ['decision' => 'accept', 'delivery_date' => now()->addDays(5)->toDateString(), 'name' => 'Koné (commercial)'])->assertRedirect();
        $this->assertSame('accepted', OrderAck::withoutGlobalScopes()->where('order_id', $po->id)->value('status'));
        $this->get('/fournisseur/'.$tokA.'/commandes')->assertSee('Confirmée');

        // Dépôt de facture, doublon et format refusés ; acceptation → facture fournisseur des Achats
        $this->post('/fournisseur/'.$tokA.'/factures', ['number' => 'F-2026-118', 'date' => now()->toDateString(), 'amount' => $po->total, 'order_id' => $po->id, 'file' => UploadedFile::fake()->create('f118.pdf', 50, 'application/pdf')])->assertRedirect();
        $this->post('/fournisseur/'.$tokA.'/factures', ['number' => 'F-2026-118', 'date' => now()->toDateString(), 'amount' => 10, 'file' => UploadedFile::fake()->create('f.pdf', 5, 'application/pdf')])->assertSessionHas('error', 'Vous avez déjà déposé la facture n° F-2026-118.');
        $this->post('/fournisseur/'.$tokA.'/factures', ['number' => 'F-2', 'date' => now()->toDateString(), 'amount' => 10, 'file' => UploadedFile::fake()->create('f.docx', 5)])->assertSessionHas('error');
        $this->post('/fournisseur/'.$tokA.'/factures', ['number' => 'F-2026-119', 'date' => now()->toDateString(), 'amount' => 59000, 'file' => UploadedFile::fake()->image('f119.jpg')])->assertRedirect();
        $si = SupplierInvoice::withoutGlobalScopes()->where('number', 'F-2026-118')->first();
        $api()->get('/api/portail_fournisseurs/invoices/'.$si->id.'/file')->assertOk();
        $this->ok($push('sp_invoices', ['status' => 'accepted'], $si->id));
        $fi = PurchaseDocument::with('lines')->find(SupplierInvoice::find($si->id)->supplier_invoice_id);
        $this->assertEquals(['supplier_invoice', 'F-2026-118', $po->id, 2, (float) $po->total], [$fi->type, $fi->reference, $fi->source_id, $fi->lines->count(), (float) $fi->total]);
        $this->assertStringContainsString('déjà acceptée', $push('sp_invoices', ['status' => 'rejected', 'reject_reason' => 'x'], $si->id)['message']);
        $si2 = SupplierInvoice::where('number', 'F-2026-119')->first();
        $this->assertStringContainsString('pourquoi', $push('sp_invoices', ['status' => 'rejected'], $si2->id)['message']);
        $this->ok($push('sp_invoices', ['status' => 'rejected', 'reject_reason' => 'Bon de commande manquant'], $si2->id));

        // Paiement partiel visible sur le portail
        $this->ok($push('purchase_documents', ['status' => 'validated'], $fi->id));
        $this->ok($push('purchase_payments', ['document_id' => $fi->id, 'date' => now()->toDateString(), 'amount' => 500000, 'method' => 'bank_transfer', 'reference' => 'VIR-889']));
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get('/fournisseur/'.$tokA.'/paiements')->assertOk()->assertSee('Payée en partie')->assertSee('500 000')->assertSee('VIR-889');
        $this->get('/fournisseur/'.$tokA.'/factures')->assertOk()->assertSee('Payée en partie')->assertSee('Refusée')->assertSee('Bon de commande manquant');
        // Accès révoqué
        $api()->postJson('/api/portail_fournisseurs/partners/'.$a.'/revoke')->assertOk();
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get('/fournisseur/'.$tokA)->assertNotFound();
        $s = $api()->getJson('/api/portail_fournisseurs/stats')->assertOk()->json('data');
        $this->assertEquals([0, 0, 0], [$s['open_tenders'], $s['to_award'], $s['invoices_received']]);
    }
}
