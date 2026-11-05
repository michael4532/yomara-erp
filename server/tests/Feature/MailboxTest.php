<?php

namespace Tests\Feature;

use App\Modules\Mailbox\Mime;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Message;
use App\Modules\Mailbox\Protocol\ImapClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Boîte mail : IMAP / POP3 / SMTP réels (serveur GreenMail local si MAILBOX_TEST_SERVER=1), Microsoft 365 (Graph simulé),
 * Google (Agenda simulé), droits, copie locale des postes, signatures, conversion en tâche / opportunité.
 */
class MailboxTest extends TestCase
{
    protected function greenmail(): void
    {
        if (! env('MAILBOX_TEST_SERVER')) {
            $this->markTestSkipped('MAILBOX_TEST_SERVER non défini (serveur GreenMail local sur 3025/3110/3143).');
        }
        @file_get_contents('http://127.0.0.1:8080/api/mail/purge', false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Length: 0\r\n"]]));
    }

    protected function deliver(string $to, string $subject, string $html, array $atts = [], array $headers = []): void
    {
        $t = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport('127.0.0.1', 3025, null);
        $t->setAutoTls(false);
        $t->setUsername('bob');
        $t->setPassword('secret');
        $e = (new \Symfony\Component\Mime\Email)->from(new \Symfony\Component\Mime\Address('bob@yomara.test', 'Bob Kouassi'))->to($to)->subject($subject)->html($html);
        foreach ($atts as [$name, $data, $mime]) {
            $e->attach($data, $name, $mime);
        }
        foreach ($headers as $k => $v) {
            $e->getHeaders()->addTextHeader($k, $v);
        }
        (new \Symfony\Component\Mailer\Mailer($t))->send($e);
    }

    public function test_imap_smtp_pop3_with_real_server(): void
    {
        $this->greenmail();
        config(['erp.mailbox.verify_tls' => false]);
        [$company, $user, $token] = $this->makeCompany('Courrier SARL');
        $api = fn () => $this->api($token);
        $partner = DB::table('partners')->insertGetId(['id' => $pid = (string) Str::uuid(), 'company_id' => $company->id, 'type' => 'customer', 'name' => 'Bob Kouassi', 'email' => 'Bob@Yomara.test',
            'created_at' => now(), 'updated_at' => now(), 'version' => 1]);
        $this->deliver('alice@yomara.test', 'Devis été 2026 ✓', '<p onclick="x()">Bonjour <b>Alice</b> <img src="cid:logo123"></p><script>alert(1)</script><a href="javascript:evil()">x</a>',
            [['devis.pdf', '%PDF-1.4 devis', 'application/pdf']]);

        $api()->postJson('/api/mailbox/accounts/detect', ['email' => 'compta@monentreprise.ci'])->assertOk()->assertJsonPath('settings.imap_host', 'mail.monentreprise.ci');
        $api()->postJson('/api/mailbox/accounts/detect', ['email' => 'jean@gmail.com'])->assertOk()->assertJsonPath('provider', 'google');
        $bad = $api()->postJson('/api/mailbox/accounts', ['provider' => 'imap', 'email' => 'alice@yomara.test', 'password' => 'secret',
            'settings' => ['imap_host' => '127.0.0.1', 'imap_port' => 1, 'imap_security' => 'none', 'smtp_host' => '127.0.0.1', 'smtp_port' => 3025, 'smtp_security' => 'none', 'username' => 'alice']])
            ->assertStatus(422)->json('message');
        $this->assertStringStartsWith('Connexion impossible : Connexion impossible à 127.0.0.1:1', $bad);
        $acc = $api()->postJson('/api/mailbox/accounts', ['provider' => 'imap', 'email' => 'alice@yomara.test', 'display_name' => 'Alice', 'password' => 'secret',
            'settings' => ['imap_host' => '127.0.0.1', 'imap_port' => 3143, 'imap_security' => 'none', 'smtp_host' => '127.0.0.1', 'smtp_port' => 3025, 'smtp_security' => 'none', 'username' => 'alice']])
            ->assertCreated()->json('data');
        $this->assertArrayNotHasKey('password', $acc);
        $this->assertTrue($acc['has_password']);

        $idx = $api()->getJson('/api/mailbox')->assertOk()->json('data');
        $inbox = collect($idx['folders'])->firstWhere('role', 'inbox');
        $this->assertSame(1, $inbox['unread']);
        $list = $api()->getJson('/api/mailbox/messages?folder_id='.$inbox['id'])->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertEquals(['Devis été 2026 ✓', 'bob@yomara.test', 'Bob Kouassi', true, $pid], [$list[0]['subject'], $list[0]['from_email'], $list[0]['from_name'], $list[0]['has_attachments'], $list[0]['partner_id']]);
        $full = $api()->getJson('/api/mailbox/messages/'.$list[0]['id'].'?read=1')->assertOk()->json();
        $html = $full['data']['html'];
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<b>Alice</b>', $html);
        $this->assertSame('Bob Kouassi', $full['partner']['name']);
        $att = collect($full['data']['attachments'])->firstWhere('name', 'devis.pdf');
        $this->assertNotNull($att);
        $this->flushHeaders();
        $this->get(parse_url($att['url'], PHP_URL_PATH).'?'.parse_url($att['url'], PHP_URL_QUERY))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF-1.4 devis', false);
        $this->get(parse_url($att['url'], PHP_URL_PATH).'?expires=1&signature=faux')->assertForbidden();
        // lu sur le serveur IMAP aussi
        $c = (new ImapClient)->connect('127.0.0.1', 3143, 'none')->login('alice', 'secret');
        $c->select('INBOX');
        $this->assertSame(['\\Seen'], array_values(array_filter(array_values($c->uidFetch('1:*', 'UID FLAGS'))[0]['FLAGS'], fn ($f) => $f === '\\Seen')));

        // Réponse avec pièce jointe : part en SMTP, copie dans « Éléments envoyés », original marqué « répondu »
        foreach (['Éléments envoyés', 'Corbeille'] as $nf) {
            try {
                $c->createFolder($nf);
            } catch (\App\Modules\Mailbox\Protocol\MailProtocolException) { // déjà créé lors d'un passage précédent
            }
        }
        $api()->postJson("/api/mailbox/accounts/{$acc['id']}/sync")->assertOk();
        $sid = (string) Str::uuid();
        $out = $api()->postJson('/api/mailbox/send', ['id' => $sid, 'account_id' => $acc['id'], 'to' => [['name' => 'Bob', 'email' => 'bob@yomara.test']], 'subject' => 'RE: Devis été 2026 ✓',
            'html' => '<p>Merci Bob, devis accepté.</p>', 'mode' => 'reply', 'message_id' => $list[0]['id'],
            'attachments' => [['type' => 'upload', 'name' => 'bon-pour-accord.txt', 'mime' => 'text/plain', 'data' => base64_encode('Bon pour accord')]]])->assertCreated()->json('data');
        $this->assertSame('sent', $out['status']);
        $api()->postJson('/api/mailbox/send', ['id' => $sid, 'account_id' => $acc['id'], 'to' => ['bob@yomara.test'], 'subject' => 'x', 'html' => 'x'])->assertCreated(); // rejoué : pas de doublon
        $this->assertSame(1, DB::table('mb_outbox')->count());
        $bob = (new ImapClient)->connect('127.0.0.1', 3143, 'none')->login('bob', 'secret');
        $bob->select('INBOX');
        $got = array_values($bob->uidFetch('1:*', 'UID BODY.PEEK[]'));
        $this->assertCount(1, $got);
        $p = Mime::parse($got[0]['BODY[]']);
        $this->assertEquals(['RE: Devis été 2026 ✓', 'alice@yomara.test', 'Alice'], [$p['subject'], $p['from_email'], $p['from_name']]);
        $this->assertSame(Message::find($list[0]['id'])->message_id, $p['in_reply_to']);
        $this->assertSame('bon-pour-accord.txt', $p['attachments'][0]['name']);
        $this->assertTrue(Message::find($list[0]['id'])->answered);
        $sentFolder = DB::table('mb_folders')->where('account_id', $acc['id'])->where('role', 'sent')->first();
        $this->assertSame(1, Message::where('folder_id', $sentFolder->id)->count());
        $this->assertSame(Message::find($list[0]['id'])->thread_key, Message::where('folder_id', $sentFolder->id)->first()->thread_key);

        // Envoi programmé, annulation
        $later = $api()->postJson('/api/mailbox/send', ['id' => (string) Str::uuid(), 'account_id' => $acc['id'], 'to' => ['bob@yomara.test'], 'subject' => 'Relance', 'html' => '<p>Relance</p>',
            'send_at' => now()->addHour()->toIso8601String()])->assertCreated()->json('data');
        $this->assertSame('queued', $later['status']);
        $api()->deleteJson('/api/mailbox/outbox/'.$later['id'])->assertNoContent();
        $api()->postJson('/api/mailbox/send', ['id' => (string) Str::uuid(), 'account_id' => $acc['id'], 'to' => ['pas-une-adresse'], 'subject' => 'x', 'html' => 'x'])->assertStatus(422);

        // Drapeau, corbeille, suppression définitive
        $api()->postJson('/api/mailbox/messages/'.$list[0]['id'].'/flags', ['flagged' => true])->assertOk()->assertJsonPath('data.flagged', true);
        $c->select('INBOX');
        $this->assertContains('\\Flagged', array_values($c->uidFetch('1:*', 'UID FLAGS'))[0]['FLAGS']);
        $api()->deleteJson('/api/mailbox/messages/'.$list[0]['id'])->assertNoContent();
        $this->assertSame(0, $c->status('INBOX')['messages']);
        $this->assertSame(1, $c->status('Corbeille')['messages']);
        $api()->postJson("/api/mailbox/accounts/{$acc['id']}/sync")->assertOk();
        $trash = DB::table('mb_folders')->where('account_id', $acc['id'])->where('role', 'trash')->first();
        $inTrash = Message::where('folder_id', $trash->id)->first();
        $this->assertNotNull($inTrash);
        $api()->deleteJson('/api/mailbox/messages/'.$inTrash->id)->assertNoContent();
        $this->assertSame(0, $c->status('Corbeille')['messages']);

        // Copie locale des postes : curseur, suppressions
        $ch = $api()->getJson('/api/mailbox/changes')->assertOk()->json();
        $this->assertNotEmpty($ch['data']);
        $this->assertArrayHasKey('html', $ch['data'][0]);
        $this->deliver('alice@yomara.test', 'Nouvelle commande', '<p>Commande n°42</p>');
        $this->travel(2)->seconds();
        $api()->postJson("/api/mailbox/accounts/{$acc['id']}/sync")->assertOk();
        $ch2 = $api()->getJson('/api/mailbox/changes?since='.urlencode($ch['cursor']))->assertOk()->json();
        $this->assertContains('Nouvelle commande', array_column($ch2['data'], 'subject'));
        $this->assertNotContains($list[0]['subject'], array_column($ch2['data'], 'subject'));
        $search = $api()->getJson('/api/mailbox/messages?q=commande')->assertOk()->json('data');
        $this->assertCount(1, $search);

        // POP3 : relève, copie laissée sur le serveur
        $this->deliver('bob@yomara.test', 'Facture FA-2026-0007', '<p>Votre facture</p>');
        $pop = $api()->postJson('/api/mailbox/accounts', ['provider' => 'pop3', 'email' => 'bob@yomara.test', 'password' => 'secret',
            'settings' => ['pop3_host' => '127.0.0.1', 'pop3_port' => 3110, 'pop3_security' => 'none', 'smtp_host' => '127.0.0.1', 'smtp_port' => 3025, 'smtp_security' => 'none', 'username' => 'bob']])
            ->assertCreated()->json('data');
        $popInbox = DB::table('mb_folders')->where('account_id', $pop['id'])->where('role', 'inbox')->first();
        $this->assertEqualsCanonicalizing(['RE: Devis été 2026 ✓', 'Facture FA-2026-0007'], Message::where('folder_id', $popInbox->id)->pluck('subject')->all());
        $api()->postJson("/api/mailbox/accounts/{$pop['id']}/sync")->assertOk();
        $this->assertSame(2, Message::where('folder_id', $popInbox->id)->count());
        $pm = Message::where('folder_id', $popInbox->id)->where('subject', 'like', 'RE:%')->first();
        $this->assertSame('bon-pour-accord.txt', $pm->attachments[0]['name']);
        $this->flushHeaders();
        $this->api($token)->get('/api/mailbox/messages/'.$pm->id.'/attachments/'.rtrim(strtr(base64_encode('0'), '+/', '-_'), '='))->assertOk()->assertSee('Bon pour accord', false);

        // Dossiers de rangement (IMAP réel) : créer, renommer, ranger un message, supprimer
        $tag = Str::lower(Str::random(4));
        $fid = $this->api($token)->postJson('/api/mailbox/folders', ['account_id' => $acc['id'], 'name' => 'Clients été '.$tag])->assertCreated()->json('data');
        $this->assertSame('Clients été '.$tag, $fid['name']);
        $this->assertTrue($fid['custom']);
        $sub = $this->api($token)->postJson('/api/mailbox/folders', ['account_id' => $acc['id'], 'name' => 'Abidjan', 'parent_id' => $fid['id']])->assertCreated()->json('data');
        $this->assertSame('Clients été '.$tag.' › Abidjan', $sub['name']);
        $this->assertSame(1, $sub['depth']);
        $this->api($token)->putJson('/api/mailbox/folders/'.$fid['id'], ['name' => 'Clients 2026 '.$tag])->assertOk()->assertJsonPath('data.name', 'Clients 2026 '.$tag);
        $this->assertSame('Clients 2026 '.$tag.' › Abidjan', \App\Modules\Mailbox\Models\Folder::find($sub['id'])->name);
        $paths = array_column($c->listFolders(), 'full');
        $this->assertContains('Clients 2026 '.$tag, array_map(fn ($p) => \App\Modules\Mailbox\Store::label($p, '.'), $paths));
        // (supprimé avant toute sélection : GreenMail plante sur un dossier encore suivi par une autre session)
        $this->api($token)->deleteJson('/api/mailbox/folders/'.$sub['id'])->assertNoContent();
        $this->assertNull(\App\Modules\Mailbox\Models\Folder::find($sub['id']));
        // supprimer un dossier qui a des sous-dossiers : les sous-dossiers partent d'abord
        $arch = $this->api($token)->postJson('/api/mailbox/folders', ['account_id' => $acc['id'], 'name' => 'Archives '.$tag])->assertCreated()->json('data');
        $q1 = $this->api($token)->postJson('/api/mailbox/folders', ['account_id' => $acc['id'], 'name' => 'T1', 'parent_id' => $arch['id']])->assertCreated()->json('data');
        $this->api($token)->deleteJson('/api/mailbox/folders/'.$arch['id'])->assertNoContent();
        $this->assertNull(\App\Modules\Mailbox\Models\Folder::find($q1['id']));
        $this->assertNotContains('Archives '.$tag, array_column($c->listFolders(), 'full'));
        $inboxF = \App\Modules\Mailbox\Models\Folder::where('account_id', $acc['id'])->where('role', 'inbox')->first();
        $mid = Message::where('folder_id', $inboxF->id)->value('id');
        $this->api($token)->postJson('/api/mailbox/messages/bulk', ['ids' => [$mid], 'action' => 'move', 'folder_id' => $fid['id']])->assertOk()->assertJsonPath('done', 1);
        $this->api($token)->postJson("/api/mailbox/accounts/{$acc['id']}/sync")->assertOk();
        $this->assertSame(1, Message::where('folder_id', $fid['id'])->count());
        $this->api($token)->putJson('/api/mailbox/folders/'.$inboxF->id, ['name' => 'Autre'])->assertStatus(422);
        $names = collect($this->api($token)->getJson('/api/mailbox')->json('data.folders'))->where('account_id', $acc['id'])->pluck('name')->all();
        $this->assertSame('Boîte de réception', $names[0]);
        $this->assertNotContains('INBOX.Clients 2026 '.$tag, $names);

        // Tâche et suppression du compte
        $this->api($token)->postJson('/api/mailbox/messages/'.$pm->id.'/convert', ['type' => 'task'])->assertCreated();
        $this->assertSame('Mail : RE: Devis été 2026 ✓', DB::table('user_todos')->where('user_id', $user->id)->value('title'));
        $this->api($token)->deleteJson('/api/mailbox/accounts/'.$pop['id'])->assertNoContent();
        $this->assertSame(0, Message::where('account_id', $pop['id'])->count());
        $c->logout();
        $bob->logout();
    }

    public function test_microsoft_365_mail_calendar_and_permissions(): void
    {
        [$company, $user, $token] = $this->makeCompany('Office SARL');
        config(['erp.mailbox.microsoft_client_id' => 'cid-123', 'erp.mailbox.microsoft_client_secret' => 'sec']);
        $api = fn () => $this->api($token);
        $url = $api()->postJson('/api/mailbox/oauth/microsoft', ['calendar' => true])->assertOk()->json('url');
        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url);
        $this->assertStringContainsString('Mail.Send', urldecode($url));
        parse_str(parse_url($url, PHP_URL_QUERY), $q);

        $sent = [];
        Http::fake(function ($req) use (&$sent) {
            $u = $req->url();
            $m = $req->method();
            $sent[] = "$m $u";
            $json = fn ($d, $s = 200) => Http::response($d, $s);
            return match (true) {
                str_contains($u, '/oauth2/v2.0/token') => $json(['access_token' => 'AT-1', 'refresh_token' => 'RT-1', 'expires_in' => 3600]),
                str_ends_with($u, '/me?$select=displayName,mail,userPrincipalName') => $json(['displayName' => 'Awa Traoré', 'mail' => 'Awa@Office.ci']),
                preg_match('#/me/mailFolders/(inbox|sentitems|drafts|deleteditems|junkemail|archive)\?\$select=id$#', $u, $mm) === 1 => $json(['id' => 'F-'.$mm[1]]),
                str_contains($u, '/mailFolders/inbox?$select=displayName') => $json(['totalItemCount' => 2]),
                str_contains($u, '/me/mailFolders?$top=100') => $json(['value' => [
                    ['id' => 'F-inbox', 'displayName' => 'Inbox', 'childFolderCount' => 1], ['id' => 'F-sentitems', 'displayName' => 'Sent Items', 'childFolderCount' => 0],
                    ['id' => 'F-deleteditems', 'displayName' => 'Deleted Items', 'childFolderCount' => 0], ['id' => 'F-x', 'displayName' => 'Conversation History', 'childFolderCount' => 0]]]),
                str_contains($u, '/childFolders') => $json(['value' => [['id' => 'F-clients', 'displayName' => 'Clients', 'childFolderCount' => 0]]]),
                str_contains($u, '/mailFolders/F-inbox/messages/delta') && $m === 'GET' => $json(['value' => [
                    ['id' => 'M1', 'subject' => 'Bon de commande', 'from' => ['emailAddress' => ['name' => 'Koffi', 'address' => 'koffi@client.ci']], 'toRecipients' => [['emailAddress' => ['address' => 'awa@office.ci']]],
                        'receivedDateTime' => now()->subHour()->utc()->toIso8601String(), 'isRead' => false, 'flag' => ['flagStatus' => 'notFlagged'], 'hasAttachments' => true, 'internetMessageId' => '<m1@client.ci>',
                        'conversationId' => 'CONV1', 'bodyPreview' => 'Veuillez trouver', 'body' => ['contentType' => 'html', 'content' => '<p>Veuillez trouver <img src="cid:sig@1"></p>'], 'isDraft' => false],
                ], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/F-inbox/messages/delta?$deltatoken=D1']),
                str_contains($u, 'messages/delta') => $json(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/x/delta?$deltatoken=DX']),
                str_contains($u, '/messages/M1/attachments?') => $json(['value' => [
                    ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'A1', 'name' => 'commande.pdf', 'contentType' => 'application/pdf', 'size' => 10, 'isInline' => false],
                    ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'A2', 'name' => 'sig.png', 'contentType' => 'image/png', 'size' => 4, 'isInline' => true]]]),
                str_contains($u, '/messages/M1/attachments/A2?$select=id,contentId') => $json(['id' => 'A2', 'contentId' => '<sig@1>']),
                str_contains($u, '/messages/M1/attachments/A1') => $json(['name' => 'commande.pdf', 'contentType' => 'application/pdf', 'contentBytes' => base64_encode('%PDF commande')]),
                $m === 'PATCH' && str_ends_with($u, '/messages/M1') => $json(['id' => 'M1']),
                str_ends_with($u, '/messages/M1/createReply') => $json(['id' => 'DRAFT1']),
                str_ends_with($u, '/messages/DRAFT1') || str_ends_with($u, '/messages/DRAFT1/attachments') || str_ends_with($u, '/messages/DRAFT1/send') => $json(['id' => 'DRAFT1'], 202),
                str_ends_with($u, '/me/sendMail') => $json(null, 202),
                str_ends_with($u, '/messages/M1/move') => $json(['id' => 'M1-moved']),
                str_contains($u, '/me/calendarView/delta') => $json(['value' => [
                    ['id' => 'EV1', 'changeKey' => 'ck1', 'subject' => 'Comité de direction', 'start' => ['dateTime' => now()->addDay()->setTime(9, 0)->utc()->format('Y-m-d\TH:i:s.0000000')],
                        'end' => ['dateTime' => now()->addDay()->setTime(10, 0)->utc()->format('Y-m-d\TH:i:s.0000000')], 'isAllDay' => false, 'location' => ['displayName' => 'Salle Ébène'],
                        'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/abc'], 'attendees' => [['emailAddress' => ['name' => 'Koffi', 'address' => 'koffi@client.ci']]], 'sensitivity' => 'normal'],
                ], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=C1']),
                $m === 'POST' && str_ends_with($u, '/me/events') => $json(['id' => 'EV-NEW', 'changeKey' => 'ck2', 'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/new']], 201),
                $m === 'DELETE' && str_contains($u, '/me/events/') => $json(null, 204),
                default => $json(['error' => ['message' => 'non simulé '.$u]], 500),
            };
        });

        $this->flushHeaders();
        $this->get('/oauth/microsoft/retour?state='.$q['state'].'&code=CODE')->assertOk()->assertSee('Compte connecté')->assertSee('awa@office.ci');
        $this->get('/oauth/microsoft/retour?state='.$q['state'].'&code=CODE')->assertStatus(422)->assertSee('expiré');
        $acc = Account::where('email', 'awa@office.ci')->firstOrFail();
        $this->assertEquals(['microsoft', $user->id, true, 'active'], [$acc->provider, $acc->user_id, $acc->calendar_sync, $acc->status]);
        $this->assertSame('RT-1', $acc->secret()['refresh_token']);

        $idx = $api()->getJson('/api/mailbox')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Boîte de réception', 'Envoyés', 'Corbeille', 'Clients'], collect($idx['folders'])->pluck('name')->all());
        $m = Message::where('remote_id', 'M1')->firstOrFail();
        $this->assertEquals(['Bon de commande', 'koffi@client.ci', false, true, sha1('cCONV1')], [$m->subject, $m->from_email, $m->seen, $m->has_attachments, $m->thread_key]);
        // ouverture : pièces jointes et image intégrée chargées, message marqué lu chez Microsoft
        $full = $api()->getJson('/api/mailbox/messages/'.$m->id.'?read=1')->assertOk()->json('data');
        $this->assertEquals(['commande.pdf', 'sig.png'], array_column($full['attachments'], 'name'));
        $this->assertStringContainsString('/mailbox/fichier/'.$m->id.'/', $full['html']);
        $this->assertStringNotContainsString('cid:sig@1', $full['html']);
        $this->assertTrue(collect($sent)->contains(fn ($s) => str_starts_with($s, 'PATCH') && str_ends_with($s, '/messages/M1')));
        $this->flushHeaders();
        $this->api($token)->get('/api/mailbox/messages/'.$m->id.'/attachments/'.rtrim(strtr(base64_encode('A1'), '+/', '-_'), '='))->assertOk()->assertSee('%PDF commande', false);

        // Réponse dans la conversation Outlook
        $this->api($token)->postJson('/api/mailbox/send', ['id' => (string) Str::uuid(), 'account_id' => $acc->id, 'to' => [['email' => 'koffi@client.ci']], 'subject' => 'RE: Bon de commande',
            'html' => '<p>Bien reçu.</p>', 'mode' => 'reply', 'message_id' => $m->id])->assertCreated()->assertJsonPath('data.status', 'sent');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/messages/DRAFT1') && $r['body']['content'] === '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1b1f24"><p>Bien reçu.</p></body></html>');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages/DRAFT1/send'));
        // Nouveau message avec pièce jointe trop lourde pour Graph
        $this->api($token)->postJson('/api/mailbox/send', ['id' => (string) Str::uuid(), 'account_id' => $acc->id, 'to' => ['x@y.ci'], 'subject' => 'Lourd', 'html' => 'x',
            'attachments' => [['type' => 'upload', 'name' => 'gros.bin', 'mime' => 'application/octet-stream', 'data' => base64_encode(str_repeat('a', 3 * 1024 * 1024 + 10))]]])
            ->assertStatus(422)->assertJsonPath('data.status', 'failed');

        // Agenda : événement Outlook importé, événement Yomara « teams » créé avec sa réunion Teams
        $ev = DB::table('ag_events')->where('company_id', $company->id)->where('title', 'Comité de direction')->first();
        $this->assertNotNull($ev);
        $this->assertEquals(['Salle Ébène', 'https://teams.microsoft.com/l/meetup-join/abc', $user->id], [$ev->location, $ev->online_link, (int) $ev->owner_id]);
        $this->assertSame('koffi@client.ci', json_decode($ev->guests, true)[0]['email']);
        $mine = (string) Str::uuid();
        $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => (string) Str::uuid(), 'entity' => 'ag_events', 'op' => 'upsert', 'record_id' => $mine,
            'data' => ['title' => 'Point client Koffi', 'starts_at' => now()->addDays(2)->setTime(15, 0)->format('Y-m-d H:i:s'), 'ends_at' => now()->addDays(2)->setTime(16, 0)->format('Y-m-d H:i:s'), 'online_link' => 'teams']]]])
            ->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->api($token)->postJson("/api/mailbox/accounts/{$acc->id}/sync")->assertOk()->assertJsonPath('data.calendar.pushed', 1);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/me/events') && $r['isOnlineMeeting'] === true && $r['onlineMeetingProvider'] === 'teamsForBusiness' && $r['subject'] === 'Point client Koffi');
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/new', DB::table('ag_events')->where('id', $mine)->value('online_link'));
        $this->api($token)->postJson("/api/mailbox/accounts/{$acc->id}/sync")->assertOk()->assertJsonPath('data.calendar.pushed', 0); // rien de neuf : pas d'écho
        DB::table('ag_events')->where('id', $mine)->update(['deleted_at' => now()]);
        $this->api($token)->postJson("/api/mailbox/accounts/{$acc->id}/sync")->assertOk()->assertJsonPath('data.calendar.pushed', 1);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/me/events/EV-NEW'));

        // Droits : un collègue ne voit pas la boîte d'Awa ; boîte partagée attribuée
        $other = \App\Models\User::create(['company_id' => $company->id, 'name' => 'Collègue', 'email' => 'col-'.Str::random(4).'@test.ci', 'password' => bcrypt('x'), 'is_active' => true,
            'role_id' => DB::table('roles')->where('company_id', $company->id)->where('name', 'Commercial')->value('id')]);
        $this->assertTrue($other->fresh()->hasPermission('mailbox.use'));
        $otherToken = $other->createToken('t')->plainTextToken;
        $this->api($otherToken)->getJson('/api/mailbox/messages/'.$m->id)->assertNotFound();
        $this->assertSame([], $this->api($otherToken)->getJson('/api/mailbox/messages')->json('data'));
        $this->api($otherToken)->getJson('/api/mailbox/settings')->assertForbidden();
        $shared = $api()->postJson("/api/mailbox/accounts/{$acc->id}/shared", ['email' => 'contact@office.ci', 'display_name' => 'Contact'])->assertCreated()->json('data');
        $this->assertSame('contact@office.ci', Account::find($shared['id'])->setting('graph_user'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/users/contact%40office.ci/mailFolders/inbox'));
    }

    public function test_google_calendar_signatures_templates_and_lead(): void
    {
        [$company, $user, $token] = $this->makeCompany('Gmail SARL');
        $api = fn () => $this->api($token);
        $acc = new Account(['provider' => 'google', 'email' => 'eva@gmail.com', 'user_id' => $user->id, 'calendar_sync' => true, 'sync_days' => 30, 'status' => 'active']);
        $acc->company_id = $company->id;
        $acc->setSecret(['access_token' => 'GAT', 'refresh_token' => 'GRT', 'expires_at' => time() + 3000]);
        $acc->save();
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::sequence()
                ->push(['items' => [['id' => 'G1', 'etag' => 'e1', 'summary' => 'Formation Excel', 'start' => ['date' => now()->addDays(3)->toDateString()], 'end' => ['date' => now()->addDays(4)->toDateString()]],
                    ['id' => 'G2', 'status' => 'cancelled']], 'nextSyncToken' => 'S1'])
                ->push(['id' => 'G-NEW', 'etag' => 'e2', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'])
                ->push(['items' => [], 'nextSyncToken' => 'S2']),
        ]);
        $mine = (string) Str::uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => (string) Str::uuid(), 'entity' => 'ag_events', 'op' => 'upsert', 'record_id' => $mine,
            'data' => ['title' => 'Revue mensuelle', 'starts_at' => now()->addDays(5)->setTime(10, 0)->format('Y-m-d H:i:s'), 'ends_at' => now()->addDays(5)->setTime(11, 0)->format('Y-m-d H:i:s'),
                'online_link' => 'meet', 'recurrence' => ['freq' => 'weekly', 'byday' => [1, 3]]]]]])->assertOk();
        $res = app(\App\Modules\Mailbox\CalendarSync::class)->sync($acc->fresh());
        $this->assertEquals(['pulled' => 1, 'pushed' => 1], $res);
        $g1 = DB::table('ag_events')->where('title', 'Formation Excel')->first();
        $this->assertTrue((bool) $g1->all_day);
        $this->assertSame(now()->addDays(3)->toDateString(), substr($g1->starts_at, 0, 10));
        $this->assertSame('https://meet.google.com/abc-defg-hij', DB::table('ag_events')->where('id', $mine)->value('online_link'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'conferenceDataVersion=1') && $r['recurrence'] === ['RRULE:FREQ=WEEKLY;BYDAY=MO,WE']
            && $r['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet');
        $this->assertSame('S1', $acc->fresh()->stateValue('cal_sync_token'));

        // Signatures (générateur), modèle, opportunité CRM depuis un mail
        $sig = $api()->postJson('/api/mailbox/signatures', ['name' => 'Pro', 'html' => '<table><tr><td><b>Eva</b><script>x</script></td></tr></table>', 'is_default' => true,
            'fields' => ['name' => 'Eva', 'title' => 'Directrice']])->assertCreated()->json('data');
        $this->assertStringNotContainsString('script', $sig['html']);
        $api()->postJson('/api/mailbox/signatures', ['name' => 'Société', 'html' => '<p>Gmail SARL</p>', 'company' => true])->assertCreated();
        $api()->postJson('/api/mailbox/templates', ['name' => 'Relance devis', 'subject' => 'Votre devis', 'html' => '<p>Bonjour, avez-vous pu consulter notre devis ?</p>'])->assertCreated();
        $idx = $api()->getJson('/api/mailbox')->assertOk()->json('data');
        $this->assertCount(2, $idx['signatures']);
        $this->assertSame(['Relance devis'], array_column($idx['templates'], 'name'));
        $this->assertSame('google', $idx['accounts'][0]['provider']);
        // Signatures par boîte (comme Outlook) : nouveaux messages / réponses
        $accId = $idx['accounts'][0]['id'];
        $api()->putJson('/api/mailbox/signature-defaults', ['account_id' => $accId, 'new_id' => $sig['id'], 'reply_id' => null])->assertOk();
        $this->assertSame([['account_id' => $accId, 'new_id' => $sig['id'], 'reply_id' => null]], $api()->getJson('/api/mailbox')->json('data.signature_defaults'));
        $api()->putJson('/api/mailbox/signature-defaults', ['account_id' => $accId, 'new_id' => 999999])->assertStatus(422);
        $folder = \App\Modules\Mailbox\Store::folder($acc, 'INBOX', 'Boîte de réception', 'inbox');
        $msg = \App\Modules\Mailbox\Store::upsert($acc, $folder, '7', ['subject' => 'Demande de devis climatisation', 'from_name' => 'Hôtel Assinie', 'from_email' => 'achat@hotel.ci',
            'text' => 'Bonjour, nous souhaitons un devis pour 12 splits.', 'date' => now()]);
        if ($company->hasModule('crm')) {
            $api()->postJson('/api/mailbox/messages/'.$msg->id.'/convert', ['type' => 'lead'])->assertCreated();
            $this->assertSame('achat@hotel.ci', DB::table('crm_leads')->where('company_id', $company->id)->where('title', 'Demande de devis climatisation')->value('email'));
        }
        $this->assertSame('Bonjour, nous souhaitons un devis pour 12 splits.', $msg->snippet);

        // Images de signature et photo de profil : adresses publiques ; page d'installation mobile
        $png = \Illuminate\Http\UploadedFile::fake()->image('logo.png', 120, 60);
        $url = $api()->post('/api/mailbox/media', ['file' => $png])->assertCreated()->json('url');
        $this->assertMatchesRegularExpression('#/mailbox/media/[A-Za-z0-9]{32}\.png$#', $url);
        $this->flushHeaders();
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk();
        $this->get('/mailbox/media/'.str_repeat('a', 32).'.png')->assertNotFound();
        $api()->post('/api/mailbox/media', ['file' => \Illuminate\Http\UploadedFile::fake()->create('virus.png', 10, 'image/png')])->assertStatus(422);
        $av = $api()->post("/api/mailbox/accounts/{$acc->id}/avatar", ['file' => \Illuminate\Http\UploadedFile::fake()->image('moi.jpg', 200, 200)])->assertOk()->json('data.avatar_url');
        $this->assertStringContainsString('/mailbox/media/', $av);
        $this->assertSame($av, collect($api()->getJson('/api/mailbox')->json('data.accounts'))->firstWhere('id', $acc->id)['avatar_url']);
        $api()->deleteJson("/api/mailbox/accounts/{$acc->id}/avatar")->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->flushHeaders();
        $this->get('/application-mobile')->assertOk()->assertSee('Yomara sur votre téléphone');
    }

    public function test_mime_threading_and_imap_parser(): void
    {
        $this->assertSame(Mime::threadKey('RE: TR: Facture 12', null, null, null), Mime::threadKey('Facture 12', null, null, null));
        $this->assertSame(Mime::threadKey('RE: x', null, '<orig@a>', '<rep@a>'), Mime::threadKey('x', null, null, '<orig@a>'));
        $this->assertSame(Mime::threadKey('x', '<root@a> <r2@a>', '<r2@a>', '<m@a>'), Mime::threadKey('y', '<root@a>', null, '<n@a>'));
        $this->assertSame('1:3,5,7:8', ImapClient::set([7, 1, 2, 3, 5, 8]));
        $this->assertSame('Éléments envoyés', ImapClient::decodeName(ImapClient::encodeName('Éléments envoyés')));
        $this->assertSame('sent', ImapClient::role('INBOX.Sent', []));
        $this->assertSame('Envoyés', \App\Modules\Mailbox\Store::label('INBOX.Sent', '.', 'sent'));
        $this->assertSame('Clients › Abidjan', \App\Modules\Mailbox\Store::label('INBOX.Clients.Abidjan', '.'));
        $this->assertSame('Projets', \App\Modules\Mailbox\Store::label('Projets', '/'));
        $this->assertSame('Factures', \App\Modules\Mailbox\Store::label('[Gmail]/Factures', '/'));
        $this->assertSame('trash', ImapClient::role('[Gmail]/Corbeille', ['\\trash']));
        $p = new \App\Modules\Mailbox\Protocol\ImapParser("(UID 12 FLAGS (\\Seen \\Flagged) BODY[HEADER.FIELDS (SUBJECT)] {9}\r\nSubject:x RFC822.SIZE 99)");
        $this->assertSame(['UID', '12', 'FLAGS', ['\\Seen', '\\Flagged'], 'BODY[HEADER.FIELDS (SUBJECT)]', 'Subject:x', 'RFC822.SIZE', '99'], $p->next());
        $this->assertSame('<p>ok</p>', Mime::sanitize('<p>ok</p><iframe src="x"></iframe><form><input></form>'));
    }

    public function test_antispam_and_hosted_mailboxes(): void
    {
        [$company, $user, $token] = $this->makeCompany('Antispam SARL');
        $fake = new class implements \App\Modules\Mailbox\Drivers\Driver
        {
            public array $sent = [];

            public bool $reject = false;

            public function test($a): array { return []; }

            public function sync($a): array { return ['new' => 0, 'updated' => 0, 'deleted' => 0]; }

            public function loadBody($a, $m): void {}

            public function attachment($a, $m, string $attId): ?array { return null; }

            public function setFlags($a, $m, array $flags): void {}

            public function move($a, $m, $t): void {}

            public function destroy($a, $m): void {}

            public function createFolder($a, string $name, $parent = null): \App\Modules\Mailbox\Models\Folder { return \App\Modules\Mailbox\Store::folder($a, 'x:'.$name, $name); }

            public function renameFolder($a, $f, string $name): void {}

            public function deleteFolder($a, $f): void {}

            public function send($a, array $mail): void
            {
                if ($this->reject) {
                    throw new \App\Modules\Mailbox\Protocol\MailProtocolException('Envoi refusé par le serveur SMTP : 550 5.7.1 Message rejected');
                }
                $this->sent[] = $mail;
            }
        };
        \App\Modules\Mailbox\MailboxService::$fakes['imap'] = $fake;
        try {
            // Adresse fournie par Yomara : réservée au module Messagerie hébergée, créée sur le cPanel
            config(['erp.mailbox.hosted' => ['cpanel_host' => 'cp.yomara.ci', 'cpanel_user' => 'yomara', 'cpanel_token' => 'TOK', 'domain' => 'mail.yomara.ci', 'quota_mb' => 1024, 'max_per_company' => 2]]);
            Http::fake(['cp.yomara.ci:2083/execute/Email/*' => Http::response(['status' => 1, 'data' => ['diskused' => 3.2], 'errors' => null])]);
            $idx = $this->api($token)->getJson('/api/mailbox')->assertOk()->json('data.hosted');
            $this->assertEquals([true, 2, '.antispam-sarl'], [$idx['configured'], $idx['max'], $idx['suffix']]);
            $this->assertTrue($company->hasModule('mail_server'));
            $company->refresh();
            if ($company->hasModule('mail_server')) {
                $this->api($token)->postJson('/api/mailbox/accounts/hosted', ['local' => 'postmaster'])->assertStatus(422);
                $h = $this->api($token)->postJson('/api/mailbox/accounts/hosted', ['local' => 'Awa Traoré', 'display_name' => 'Awa'])->assertCreated()->json('data');
                $this->assertEquals(['awatraore.antispam-sarl@mail.yomara.ci', true], [$h['email'], $h['hosted']]);
                Http::assertSent(fn ($r) => str_contains($r->url(), '/execute/Email/add_pop') && $r['email'] === 'awatraore.antispam-sarl' && $r['quota'] == 1024 && $r->header('Authorization')[0] === 'cpanel yomara:TOK');
                $this->assertEquals([20, 100, 30], [$h['limits']['per_hour'], $h['limits']['per_day'], $h['limits']['recipients']]); // rodage : un tiers des plafonds
                $pw = $this->api($token)->postJson("/api/mailbox/accounts/{$h['id']}/hosted-password")->assertOk()->json();
                $this->assertSame(20, strlen($pw['password']));
                $this->api($token)->postJson('/api/mailbox/accounts/hosted', ['local' => 'compta'])->assertCreated();
                $this->api($token)->postJson('/api/mailbox/accounts/hosted', ['local' => 'rh'])->assertStatus(422)->assertJsonFragment(['message' => 'Votre formule comprend 2 adresse(s) Yomara : retirez-en une ou contactez-nous pour en ajouter.']);
                $this->api($token)->deleteJson("/api/mailbox/accounts/{$h['id']}")->assertNoContent();
                Http::assertSent(fn ($r) => str_contains($r->url(), '/execute/Email/delete_pop'));
            }

            // Anti-spam sur un compte propre
            $a = new Account(['provider' => 'imap', 'email' => 'vente@antispam.ci', 'user_id' => $user->id, 'status' => 'active', 'settings' => ['imap_host' => 'x', 'smtp_host' => 'x']]);
            $a->company_id = $company->id;
            $a->save();
            config(['erp.mailbox.limits.own' => ['per_hour' => 4, 'per_day' => 100, 'recipients' => 3]]);
            $send = fn (array $extra = []) => $this->api($token)->postJson('/api/mailbox/send', array_merge(['id' => (string) Str::uuid(), 'account_id' => $a->id, 'to' => ['client@exemple.ci'],
                'subject' => 'Votre devis', 'html' => '<p>Bonjour, voici notre devis.</p>'], $extra));
            $send()->assertCreated()->assertJsonPath('data.status', 'sent');
            $send(['to' => ['a@x.ci', 'b@x.ci'], 'cc' => ['c@x.ci', 'd@x.ci']])->assertStatus(422)->assertJsonFragment(['message' => 'Trop de destinataires (4, maximum 3 par message) : pour un envoi groupé, utilisez une campagne du module Marketing (désinscription et suivi inclus).']);
            $msg = $send(['attachments' => [['type' => 'upload', 'name' => 'facture.pdf.exe', 'mime' => 'application/octet-stream', 'data' => base64_encode('MZ')]]])->assertStatus(422)->json('message');
            $this->assertStringContainsString('bloqué par les messageries', $msg);
            $msg = $send(['subject' => 'FÉLICITATIONS GAGNEZ VITE !!!', 'html' => '<p>Vous avez gagné à la loterie, cliquez ici : <a href="https://bit.ly/x">ici</a></p>'])->assertStatus(422)->json('message');
            $this->assertStringContainsString('ressemble à un pourriel', $msg);
            $send()->assertCreated();
            $send()->assertCreated();
            $send()->assertCreated();
            $this->assertStringContainsString('Limite de 4 messages par heure', $send()->assertStatus(422)->json('message'));
            $this->assertCount(4, $fake->sent);

            // Refus répétés du serveur → envois suspendus une heure
            config(['erp.mailbox.limits.own' => ['per_hour' => 100, 'per_day' => 100, 'recipients' => 10]]);
            $fake->reject = true;
            for ($i = 0; $i < 5; $i++) {
                $send(['subject' => 'Relance '.$i])->assertCreated()->assertJsonPath('data.status', 'queued'); // nouvel essai automatique plus tard
            }
            $this->assertNotNull($a->fresh()->stateValue('send_blocked_until'));
            $this->assertStringContainsString('Envois suspendus', $send(['subject' => 'Encore'])->assertStatus(422)->json('message'));
            $acc = collect($this->api($token)->getJson('/api/mailbox')->json('data.accounts'))->firstWhere('id', $a->id);
            $this->assertNotNull($acc['send_blocked_until']);
            $this->api($token)->getJson("/api/mailbox/accounts/{$a->id}/deliverability")->assertOk()->assertJsonStructure(['data' => ['domain', 'score', 'checks', 'verdict', 'limits']]);
            $this->assertSame(0, \App\Modules\Mailbox\Antispam::score(['subject' => 'Facture de novembre', 'html' => '<p>Bonjour, ci-joint votre facture.</p>', 'attachments' => []])['score']);
        } finally {
            \App\Modules\Mailbox\MailboxService::$fakes = [];
        }
    }
}
