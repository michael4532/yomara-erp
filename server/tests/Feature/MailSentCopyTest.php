<?php

namespace Tests\Feature;

use App\Modules\Mailbox\Mime;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Folder;
use App\Modules\Mailbox\Models\Message;
use App\Modules\Mailbox\Store;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Une réponse envoyée apparaît tout de suite dans la conversation, même si le serveur ne renvoie pas sa copie. */
class MailSentCopyTest extends TestCase
{
    public function test_reply_is_threaded_immediately_and_not_duplicated(): void
    {
        [$company, $user, $token] = $this->makeCompany('Repond SARL');
        $fake = new class implements \App\Modules\Mailbox\Drivers\Driver
        {
            public array $sent = [];

            public function test($a): array { return []; }

            public function sync($a): array { return ['new' => 0, 'updated' => 0, 'deleted' => 0]; }

            public function loadBody($a, $m): void {}

            public function attachment($a, $m, string $attId): ?array { return null; }

            public function setFlags($a, $m, array $flags): void {}

            public function move($a, $m, $t): void { throw new \RuntimeException('pas de déplacement à distance pour une copie locale'); }

            public function destroy($a, $m): void {}

            public function createFolder($a, string $name, $parent = null): Folder { return Store::folder($a, 'x:'.$name, $name); }

            public function renameFolder($a, $f, string $name): void {}

            public function deleteFolder($a, $f): void {}

            // Comme Microsoft 365 ou un serveur sans UIDPLUS : pas de copie renvoyée
            public function send($a, array $mail): void { $this->sent[] = $mail; }
        };
        \App\Modules\Mailbox\MailboxService::$fakes['imap'] = $fake;
        try {
            app()->instance('tenant', $company);
            $a = new Account(['provider' => 'imap', 'email' => 'michael@nsi.ci', 'user_id' => $user->id, 'status' => 'active', 'settings' => ['imap_host' => 'x', 'smtp_host' => 'x']]);
            $a->company_id = $company->id;
            $a->save();
            $inbox = Folder::create(['account_id' => $a->id, 'remote_id' => 'INBOX', 'name' => 'Boîte de réception', 'role' => 'inbox']);
            $sent = Folder::create(['account_id' => $a->id, 'remote_id' => 'Sent', 'name' => 'Éléments envoyés', 'role' => 'sent']);
            // Message reçu, lui-même réponse dans une conversation plus ancienne
            $orig = Store::upsert($a, $inbox, '41', ['subject' => 'Re: Candidature HACKATHON', 'from_name' => 'Samba', 'from_email' => 'samba@gmail.com', 'to' => [['email' => 'michael@nsi.ci']],
                'html' => '<p>Merci de fermer les liens.</p>', 'message_id' => '<b2@gmail.com>', 'in_reply_to' => '<a1@nsi.ci>', 'references' => '<a1@nsi.ci>']);

            $this->api($token)->postJson('/api/mailbox/send', ['id' => (string) Str::uuid(), 'account_id' => $a->id, 'to' => ['samba@gmail.com'], 'mode' => 'reply', 'message_id' => $orig->id,
                'subject' => 'Re: Candidature HACKATHON', 'html' => '<p>C\'est fait, les liens sont fermés.</p>'])->assertCreated()->assertJsonPath('data.status', 'sent');

            $copy = Message::where('folder_id', $sent->id)->first();
            $this->assertNotNull($copy, 'la réponse est dans Éléments envoyés tout de suite');
            $this->assertSame($orig->thread_key, $copy->thread_key, 'et dans la même conversation');
            $this->assertStringContainsString('les liens sont fermés', $copy->body_html);
            $this->assertStringStartsWith('local:', $copy->remote_id);

            // Une copie locale se déplace sans appel au serveur
            $arch = Folder::create(['account_id' => $a->id, 'remote_id' => 'Archive', 'name' => 'Archives', 'role' => 'archive']);
            app(\App\Modules\Mailbox\MailboxService::class)->move($copy, $arch);
            $this->assertSame($arch->id, $copy->fresh()->folder_id);
            app(\App\Modules\Mailbox\MailboxService::class)->move($copy->fresh(), $sent);

            // La synchronisation ramène la copie du serveur : elle remplace la copie locale, pas de doublon
            $raw = \App\Modules\Mailbox\Composer::email($fake->sent[0])->toString();
            Store::upsert($a, $sent, '1007', Mime::parse($raw), ['seen' => true]);
            $this->assertSame(1, Message::where('folder_id', $sent->id)->count());
            $this->assertSame('1007', Message::where('folder_id', $sent->id)->value('remote_id'));
        } finally {
            unset(\App\Modules\Mailbox\MailboxService::$fakes['imap']);
        }
    }
}
