<?php

namespace Tests\Feature;

use App\Models\MessagingChannel;
use App\Modules\Whatsapp\Models\Conversation;
use App\Modules\Whatsapp\Models\WaMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** WhatsApp : boîte partagée, webhooks Meta / UltraMsg / Green API, fenêtre de 24 h, modèles, réponses automatiques, attribution. */
class WhatsappInboxTest extends TestCase
{
    public function test_meta_inbox_flow(): void
    {
        [$company, $user, $token] = $this->makeCompany('Wa SARL');
        $api = fn () => $this->api($token);
        MessagingChannel::withoutGlobalScopes()->create(['company_id' => $company->id, 'channel' => 'whatsapp', 'provider' => 'meta', 'enabled' => true,
            'settings' => ['phone_number_id' => 'PN1', 'waba_id' => 'WABA1', 'access_token' => 'TOK', 'app_secret' => 'SECRET']]);
        DB::table('partners')->insert(['id' => $pid = (string) Str::uuid(), 'company_id' => $company->id, 'type' => 'customer', 'name' => 'Maquis Chez Tantie', 'phone' => '07 07 12 34 56',
            'created_at' => now(), 'updated_at' => now(), 'version' => 1]);
        Http::fake([
            'graph.facebook.com/v21.0/PN1/messages' => Http::sequence()->push(['messages' => [['id' => 'wamid.OUT1']]])->push(['messages' => [['id' => 'wamid.OUT2']]])
                ->push(['messages' => [['id' => 'wamid.OUT3']]])->push(['messages' => [['id' => 'wamid.OUT4']]])->push(['messages' => [['id' => 'wamid.OUT5']]]),
            'graph.facebook.com/v21.0/MEDIA9' => Http::response(['url' => 'https://lookaside.fbsbx.com/x/MEDIA9', 'mime_type' => 'image/jpeg']),
            'lookaside.fbsbx.com/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
            'graph.facebook.com/v21.0/WABA1/message_templates*' => Http::sequence()
                ->push(['data' => [['name' => 'relance_facture', 'language' => 'fr', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Bonjour {{1}}, votre facture {{2}} est en attente de règlement.']]]]])
                ->push(['id' => 'T2', 'status' => 'PENDING']),
        ]);

        $idx = $api()->getJson('/api/whatsapp/inbox')->assertOk()->json('data');
        $this->assertSame('meta', $idx['provider']);
        $hook = parse_url($idx['webhook_url'], PHP_URL_PATH);
        $this->flushHeaders();
        // Vérification Meta
        $this->get($hook.'?hub.mode=subscribe&hub.verify_token='.$idx['verify_token'].'&hub.challenge=12345')->assertOk()->assertSee('12345');
        $this->get($hook.'?hub.mode=subscribe&hub.verify_token=faux&hub.challenge=1')->assertForbidden();

        // Message entrant signé : texte puis photo
        $payload = fn (array $msg) => ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
            'contacts' => [['wa_id' => '2250707123456', 'profile' => ['name' => 'Tantie Awa']]], 'messages' => [$msg]]]]]]];
        $post = function (array $body, ?string $secret = 'SECRET') use ($hook) {
            $raw = json_encode($body);

            return $this->call('POST', $hook, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, (string) $secret)], $raw);
        };
        $post($payload(['from' => '2250707123456', 'id' => 'wamid.IN1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Bonjour, vous livrez à Cocody ?']]))->assertOk();
        $post($payload(['from' => '2250707123456', 'id' => 'wamid.IN1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'doublon']]))->assertOk();
        $post($payload(['from' => '2250707123456', 'id' => 'wamid.IN2', 'type' => 'image', 'image' => ['id' => 'MEDIA9', 'mime_type' => 'image/jpeg', 'caption' => 'Le modèle']]))->assertOk();
        $post($payload(['from' => '2250707123456', 'id' => 'wamid.X', 'type' => 'text', 'text' => ['body' => 'pirate']]), 'MAUVAIS')->assertForbidden();
        $conv = Conversation::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
        $this->assertEquals(['2250707123456', $pid, 2, $user->id], [$conv->phone, $conv->partner_id, $conv->unread, $conv->assigned_to]);
        $this->assertSame('Maquis Chez Tantie', $conv->name);
        $this->assertSame(2, WaMessage::withoutGlobalScopes()->where('conversation_id', $conv->id)->where('direction', 'in')->count());

        $list = $api()->getJson('/api/whatsapp/conversations?filter=mine')->assertOk()->json('data');
        $this->assertEquals(['Photo · Le modèle', true], [$list[0]['last_preview'], $list[0]['window_open']]);
        $show = $api()->getJson('/api/whatsapp/conversations/'.$conv->id)->assertOk()->json();
        $this->assertCount(2, $show['messages']);
        $this->assertSame('Maquis Chez Tantie', $show['partner']['name']);
        $img = $show['messages'][1]['media_url'];
        $this->flushHeaders();
        $this->get(parse_url($img, PHP_URL_PATH).'?'.parse_url($img, PHP_URL_QUERY))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(0, $conv->fresh()->unread);

        // Réponse texte et document ; statuts « distribué », « lu »
        $out = $this->api($token)->postJson('/api/whatsapp/conversations/'.$conv->id.'/messages', ['body' => 'Oui, livraison demain matin.'])->assertCreated()->json('data');
        $this->assertEquals(['sent', 'text'], [$out['status'], $out['type']]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'PN1/messages') && $r['to'] === '2250707123456' && $r['text']['body'] === 'Oui, livraison demain matin.');
        $doc = $this->api($token)->post('/api/whatsapp/conversations/'.$conv->id.'/messages', ['body' => 'Votre devis',
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('devis.pdf', '%PDF devis')])->assertCreated()->json('data');
        $this->assertSame('document', $doc['type']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'PN1/messages') && ($r['type'] ?? '') === 'document' && $r['document']['filename'] === 'devis.pdf' && str_contains($r['document']['link'], '/whatsapp/fichier/'));
        $link = Http::recorded()->map(fn ($x) => $x[0])->first(fn ($r) => ($r['type'] ?? '') === 'document')['document']['link'];
        $this->flushHeaders();
        $this->get(parse_url($link, PHP_URL_PATH))->assertOk();
        $post(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.OUT1', 'status' => 'delivered']]]]]]]]);
        $post(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.OUT1', 'status' => 'read']]]]]]]]);
        $post(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.OUT1', 'status' => 'delivered']]]]]]]]);
        $this->assertSame('read', WaMessage::withoutGlobalScopes()->where('provider_id', 'wamid.OUT1')->value('status'));

        // Fenêtre de 24 h dépassée : modèle obligatoire
        $conv->forceFill(['last_inbound_at' => now()->subHours(25)])->save();
        $this->api($token)->postJson('/api/whatsapp/conversations/'.$conv->id.'/messages', ['body' => 'Relance libre'])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Le client n\'a pas écrit depuis plus de 24 h : WhatsApp impose un modèle approuvé pour relancer la conversation.']);
        $this->api($token)->postJson('/api/whatsapp/templates/sync')->assertOk()->assertJsonPath('count', 1);
        $t = $this->api($token)->postJson('/api/whatsapp/conversations/'.$conv->id.'/messages', ['template' => 'relance_facture', 'params' => ['Awa', 'FA-2026-0042']])->assertCreated()->json('data');
        $this->assertEquals(['template', 'Bonjour Awa, votre facture FA-2026-0042 est en attente de règlement.'], [$t['type'], $t['body']]);
        Http::assertSent(fn ($r) => ($r['type'] ?? '') === 'template' && $r['template']['name'] === 'relance_facture' && $r['template']['components'][0]['parameters'][1]['text'] === 'FA-2026-0042');
        $this->api($token)->postJson('/api/whatsapp/templates', ['name' => 'Confirmation RDV', 'category' => 'UTILITY', 'body' => 'Bonjour {{1}}, votre rendez-vous est confirmé.'])->assertCreated();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'WABA1/message_templates') && $r->method() === 'POST' && $r['name'] === 'confirmation_rdv' && $r['components'][0]['example']['body_text'][0] === ['exemple 1']);

        // Paramètres : accueil et absence, réponses rapides, clôture
        $api()->putJson('/api/whatsapp/inbox/settings', ['welcome_message' => 'Bienvenue chez {societe} ! Un conseiller vous répond.', 'away_message' => 'Nous sommes fermés. Horaires : {horaires}.',
            'hours' => ['1' => ['08:00', '18:00'], '7' => null], 'display_phone' => '+225 07 00 00 00 00'])->assertOk()->assertJsonPath('data.click_to_chat', 'https://wa.me/2250700000000');
        $post(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['contacts' => [['wa_id' => '2250101010101', 'profile' => ['name' => 'Nouveau client']]],
            'messages' => [['from' => '2250101010101', 'id' => 'wamid.IN9', 'type' => 'text', 'text' => ['body' => 'Bonsoir']]]]]]]]]);
        $nc = Conversation::withoutGlobalScopes()->where('phone', '2250101010101')->firstOrFail();
        $auto = WaMessage::withoutGlobalScopes()->where('conversation_id', $nc->id)->where('auto', true)->get();
        $this->assertSame('Bienvenue chez Wa SARL ! Un conseiller vous répond.', $auto->first()->body);
        $api()->postJson('/api/whatsapp/quick-replies', ['shortcut' => '/tarifs', 'body' => 'Nos tarifs : …'])->assertCreated()->assertJsonPath('data.shortcut', 'tarifs');
        $api()->putJson('/api/whatsapp/conversations/'.$nc->id, ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');
        $api()->postJson('/api/whatsapp/conversations/'.$nc->id.'/partner', ['name' => 'Nouveau client'])->assertOk();
        $this->assertSame('+2250101010101', DB::table('partners')->where('id', $nc->fresh()->partner_id)->value('phone'));

        // Droits : un commercial voit ses conversations et celles non attribuées, pas celles d'un collègue
        $other = \App\Models\User::create(['company_id' => $company->id, 'name' => 'Commercial', 'email' => 'co-'.Str::random(4).'@test.ci', 'password' => bcrypt('x'), 'is_active' => true,
            'role_id' => DB::table('roles')->where('company_id', $company->id)->where('name', 'Commercial')->value('id')]);
        $this->assertTrue($other->hasPermission('whatsapp.use'));
        $ot = $other->createToken('t')->plainTextToken;
        $this->api($ot)->getJson('/api/whatsapp/conversations/'.$conv->id)->assertNotFound();
        $this->api($ot)->putJson('/api/whatsapp/inbox/settings', ['away_message' => 'x'])->assertForbidden();
    }

    public function test_ultramsg_and_greenapi_webhooks(): void
    {
        [$company, , $token] = $this->makeCompany('Wa Multi');
        $ch = MessagingChannel::withoutGlobalScopes()->create(['company_id' => $company->id, 'channel' => 'whatsapp', 'provider' => 'ultramsg', 'enabled' => true, 'settings' => ['instance_id' => 'instance1', 'token' => 'T']]);
        Http::fake(['api.ultramsg.com/*' => Http::response(['sent' => 'true', 'id' => 77]), 'files.example.com/*' => Http::response('PDFDATA', 200, ['Content-Type' => 'application/pdf'])]);
        $hook = parse_url($this->api($token)->getJson('/api/whatsapp/inbox')->json('data.webhook_url'), PHP_URL_PATH);
        $this->flushHeaders();
        $this->postJson($hook, ['event_type' => 'message_received', 'data' => ['id' => 'u1', 'from' => '2250505050505@c.us', 'pushname' => 'Koffi', 'type' => 'chat', 'body' => 'Salut']])->assertOk();
        $this->postJson($hook, ['event_type' => 'message_received', 'data' => ['id' => 'u2', 'from' => '2250505050505@c.us', 'type' => 'document', 'media' => 'https://files.example.com/bon.pdf', 'filename' => 'bon.pdf']])->assertOk();
        $c1 = Conversation::withoutGlobalScopes()->where('phone', '2250505050505')->firstOrFail();
        $this->assertSame('Koffi', $c1->name);
        $this->assertSame('bon.pdf', WaMessage::withoutGlobalScopes()->where('provider_id', 'u2')->value('file_name'));
        $c1->forceFill(['last_inbound_at' => now()->subDays(3)])->save();
        $this->api($token)->postJson('/api/whatsapp/conversations/'.$c1->id.'/messages', ['body' => 'Hors fenêtre mais UltraMsg accepte'])->assertCreated();
        $this->flushHeaders();
        $this->postJson($hook, ['event_type' => 'message_ack', 'data' => ['id' => '77', 'ack' => 'read']])->assertOk();
        $this->assertSame('read', WaMessage::withoutGlobalScopes()->where('provider_id', '77')->value('status'));

        $ch->forceFill(['provider' => 'greenapi', 'settings' => ['id_instance' => '1101', 'api_token' => 'G']])->save();
        $this->postJson($hook, ['typeWebhook' => 'incomingMessageReceived', 'idMessage' => 'g1', 'timestamp' => now()->timestamp, 'senderData' => ['chatId' => '2250606060606@c.us', 'senderName' => 'Mariam'],
            'messageData' => ['typeMessage' => 'textMessage', 'textMessageData' => ['textMessage' => 'Bonjour depuis Green API']]])->assertOk();
        $this->assertSame('Bonjour depuis Green API', WaMessage::withoutGlobalScopes()->where('provider_id', 'g1')->value('body'));
        $this->postJson('/api/whatsapp-webhook/'.str_repeat('z', 40), ['typeWebhook' => 'x'])->assertNotFound();
    }
}
