<?php

namespace App\Modules\Mailbox\Drivers;

use App\Modules\Mailbox\Composer;
use App\Modules\Mailbox\Mime;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Folder;
use App\Modules\Mailbox\Models\Message;
use App\Modules\Mailbox\OAuth;
use App\Modules\Mailbox\Protocol\ImapClient;
use App\Modules\Mailbox\Protocol\MailProtocolException;
use App\Modules\Mailbox\Store;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** IMAP + SMTP (mot de passe) et Gmail / Google Workspace (IMAP + SMTP en XOAUTH2). */
class ImapDriver implements Driver
{
    public const MAX_BODY = 8 * 1024 * 1024;

    public const BATCH = 150;

    protected array $conn = [];

    public static function server(Account $a, string $proto): array
    {
        if ($a->provider === 'google') {
            return $proto === 'imap' ? ['imap.gmail.com', 993, 'ssl'] : ['smtp.gmail.com', 465, 'ssl'];
        }
        $s = $a->settings ?? [];

        return [$s[$proto.'_host'] ?? '', (int) ($s[$proto.'_port'] ?? ($proto === 'imap' ? 993 : 465)), $s[$proto.'_security'] ?? 'ssl'];
    }

    public function client(Account $a): ImapClient
    {
        if (isset($this->conn[$a->id])) {
            return $this->conn[$a->id];
        }
        [$host, $port, $sec] = self::server($a, 'imap');
        if (! $host) {
            throw new MailProtocolException('Serveur IMAP non renseigné.');
        }
        $c = (new ImapClient)->connect($host, $port, $sec);
        $user = $a->setting('username') ?: $a->email;
        $a->provider === 'google' ? $c->xoauth2($a->email, OAuth::token($a)) : $c->login($user, (string) ($a->secret()['password'] ?? ''));

        return $this->conn[$a->id] = $c;
    }

    public function close(): void
    {
        foreach ($this->conn as $c) {
            $c->logout();
        }
        $this->conn = [];
    }

    public function __destruct()
    {
        $this->close();
    }

    public function test(Account $a): array
    {
        $c = $this->client($a);
        $folders = count($c->listFolders());
        $this->transport($a)->start();

        return ['folders' => $folders];
    }

    public function sync(Account $a): array
    {
        $c = $this->client($a);
        $stats = ['new' => 0, 'updated' => 0, 'deleted' => 0];
        $keep = [];
        $folders = [];
        foreach ($c->listFolders() as $lf) {
            // Gmail : « Tous les messages », « Important », « Suivis » font doublon avec les libellés
            if ($a->provider === 'google' && array_intersect($lf['flags'], ['\\all', '\\important', '\\flagged'])) {
                continue;
            }
            $keep[] = $lf['path'];
            $folders[] = Store::folder($a, $lf['path'], Store::label($lf['full'], $lf['delimiter'], $lf['role']), $lf['role']);
        }
        Store::pruneFolders($a, $keep);
        usort($folders, fn ($x, $y) => ($x->role === 'inbox' ? 0 : 1) <=> ($y->role === 'inbox' ? 0 : 1));
        foreach (array_slice($folders, 0, 60) as $f) {
            if (! $f->sync) {
                continue;
            }
            try {
                $r = $this->syncFolder($a, $c, $f);
                foreach ($r as $k => $v) {
                    $stats[$k] += $v;
                }
            } catch (MailProtocolException $e) {
                if (str_contains($e->getMessage(), 'interrompue') || str_contains($e->getMessage(), 'délai')) {
                    throw $e;
                }
                $f->forceFill(['state' => array_merge($f->state ?? [], ['error' => mb_substr($e->getMessage(), 0, 200)])])->save();
            }
        }

        return $stats;
    }

    protected function syncFolder(Account $a, ImapClient $c, Folder $f): array
    {
        $stats = ['new' => 0, 'updated' => 0, 'deleted' => 0];
        $sel = $c->select($f->remote_id);
        $st = $f->state ?? [];
        if (($st['uidvalidity'] ?? null) !== $sel['uidvalidity']) {
            Message::withoutGlobalScopes()->where('folder_id', $f->id)->forceDelete();
            $st = ['uidvalidity' => $sel['uidvalidity'], 'last_uid' => 0, 'min_uid' => null, 'backfill' => true];
        }
        $since = now()->subDays(max(1, $a->sync_days))->locale('en')->format('d-M-Y');
        $last = (int) ($st['last_uid'] ?? 0);
        $toFetch = [];
        if ($sel['exists'] > 0) {
            if ($last > 0) {
                $toFetch = array_values(array_filter($c->uidSearch('UID '.($last + 1).':*'), fn ($u) => $u > $last));
            } else {
                $toFetch = $c->uidSearch('SINCE '.$since);
            }
            if (! empty($st['backfill']) && ! empty($st['min_uid']) && $st['min_uid'] > 1) {
                $older = $c->uidSearch('UID 1:'.($st['min_uid'] - 1).' SINCE '.$since);
                if (! $older) {
                    $st['backfill'] = false;
                }
                $toFetch = array_merge($older, $toFetch);
            }
        }
        sort($toFetch);
        // les plus récents d'abord ; le reste sera rattrapé aux passages suivants
        if (count($toFetch) > self::BATCH) {
            $toFetch = array_slice($toFetch, -self::BATCH);
            $st['backfill'] = true;
        } elseif ($last > 0 && empty($st['min_uid'])) {
            $st['backfill'] = false;
        }
        foreach (array_chunk($toFetch, 20) as $chunk) {
            $sizes = $c->uidFetch(ImapClient::set($chunk), 'UID RFC822.SIZE FLAGS');
            $small = array_keys(array_filter($sizes, fn ($r) => (int) ($r['RFC822.SIZE'] ?? 0) <= self::MAX_BODY));
            $big = array_diff(array_keys($sizes), $small);
            $rows = $small ? $c->uidFetch(ImapClient::set($small), 'UID BODY.PEEK[]') : [];
            $heads = $big ? $c->uidFetch(ImapClient::set(array_values($big)), 'UID BODY.PEEK[HEADER]') : [];
            foreach ($sizes as $uid => $info) {
                $raw = $rows[$uid]['BODY[]'] ?? $heads[$uid]['BODY[HEADER]'] ?? null;
                if ($raw === null) {
                    continue;
                }
                try {
                    $p = Mime::parse($raw);
                } catch (\Throwable) {
                    continue;
                }
                Store::upsert($a, $f, (string) $uid, $p, self::flagMap($info['FLAGS'] ?? []), (int) ($info['RFC822.SIZE'] ?? 0), isset($rows[$uid]));
                $stats['new']++;
            }
            $st['last_uid'] = max((int) ($st['last_uid'] ?? 0), max($chunk));
            $st['min_uid'] = min($st['min_uid'] ?? PHP_INT_MAX, min($chunk));
        }
        if ($last === 0 && ! $toFetch) {
            $st['last_uid'] = max(0, ($sel['uidnext'] ?: 1) - 1);
            $st['backfill'] = false;
        }
        // drapeaux et suppressions sur la fenêtre déjà synchronisée
        $local = Message::withoutGlobalScopes()->where('folder_id', $f->id)->whereNull('deleted_at')->get(['id', 'remote_id', 'seen', 'flagged', 'answered']);
        if ($local->count() > 0 && $local->count() <= 5000) {
            $min = $local->min(fn ($m) => (int) $m->remote_id);
            $remote = $sel['exists'] > 0 ? $c->uidFetch($min.':*', 'UID FLAGS') : [];
            foreach ($local as $m) {
                if (! isset($remote[(int) $m->remote_id])) {
                    Message::withoutGlobalScopes()->where('id', $m->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
                    $stats['deleted']++;

                    continue;
                }
                $fl = self::flagMap($remote[(int) $m->remote_id]['FLAGS'] ?? []);
                $diff = array_filter(['seen' => $fl['seen'], 'flagged' => $fl['flagged'], 'answered' => $fl['answered']], fn ($v, $k) => (bool) $m->{$k} !== $v, ARRAY_FILTER_USE_BOTH);
                if ($diff) {
                    Message::withoutGlobalScopes()->where('id', $m->id)->update($diff + ['updated_at' => now()]);
                    $stats['updated']++;
                }
            }
        }
        unset($st['error']);
        $f->forceFill(['state' => $st])->save();
        Store::retention($a, $f);
        Store::counts($f);

        return $stats;
    }

    public static function flagMap($flags): array
    {
        $flags = array_map('strtolower', is_array($flags) ? $flags : []);

        return ['seen' => in_array('\\seen', $flags, true), 'flagged' => in_array('\\flagged', $flags, true), 'answered' => in_array('\\answered', $flags, true), 'draft' => in_array('\\draft', $flags, true)];
    }

    protected function raw(Account $a, Message $m): string
    {
        $c = $this->client($a);
        $c->select($m->folder->remote_id);
        $r = $c->uidFetch($m->remote_id, 'UID BODY.PEEK[]');
        $raw = $r[(int) $m->remote_id]['BODY[]'] ?? null;
        if ($raw === null) {
            throw new MailProtocolException('Ce message n\'existe plus sur le serveur.');
        }

        return $raw;
    }

    public function loadBody(Account $a, Message $m): void
    {
        $p = Mime::parse($this->raw($a, $m));
        Store::upsert($a, $m->folder, $m->remote_id, $p, [], $m->size, true);
    }

    public function attachment(Account $a, Message $m, string $attId): ?array
    {
        return Mime::attachment($this->raw($a, $m), $attId);
    }

    public function setFlags(Account $a, Message $m, array $flags): void
    {
        $c = $this->client($a);
        $c->select($m->folder->remote_id);
        foreach (['seen' => '\\Seen', 'flagged' => '\\Flagged', 'answered' => '\\Answered'] as $k => $imap) {
            if (array_key_exists($k, $flags)) {
                $c->uidStore($m->remote_id, $flags[$k] ? '+' : '-', [$imap]);
            }
        }
    }

    public function move(Account $a, Message $m, Folder $target): void
    {
        $c = $this->client($a);
        $c->select($m->folder->remote_id);
        $c->uidMove($m->remote_id, $target->remote_id);
        // le message réapparaîtra dans le dossier cible à la prochaine synchronisation (nouvel UID)
        $m->delete();
    }

    /** Chemin IMAP d'un nouveau dossier : sous le parent, ou sous « INBOX. » si le serveur l'impose (cPanel, Courier). */
    protected function newPath(Account $a, ImapClient $c, string $name, ?Folder $parent): string
    {
        $list = $c->listFolders();
        $delim = $list[0]['delimiter'] ?? '/';
        $name = str_replace($delim, ' ', trim($name));
        if ($parent) {
            return $parent->remote_id.$delim.ImapClient::encodeName($name);
        }
        $others = array_filter($list, fn ($f) => strtoupper($f['path']) !== 'INBOX');
        $prefixed = $others && count(array_filter($others, fn ($f) => str_starts_with(strtoupper($f['path']), 'INBOX'.$delim))) === count($others);

        return ($prefixed ? 'INBOX'.$delim : '').ImapClient::encodeName($name);
    }

    public function createFolder(Account $a, string $name, ?Folder $parent = null): Folder
    {
        $c = $this->client($a);
        $path = $this->newPath($a, $c, $name, $parent);
        $c->command('CREATE '.$c->quote($path));
        try {
            $c->command('SUBSCRIBE '.$c->quote($path));
        } catch (MailProtocolException) {
        }
        $delim = $c->listFolders()[0]['delimiter'] ?? '/';
        return Store::folder($a, $path, Store::label(ImapClient::decodeName($path), $delim), 'other');
    }

    public function renameFolder(Account $a, Folder $f, string $name): void
    {
        $c = $this->client($a);
        $delim = $c->listFolders()[0]['delimiter'] ?? '/';
        $base = str_contains($f->remote_id, $delim) ? substr($f->remote_id, 0, strrpos($f->remote_id, $delim) + 1) : '';
        $new = $base.ImapClient::encodeName(str_replace($delim, ' ', trim($name)));
        $c->command('RENAME '.$c->quote($f->remote_id).' '.$c->quote($new));
        try {
            $c->command('SUBSCRIBE '.$c->quote($new));
        } catch (MailProtocolException) {
        }
        // les sous-dossiers changent aussi de chemin
        foreach (Folder::where('account_id', $a->id)->where('remote_id', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $f->remote_id.$delim).'%')->get() as $ch) {
            $ch->remote_id = $new.substr($ch->remote_id, strlen($f->remote_id));
            $ch->name = Store::label(ImapClient::decodeName($ch->remote_id), $delim);
            $ch->save();
        }
        $f->forceFill(['remote_id' => $new, 'name' => Store::label(ImapClient::decodeName($new), $delim)])->save();
    }

    public function deleteFolder(Account $a, Folder $f): void
    {
        $c = $this->client($a);
        $c->command('DELETE '.$c->quote($f->remote_id));
    }

    public function destroy(Account $a, Message $m): void
    {
        $c = $this->client($a);
        $c->select($m->folder->remote_id);
        $c->uidDelete($m->remote_id);
        $m->delete();
    }

    public function transport(Account $a): EsmtpTransport
    {
        [$host, $port, $sec] = self::server($a, 'smtp');
        if (! $host) {
            throw new MailProtocolException('Serveur d\'envoi (SMTP) non renseigné.');
        }
        ImapClient::guardHost($host);
        $t = new EsmtpTransport($host, $port, $sec === 'ssl' ? true : null);
        if ($sec === 'none') {
            $t->setAutoTls(false);
        }
        $verify = (bool) config('erp.mailbox.verify_tls', true);
        $t->getStream()->setStreamOptions(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify]]);
        if ($a->provider === 'google') {
            $t->setAuthenticators([new XOAuth2Authenticator]);
            $t->setUsername($a->email);
            $t->setPassword(OAuth::token($a));
        } else {
            $t->setUsername($a->setting('smtp_username') ?: ($a->setting('username') ?: $a->email));
            $t->setPassword((string) ($a->secret()['smtp_password'] ?? $a->secret()['password'] ?? ''));
        }

        return $t;
    }

    public function send(Account $a, array $mail): void
    {
        $email = Composer::email($mail);
        try {
            (new Mailer($this->transport($a)))->send($email);
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            throw new MailProtocolException('Envoi refusé par le serveur SMTP : '.mb_substr($e->getMessage(), 0, 300), 0, $e);
        }
        $raw = $email->toString();
        $sent = Folder::where('account_id', $a->id)->where('role', 'sent')->first();
        // Gmail range lui-même les messages envoyés ; ailleurs on dépose une copie dans « Éléments envoyés »
        if ($sent && $a->provider !== 'google') {
            try {
                $c = $this->client($a);
                $uid = $c->append($sent->remote_id, $raw, ['\\Seen']);
                if ($uid) {
                    Store::upsert($a, $sent, (string) $uid, Mime::parse($raw), ['seen' => true], strlen($raw));
                    $st = $sent->state ?? [];
                    if (($st['last_uid'] ?? 0) < $uid && ($st['last_uid'] ?? 0) === $uid - 1) {
                        $st['last_uid'] = $uid;
                        $sent->forceFill(['state' => $st])->save();
                    }
                    Store::counts($sent);
                }
            } catch (\Throwable) { // la copie n'est pas bloquante : le message est parti
            }
        }
    }
}
