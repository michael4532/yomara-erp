<?php

namespace App\Modules\Mailbox\Drivers;

use App\Modules\Mailbox\Composer;
use App\Modules\Mailbox\Mime;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Folder;
use App\Modules\Mailbox\Models\Message;
use App\Modules\Mailbox\Protocol\MailProtocolException;
use App\Modules\Mailbox\Protocol\Pop3Client;
use App\Modules\Mailbox\Store;
use Symfony\Component\Mailer\Mailer;

/**
 * POP3 : relève de la boîte de réception (copie laissée sur le serveur par défaut). Les dossiers, la corbeille et les
 * messages envoyés sont tenus par Yomara, car POP3 ne connaît que la boîte de réception.
 */
class Pop3Driver implements Driver
{
    public const MAX = 200;

    protected function client(Account $a): Pop3Client
    {
        $s = $a->settings ?? [];
        if (empty($s['pop3_host'])) {
            throw new MailProtocolException('Serveur POP3 non renseigné.');
        }

        return (new Pop3Client)->connect($s['pop3_host'], (int) ($s['pop3_port'] ?? 995), $s['pop3_security'] ?? 'ssl')
            ->login($s['username'] ?? $a->email, (string) ($a->secret()['password'] ?? ''));
    }

    public static function localFolders(Account $a): array
    {
        $out = [];
        foreach (['INBOX' => ['Boîte de réception', 'inbox'], 'local:sent' => ['Envoyés', 'sent'], 'local:archive' => ['Archives', 'archive'], 'local:trash' => ['Corbeille', 'trash']] as $rid => [$n, $r]) {
            $out[$r] = Store::folder($a, $rid, $n, $r);
        }

        return $out;
    }

    public function test(Account $a): array
    {
        $c = $this->client($a);
        $n = count($c->sizes());
        $c->quit();
        (new ImapDriver)->transport($a)->start();

        return ['messages' => $n];
    }

    public function sync(Account $a): array
    {
        $folders = self::localFolders($a);
        $inbox = $folders['inbox'];
        $c = $this->client($a);
        $uidl = $c->uidl();
        $sizes = $c->sizes();
        $seen = array_flip($a->stateValue('uidl', []));
        $new = array_filter($uidl, fn ($u) => ! isset($seen[$u]));
        if (! $seen && count($new) > self::MAX) { // première relève : les plus récents seulement
            $new = array_slice($new, -self::MAX, null, true);
        }
        $keep = (bool) $a->setting('pop_keep', true);
        $n = 0;
        foreach (array_slice($new, 0, self::MAX, true) as $num => $uid) {
            if (($sizes[$num] ?? 0) > ImapDriver::MAX_BODY) {
                $seen[$uid] = true;

                continue;
            }
            $raw = $c->retr($num);
            try {
                $p = Mime::parse($raw);
                if ($p['date'] && $p['date']->lt(now()->subDays(max($a->sync_days, 1)))) {
                    $seen[$uid] = true;

                    continue;
                }
                Store::upsert($a, $inbox, 'pop:'.$uid, $p, ['seen' => false], strlen($raw));
                $n++;
            } catch (\Throwable) {
            }
            $seen[$uid] = true;
            if (! $keep) {
                $c->delete($num);
            }
        }
        $c->quit();
        // on ne garde la mémoire que des identifiants encore présents (et des nouveaux)
        $present = array_flip(array_values($uidl));
        $a->putState(['uidl' => array_values(array_slice(array_keys(array_filter($seen, fn ($v, $k) => isset($present[$k]), ARRAY_FILTER_USE_BOTH)), -20000))]);
        $a->saveQuietly();
        foreach ($folders as $f) {
            Store::retention($a, $f);
            Store::counts($f);
        }

        return ['new' => $n, 'updated' => 0, 'deleted' => 0];
    }

    public function loadBody(Account $a, Message $m): void {}

    public function attachment(Account $a, Message $m, string $attId): ?array
    {
        $uid = substr($m->remote_id, 4);
        $c = $this->client($a);
        $num = array_search($uid, $c->uidl(), true);
        if ($num === false) {
            $c->quit();
            throw new MailProtocolException('Ce message n\'est plus sur le serveur POP3 : la pièce jointe n\'est plus disponible.');
        }
        $raw = $c->retr($num);
        $c->quit();

        return Mime::attachment($raw, $attId);
    }

    public function setFlags(Account $a, Message $m, array $flags): void {}

    public function move(Account $a, Message $m, Folder $target): void
    {
        $m->folder_id = $target->id;
        $m->save();
    }

    public function destroy(Account $a, Message $m): void
    {
        $m->delete();
    }

    // POP3 ne connaît qu'une boîte : les dossiers de rangement sont locaux à Yomara
    public function createFolder(Account $a, string $name, ?Folder $parent = null): Folder
    {
        $name = trim($name);

        return Store::folder($a, 'local:'.\Illuminate\Support\Str::uuid(), $parent ? $parent->name.' › '.$name : $name, 'other');
    }

    public function renameFolder(Account $a, Folder $f, string $name): void
    {
        $parts = explode(' › ', $f->name);
        $parts[count($parts) - 1] = trim($name);
        $f->forceFill(['name' => implode(' › ', $parts)])->save();
    }

    public function deleteFolder(Account $a, Folder $f): void
    {
        // les messages repartent dans la boîte de réception
        $inbox = Folder::where('account_id', $a->id)->where('role', 'inbox')->first();
        if ($inbox) {
            Message::withoutGlobalScopes()->where('folder_id', $f->id)->update(['folder_id' => $inbox->id]);
            Store::counts($inbox);
        }
    }

    public function send(Account $a, array $mail): void
    {
        $email = Composer::email($mail);
        try {
            (new Mailer((new ImapDriver)->transport($a)))->send($email);
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            throw new MailProtocolException('Envoi refusé par le serveur SMTP : '.mb_substr($e->getMessage(), 0, 300), 0, $e);
        }
        $raw = $email->toString();
        $sent = self::localFolders($a)['sent'];
        Store::upsert($a, $sent, 'local:'.trim($mail['message_id'], '<>'), Mime::parse($raw), ['seen' => true], strlen($raw));
        Store::counts($sent);
    }
}
