<?php

namespace Tests\Feature;

use App\Modules\Mailbox\MailboxService;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Store;
use App\Support\Push;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** YoMail : notifications sur le téléphone (nouvel e-mail, réponse, échec d'envoi, clic, boîte déconnectée). */
class MailNotifyTest extends TestCase
{
    protected function tearDown(): void
    {
        Push::flush(); // rien ne doit rester en file pour les tests suivants
        Push::$sender = null;
        MailboxService::$fakes = [];
        parent::tearDown();
    }

    public function test_yomail_push_notifications(): void
    {
        $sent = [];
        Push::$sender = function ($m) use (&$sent) { $sent[] = $m; return 'ok'; };
        [$c, $u, $token] = $this->makeCompany('Mail Push SARL');
        $this->api($token, 'android')->postJson('/api/me/push-token', ['token' => str_repeat('t', 40), 'platform' => 'android'])->assertOk();

        $fake = new class implements \App\Modules\Mailbox\Drivers\Driver
        {
            public array $incoming = [];

            public bool $fail = false;

            public bool $sendFails = false;

            public function test($a): array { return []; }

            public function sync($a): array
            {
                if ($this->fail) {
                    throw new \App\Modules\Mailbox\Protocol\MailProtocolException('Authentification refusée');
                }
                $inbox = Store::folder($a, 'INBOX', 'Boîte de réception', 'inbox');
                foreach ($this->incoming as $i => $p) {
                    Store::upsert($a, $inbox, 'r'.$i.Str::random(4), $p + ['date' => now()]);
                }
                $this->incoming = [];

                return ['new' => 0];
            }

            public function loadBody($a, $m): void {}

            public function attachment($a, $m, string $attId): ?array { return null; }

            public function setFlags($a, $m, array $flags): void {}

            public function move($a, $m, $t): void {}

            public function destroy($a, $m): void {}

            public function createFolder($a, string $name, $parent = null): \App\Modules\Mailbox\Models\Folder { return Store::folder($a, 'x:'.$name, $name); }

            public function renameFolder($a, $f, string $name): void {}

            public function deleteFolder($a, $f): void {}

            public function send($a, array $mail): void
            {
                if ($this->sendFails) {
                    throw new \DomainException('Adresse du destinataire refusée.');
                }
            }
        };
        MailboxService::$fakes['imap'] = $fake;
        $a = new Account(['provider' => 'imap', 'email' => 'vente@mailpush.ci', 'user_id' => $u->id, 'status' => 'active', 'settings' => ['imap_host' => 'x', 'smtp_host' => 'x']]);
        $a->company_id = $c->id;
        $a->save();
        $svc = app(MailboxService::class);
        $flush = function () use (&$sent) { $sent = []; Push::flush(); return $sent; };

        // Première synchronisation : l'historique ne déclenche aucune notification
        $fake->incoming = [['subject' => 'Ancien', 'from_email' => 'x@y.ci', 'from_name' => 'X']];
        $svc->sync($a);
        $this->assertSame([], $flush());

        // Un nouvel e-mail : expéditeur en titre, objet et début du texte
        $fake->incoming = [['subject' => 'Demande de devis', 'from_name' => 'Hôtel Assinie', 'from_email' => 'achat@hotel.ci', 'text' => 'Bonjour, il nous faut 12 splits.']];
        $svc->sync($a->fresh());
        $out = $flush();
        $this->assertCount(1, $out);
        $this->assertSame('Hôtel Assinie', $out[0]['notification']['title']);
        $this->assertStringContainsString('Demande de devis', $out[0]['notification']['body']);
        $this->assertSame('mailbox', $out[0]['data']->module);

        // Plusieurs e-mails ensemble : un seul résumé ; ses propres copies et le courrier indésirable sont ignorés
        $fake->incoming = [
            ['subject' => 'Commande 1', 'from_name' => 'A', 'from_email' => 'a@x.ci'],
            ['subject' => 'Commande 2', 'from_name' => 'B', 'from_email' => 'b@x.ci'],
            ['subject' => 'Moi', 'from_email' => 'vente@mailpush.ci'],
        ];
        $svc->sync($a->fresh());
        $out = $flush();
        $this->assertCount(1, $out);
        $this->assertSame('2 nouveaux e-mails · vente@mailpush.ci', $out[0]['notification']['title']);

        // Réponse à un e-mail envoyé
        $sentF = Store::folder($a, 'Sent', 'Envoyés', 'sent');
        Store::upsert($a, $sentF, 's1', ['subject' => 'Notre devis', 'from_email' => 'vente@mailpush.ci', 'message_id' => '<devis-1@mailpush.ci>', 'to' => [['email' => 'achat@hotel.ci']]]);
        $flush();
        $fake->incoming = [['subject' => 'RE: Notre devis', 'from_name' => 'Hôtel Assinie', 'from_email' => 'achat@hotel.ci', 'in_reply_to' => '<devis-1@mailpush.ci>']];
        $svc->sync($a->fresh());
        $this->assertSame('Réponse de Hôtel Assinie', $flush()[0]['notification']['title']);

        // Notifications coupées pour cette boîte (réglage de l'utilisateur)
        $this->api($token)->postJson("/api/mailbox/accounts/{$a->id}/notifications", ['enabled' => false])->assertOk()->assertJsonPath('data.muted_by', [$u->id]);
        $fake->incoming = [['subject' => 'Silence', 'from_email' => 'z@x.ci']];
        $svc->sync($a->fresh());
        $this->assertSame([], $flush());
        $this->api($token)->postJson("/api/mailbox/accounts/{$a->id}/notifications", ['enabled' => true])->assertOk()->assertJsonPath('data.muted_by', []);

        // Envoi définitivement refusé : l'auteur est prévenu (cloche + téléphone)
        $fake->sendFails = true;
        $this->api($token)->postJson('/api/mailbox/send', ['id' => (string) Str::uuid(), 'account_id' => $a->id, 'to' => ['client@exemple.ci'], 'subject' => 'Tout de suite', 'html' => '<p>Bonjour</p>'])->assertStatus(422);
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $u->id)->where('type', 'mail')->count()); // erreur déjà affichée à l'écran
        $this->api($token)->postJson('/api/mailbox/send', ['id' => (string) Str::uuid(), 'account_id' => $a->id, 'to' => ['client@exemple.ci'], 'subject' => 'Votre facture', 'html' => '<p>Bonjour</p>',
            'send_at' => now()->addHour()->toIso8601String()])->assertCreated();
        $this->travel(2)->hours();
        $svc->processOutbox();
        $this->assertSame('E-mail non envoyé : Votre facture', DB::table('app_notifications')->where('user_id', $u->id)->where('type', 'mail')->latest('id')->value('title'));
        $fake->sendFails = false;

        // E-mail programmé parti à l'heure prévue
        $this->api($token)->postJson('/api/mailbox/send', ['id' => (string) Str::uuid(), 'account_id' => $a->id, 'to' => ['client@exemple.ci'], 'subject' => 'Bonne année', 'html' => '<p>Bonjour</p>',
            'send_at' => now()->addHour()->toIso8601String()])->assertCreated();
        $this->travel(2)->hours();
        $svc->processOutbox();
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $u->id)->where('title', 'E-mail programmé envoyé : Bonne année')->count());

        // Premier clic sur un lien suivi : une notification, pas à chaque clic
        $oid = (string) Str::uuid();
        DB::table('mb_outbox')->insert(['id' => $oid, 'company_id' => $c->id, 'account_id' => $a->id, 'user_id' => $u->id, 'payload' => json_encode(['subject' => 'Catalogue', 'to' => ['client@exemple.ci']]),
            'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mb_link_tracks')->insert(['company_id' => $c->id, 'account_id' => $a->id, 'outbox_id' => $oid, 'token' => 'tk1', 'subject' => 'Catalogue', 'created_at' => now(), 'updated_at' => now()]);
        \App\Modules\Mailbox\LinkTracking::click('tk1', 'https://x.ci');
        \App\Modules\Mailbox\LinkTracking::click('tk1', 'https://x.ci');
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $u->id)->where('title', 'Lien ouvert : Catalogue')->count());

        // Boîte en erreur deux fois de suite : une seule alerte, réarmée quand elle refonctionne
        $fake->fail = true;
        foreach ([1, 2, 3] as $_) {
            rescue(fn () => $svc->sync($a->fresh()), null, false);
        }
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $u->id)->where('title', 'Boîte mail déconnectée : vente@mailpush.ci')->count());
        $fake->fail = false;
        $svc->sync($a->fresh());
        $this->assertNull($a->fresh()->stateValue('error_notified'));
    }
}
