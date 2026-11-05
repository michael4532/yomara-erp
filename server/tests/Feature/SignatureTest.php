<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Signature\Models\SignRequest;
use App\Modules\Signature\Models\Signer;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    protected function pdf(string $text = 'Contrat de travail à durée indéterminée'): string
    {
        $p = new \TCPDF;
        $p->setPrintHeader(false);
        $p->AddPage();
        $p->SetFont('dejavusans', '', 12);
        $p->Write(0, $text);
        $p->AddPage();
        $p->Write(0, 'Fait à Abidjan.');

        return $p->Output('', 'S');
    }

    protected function png(): string
    {
        $im = imagecreatetruecolor(300, 120);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imageline($im, 10, 90, 280, 30, imagecolorallocate($im, 11, 27, 58));
        ob_start();
        imagepng($im);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    public function test_signature_requests_links_otp_sequential_signing_sealed_pdf_verification_decline_quote_and_maintenance(): void
    {
        Storage::fake('local');
        [$company, $admin, $token] = $this->makeCompany('Cabinet Kouamé');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $api()->putJson('/api/messaging/channels/whatsapp', ['provider' => 'meta', 'settings' => ['phone_number_id' => '77', 'access_token' => 'tok']])->assertOk();
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $roleName) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@kouame.ci', 'password' => 'secret123', 'role_id' => $role($roleName)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        [$dg, $dgApi] = $mk('Fatou', 'Direction');
        [, $caisseApi] = $mk('Awa', 'Caissier');
        $create = function (array $o, ?string $pdf = null, string $name = 'contrat.pdf') use ($api) {
            return $api()->post('/api/signature/requests', $o + ['file' => UploadedFile::fake()->createWithContent($name, $pdf ?? $this->pdf())]);
        };

        // Contrôles : PDF obligatoire, au moins un signataire
        $create(['signers' => json_encode([['name' => 'X']])], 'pas un pdf', 'x.pdf')->assertStatus(422)->assertJsonFragment(['message' => 'Le fichier doit être un PDF.']);
        $create(['signers' => json_encode([])])->assertStatus(422);
        $caisseApi()->post('/api/signature/requests', ['signers' => '[]', 'file' => UploadedFile::fake()->createWithContent('c.pdf', $this->pdf())])->assertForbidden();

        // Contrat : le salarié signe d'abord (lien WhatsApp + code), puis la direction dans l'application
        $r = $create(['title' => 'Contrat CDI — Koffi Yao', 'message' => 'Merci de signer avant votre prise de poste.', 'sequential' => 1, 'send' => 1,
            'signers' => json_encode([['name' => 'Koffi Yao', 'label' => 'Le Salarié', 'phone' => '+225 07 08 09 10 11'], ['user_id' => $dg->id, 'label' => 'L\'Employeur']])]);
        $r = $r->assertCreated()->json();
        $id = $r['data']['id'];
        $this->assertSame(['pending', 'pending', 'waiting', 'whatsapp'], [$r['data']['status'], $r['data']['signers'][0]['status'], $r['data']['signers'][1]['status'], $r['delivery'][$r['data']['signers'][0]['id']]]);
        $this->assertSame('Fatou', $r['data']['signers'][1]['name']);
        $link = $r['data']['signers'][0]['link'];
        $this->assertNotNull($link);
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data(), JSON_UNESCAPED_SLASHES), $link));
        $path = parse_url($link, PHP_URL_PATH);
        $this->assertFalse(AppNotification::withoutGlobalScopes()->where('user_id', $dg->id)->where('title', 'Document à signer')->exists());

        // Le salarié ouvre le lien
        $this->flushHeaders();
        $this->get($path)->assertOk()->assertSee('Contrat CDI — Koffi Yao')->assertSee('Recevoir mon code')->assertDontSee('Signer le document');
        $this->get('/signer/'.str_repeat('x', 40))->assertNotFound();
        $this->get($path.'/document')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('viewed', Signer::withoutGlobalScopes()->where('request_id', $id)->orderBy('position')->first()->status);

        // Signature impossible sans code ; code reçu par WhatsApp
        $this->post($path.'/signer', ['signature' => $this->png(), 'agree' => 1])->assertSessionHas('error', 'Saisissez d\'abord le code de vérification reçu.');
        $this->post($path.'/code')->assertSessionHas('ok');
        $this->post($path.'/code')->assertSessionHas('error');
        $code = null;
        Http::assertSent(function ($req) use (&$code) {
            if (preg_match('/code de signature .*?(\d{6})/su', json_encode($req->data(), JSON_UNESCAPED_UNICODE), $m)) {
                $code = $m[1];
            }

            return true;
        });
        $this->assertNotNull($code);
        $this->post($path.'/verifier', ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHas('error', 'Code incorrect.');
        $this->post($path.'/verifier', ['code' => $code])->assertSessionHas('ok');
        $this->get($path)->assertSee('Signer le document');
        $this->post($path.'/signer', ['signature' => 'data:image/png;base64,AAAA', 'agree' => 1])->assertSessionHas('error', 'Tracez votre signature.');
        $this->post($path.'/signer', ['signature' => $this->png()])->assertSessionHasErrors('agree');
        $this->post($path.'/signer', ['signature' => $this->png(), 'agree' => 1])->assertRedirect($path)->assertSessionHas('ok');
        $this->post($path.'/signer', ['signature' => $this->png(), 'agree' => 1])->assertSessionHas('error', 'Vous avez déjà signé ce document.');

        // Au tour de la direction : notification, signature dans l'application
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $dg->id)->where('title', 'Document à signer')->exists());
        $caisseApi()->getJson('/api/signature/requests')->assertOk()->assertJsonCount(0, 'data');
        $caisseApi()->getJson("/api/signature/requests/$id")->assertNotFound();
        $caisseApi()->postJson("/api/signature/requests/$id/sign", ['signature' => $this->png(), 'agree' => 1])->assertNotFound();
        $this->assertSame([$id], array_column($dgApi()->getJson('/api/signature/mine')->assertOk()->json('data'), 'id'));
        $dgApi()->postJson("/api/signature/requests/$id/sign", ['signature' => $this->png()])->assertStatus(422);
        $done = $dgApi()->postJson("/api/signature/requests/$id/sign", ['signature' => $this->png(), 'agree' => 1])->assertOk()->json('data');
        $this->assertSame(['completed', true, true], [$done['status'], $done['has_signed_file'], $done['stamped']]);
        $this->assertSame('account', $done['signers'][1]['verified_by']);
        $this->assertSame('whatsapp', $done['signers'][0]['verified_by']);
        $types = array_column($done['events'], 'type');
        foreach (['created', 'sent', 'viewed', 'otp_sent', 'otp_failed', 'verified', 'signed', 'completed', 'sealed'] as $t) {
            $this->assertContains($t, $types);
        }

        // PDF signé : pages d'origine + signatures + certificat, cachet numérique, empreinte enregistrée
        $q = SignRequest::withoutGlobalScopes()->find($id);
        $signed = Storage::disk('local')->get($q->signed_path);
        $this->assertSame(hash('sha256', $signed), $q->signed_sha256);
        $this->assertStringContainsString('/ByteRange', $signed);
        $this->assertStringContainsString('adbe.pkcs7.detached', $signed);
        $this->assertSame(4, \App\Modules\Signature\SignaturePdf::countPages($signed));
        $api()->get("/api/signature/requests/$id/file/signed")->assertOk();
        $this->flushHeaders();
        $this->get($path)->assertSee('Signé par toutes les parties');
        $this->assertSame($signed, $this->get($path.'/document')->streamedContent());

        // Vérification publique
        $this->post('/signature/verifier', ['file' => UploadedFile::fake()->createWithContent('signe.pdf', $signed)])->assertSee('Document authentique')->assertSee('Koffi Yao');
        $this->post('/signature/verifier', ['file' => UploadedFile::fake()->createWithContent('autre.pdf', $this->pdf('Autre'))])->assertSee('Inconnu');
        $this->post('/signature/verifier', ['file' => UploadedFile::fake()->createWithContent('modifie.pdf', $signed.' ')])->assertSee('Inconnu');

        // Refus : lien à transmettre soi-même (aucun contact), refus motivé, gestionnaires prévenus
        $r2 = $create(['title' => 'Avenant', 'send' => 1, 'signers' => json_encode([['name' => 'Brice Kra'], ['name' => 'Ange Diby', 'email' => 'ange@exemple.ci']])])->assertCreated()->json();
        $this->assertSame([null, null], array_values($r2['delivery']));
        $this->assertContains('link_shared', array_column($r2['data']['events'], 'type'));
        $p2 = parse_url($r2['data']['signers'][0]['link'], PHP_URL_PATH);
        $this->flushHeaders();
        $this->get($p2)->assertSee('Signer le document'); // aucun canal pour envoyer un code : le lien personnel fait foi
        $this->post($p2.'/refuser', ['reason' => 'Le montant ne correspond pas'])->assertSessionHas('ok');
        $this->assertSame('declined', SignRequest::withoutGlobalScopes()->find($r2['data']['id'])->status);
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $admin->id)->where('title', 'Signature refusée par Brice Kra')->exists());
        $p3 = parse_url($r2['data']['signers'][1]['link'], PHP_URL_PATH);
        $this->post($p3.'/signer', ['signature' => $this->png(), 'agree' => 1])->assertSessionHas('error', 'Cette demande de signature a été refusée par un signataire.');

        // Devis signé : bon pour accord, commande créée
        $client = $api()->postJson('/api/core/partners', ['name' => 'SCI Les Palmiers', 'type' => 'customer'])->json('data.id');
        $quote = $api()->postJson('/api/ventes/documents', ['type' => 'quote', 'partner_id' => $client, 'date' => now()->toDateString(),
            'lines' => [['description' => 'Audit comptable', 'quantity' => 1, 'unit_price' => 450000, 'tax_rate' => 18]]])->json('data');
        $api()->postJson("/api/ventes/documents/{$quote['id']}/validate")->assertOk();
        $r3 = $create(['title' => 'Devis', 'send' => 1, 'source_type' => 'sales_document', 'source_id' => $quote['id'], 'signers' => json_encode([['name' => 'M. Diallo', 'label' => 'Pour le client', 'email' => 'diallo@palmiers.ci']])])->assertCreated()->json('data');
        $this->assertSame([$r3['id']], array_column($api()->getJson('/api/signature/requests?source_id='.$quote['id'])->json('data'), 'id'));
        $this->flushHeaders();
        $this->post(parse_url($r3['signers'][0]['link'], PHP_URL_PATH).'/signer', ['signature' => $this->png(), 'agree' => 1])->assertSessionHas('ok');
        $order = SalesDocument::withoutGlobalScopes()->where('source_id', $quote['id'])->first();
        $this->assertNotNull($order);
        $this->assertSame(['order', 'validated'], [$order->type, $order->status]);
        $this->assertStringContainsString('M. Diallo', $order->reference);

        // PDF récent (flux compressés) sans qpdf sur le serveur : original joint au fichier signé
        config(['erp.signature_qpdf' => false]);
        $r4 = $create(['title' => 'Bon de livraison', 'send' => 1, 'signers' => json_encode([['name' => 'Client']])], file_get_contents(base_path('tests/fixtures/pdf-compresse.pdf')), 'bl.pdf')->assertCreated()->json('data');
        $this->flushHeaders();
        $this->post(parse_url($r4['signers'][0]['link'], PHP_URL_PATH).'/signer', ['signature' => $this->png(), 'agree' => 1])->assertSessionHas('ok');
        $q4 = SignRequest::withoutGlobalScopes()->find($r4['id']);
        $this->assertSame(['completed', false], [$q4->status, $q4->stamped]);
        $this->assertStringContainsString('/EmbeddedFile', Storage::disk('local')->get($q4->signed_path));

        // Brouillon supprimé, demande annulée, expiration et relances automatiques
        $draft = $create(['title' => 'Brouillon', 'signers' => json_encode([['name' => 'X']])])->json('data.id');
        $this->flushHeaders();
        $this->get(parse_url(Signer::withoutGlobalScopes()->where('request_id', $draft)->first()->link(), PHP_URL_PATH))->assertNotFound();
        $api()->deleteJson("/api/signature/requests/$draft")->assertOk();
        $api()->deleteJson("/api/signature/requests/$id")->assertStatus(422);
        $c = $create(['title' => 'À annuler', 'send' => 1, 'signers' => json_encode([['name' => 'Y']])])->json('data');
        $api()->postJson("/api/signature/requests/{$c['id']}/cancel", ['reason' => 'Erreur de destinataire'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->flushHeaders();
        $this->get(parse_url($c['signers'][0]['link'], PHP_URL_PATH))->assertSee('Annulé')->assertDontSee('Signer le document');
        $old = $create(['title' => 'Ancien', 'send' => 1, 'signers' => json_encode([['name' => 'Z', 'phone' => '0707070707']])])->json('data.id');
        $late = $create(['title' => 'À relancer', 'send' => 1, 'signers' => json_encode([['name' => 'W', 'phone' => '0505050505']])])->json('data.id');
        SignRequest::withoutGlobalScopes()->where('id', $old)->update(['expires_at' => now()->subDay()->toDateString()]);
        SignRequest::withoutGlobalScopes()->where('id', $late)->update(['sent_at' => now()->subDays(4)]);
        $this->artisan('erp:signature-maintenance')->assertSuccessful();
        $this->assertSame('expired', SignRequest::withoutGlobalScopes()->find($old)->status);
        $this->assertSame(1, SignRequest::withoutGlobalScopes()->find($late)->reminders);
        $api()->putJson('/api/signature/settings', ['otp' => false, 'reminder_days' => 2])->assertOk()->assertJsonPath('data.reminder_days', 2);
    }
}
