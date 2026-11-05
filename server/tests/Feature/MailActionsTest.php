<?php

namespace Tests\Feature;

use App\Modules\Mailbox\MailInsights;
use App\Modules\Mailbox\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Boîte mail interactive : dates détectées, invitations, création d'un devis, d'un appel de fonds, d'une réunion depuis un e-mail. */
class MailActionsTest extends TestCase
{
    public function test_insights_dates_intents_invite(): void
    {
        $now = Carbon::parse('2026-10-28 09:00', 'Africa/Abidjan'); // mercredi
        $m = new Message(['subject' => 'Demande de devis — fournitures', 'from_email' => 'achat@client.ci', 'body_text' => "Bonjour,\nPourriez-vous nous faire un devis pour 50 ramettes ? Budget 250 000 FCFA.\n"
            ."Nous pouvons faire un point le mardi 3 novembre à 10h30, ou le 05/11/2026 à 15h, sinon demain à 9h.\nLien : https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc\n"
            ."Tel 07.07.12.34.56\n\nLe 20 oct. 2026, Jean a écrit :\n> réunion le 30 décembre"]);
        $i = MailInsights::analyze($m, $now);
        $this->assertSame(['2026-11-03T10:30:00+00:00', '2026-11-05T15:00:00+00:00', '2026-10-29T09:00:00+00:00'], array_column($i['dates'], 'start'));
        $this->assertSame('mardi 3 novembre à 10h30', $i['dates'][0]['text']);
        $this->assertSame('meeting', $i['intents'][0]); // lien Teams
        $this->assertContains('quote', $i['intents']);
        $this->assertSame('teams', $i['meeting_link']['provider']);
        $this->assertSame(250000.0, $i['amount']);

        $ics = "BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\nUID:abc-123@outlook\r\nSUMMARY:Revue trimestrielle\r\nDTSTART;TZID=Africa/Abidjan:20261104T140000\r\nDTEND;TZID=Africa/Abidjan:20261104T150000\r\n"
            ."LOCATION:Microsoft Teams\r\nORGANIZER;CN=\"Awa Koné\":mailto:awa@client.ci\r\nDESCRIPTION:Rejoindre : https://teams.microsoft.com/l/meetup-join/19%3a\r\n xyz\r\nEND:VEVENT\r\nEND:VCALENDAR";
        $inv = MailInsights::parseIcs($ics);
        $this->assertEquals(['Revue trimestrielle', '2026-11-04T14:00:00+00:00', 'awa@client.ci', 'Awa Koné', 'REQUEST'], [$inv['summary'], $inv['start'], $inv['organizer']['email'], $inv['organizer']['name'], $inv['method']]);
        $this->assertStringStartsWith('https://teams.microsoft.com/l/meetup-join/19%3axyz', $inv['link']);
    }

    public function test_convert_mail_to_quote_funds_meeting_and_invite(): void
    {
        [$company, $user, $token] = $this->makeCompany('Mail SARL');
        $api = fn () => $this->api($token);
        app()->instance('tenant', $company);
        $acc = \App\Modules\Mailbox\Models\Account::create(['user_id' => $user->id, 'provider' => 'imap', 'email' => 'moi@mail-sarl.ci', 'status' => 'active']);
        $folder = \App\Modules\Mailbox\Models\Folder::create(['account_id' => $acc->id, 'remote_id' => 'INBOX', 'name' => 'Boîte de réception', 'role' => 'inbox']);
        $ics = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:inv-1\nSUMMARY:Point projet\nDTSTART:20991104T140000Z\nDTEND:20991104T150000Z\nORGANIZER:mailto:awa@client.ci\nEND:VEVENT\nEND:VCALENDAR";
        $msg = Message::create(['company_id' => $company->id, 'account_id' => $acc->id, 'folder_id' => $folder->id, 'remote_id' => '1', 'subject' => 'Demande de cotation ciment',
            'from_name' => 'Awa Koné', 'from_email' => 'awa@client.ci', 'to' => [['email' => 'moi@mail-sarl.ci'], ['email' => 'compta@client.ci', 'name' => 'Compta']], 'date' => now(),
            'body_text' => "Bonjour, merci de nous coter 40 sacs. Disponible jeudi prochain à 11h pour en parler.\n".$ics, 'body_loaded' => true]);

        // Assistant (sans clé IA : résumé, actions et réponse type produits localement)
        config(['erp.ai.api_key' => null]);
        $sum = $api()->postJson("/api/mailbox/messages/{$msg->id}/assist", ['action' => 'summary'])->assertOk()->json('data');
        $this->assertFalse($sum['ai']);
        $this->assertStringContainsString('demande de devis', $sum['text']);
        $this->assertStringContainsString('Réponse attendue : oui', $sum['text']);
        $this->assertStringContainsString('merci de nous coter', $api()->postJson("/api/mailbox/messages/{$msg->id}/assist", ['action' => 'tasks'])->json('data.text'));
        $this->assertStringContainsString('devis', $api()->postJson("/api/mailbox/messages/{$msg->id}/assist", ['action' => 'reply'])->json('data.text'));
        $api()->postJson("/api/mailbox/messages/{$msg->id}/assist", ['action' => 'pirate'])->assertStatus(422);

        $show = $api()->getJson("/api/mailbox/messages/{$msg->id}")->assertOk()->json();
        $this->assertContains('quote', $show['insights']['intents']);
        $this->assertNotEmpty($show['insights']['dates']);
        $this->assertSame('Point projet', $show['insights']['invite']['summary']);
        $this->assertEqualsCanonicalizing(['task', 'meeting', 'visio', 'webinar', 'quote', 'ticket', 'lead', 'funds'], $show['actions']);

        // Devis en brouillon, client créé depuis l'expéditeur
        $q = $api()->postJson("/api/mailbox/messages/{$msg->id}/convert", ['type' => 'quote'])->assertCreated()->json();
        $doc = DB::table('trade_documents')->find($q['id']);
        $this->assertEquals(['quote', 'draft'], [$doc->type, $doc->status]);
        $this->assertSame('awa@client.ci', DB::table('partners')->where('id', $doc->partner_id)->value('email'));
        $this->assertStringContainsString('Demande de cotation ciment', $doc->notes);

        // Appel de fonds
        $f = $api()->postJson("/api/mailbox/messages/{$msg->id}/convert", ['type' => 'funds', 'amount' => 300000])->assertCreated()->json();
        $this->assertSame('Demande de cotation ciment', DB::table('af_requests')->where('id', $f['id'])->value('title'));

        // Réunion en présentiel avec invitation aux autres destinataires (pas à moi)
        $mails = app(\App\Messaging\MessagingService::class);
        \App\Models\MessagingChannel::withoutGlobalScopes()->create(['company_id' => $company->id, 'channel' => 'email', 'provider' => 'smtp', 'enabled' => true, 'settings' => ['host' => 'localhost']]);
        $start = now()->addDays(3)->setTime(11, 0)->toIso8601String();
        $r = $api()->postJson("/api/mailbox/messages/{$msg->id}/convert", ['type' => 'meeting', 'start' => $start, 'duration' => 45, 'provider' => 'onsite', 'location' => 'Nos bureaux'])->assertCreated()->json();
        $ev = DB::table('ag_events')->find($r['id']);
        $this->assertEquals(['Demande de cotation ciment', 'Nos bureaux', 45], [$ev->title, $ev->location, Carbon::parse($ev->starts_at)->diffInMinutes(Carbon::parse($ev->ends_at))]);
        $this->assertEqualsCanonicalizing(['awa@client.ci', 'compta@client.ci'], array_column(json_decode($ev->guests, true), 'email'));
        unset($mails);

        // Invitation reçue : ajoutée à mon agenda, une seule fois
        $a = $api()->postJson("/api/mailbox/messages/{$msg->id}/accept-invite")->assertCreated()->json();
        $this->assertSame('Point projet', DB::table('ag_events')->where('id', $a['id'])->value('title'));
        $api()->postJson("/api/mailbox/messages/{$msg->id}/accept-invite")->assertOk()->assertJsonPath('message', 'Déjà dans votre agenda.');

        // Un utilisateur sans le module Ventes ne peut pas créer de devis depuis un mail
        $user->forceFill(['modules_denied' => ['ventes']])->save();
        $this->assertNotContains('quote', $this->api($token)->getJson("/api/mailbox/messages/{$msg->id}")->json('actions'));
        $this->api($token)->postJson("/api/mailbox/messages/{$msg->id}/convert", ['type' => 'quote'])->assertForbidden();
    }
}
