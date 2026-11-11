<?php

namespace App\Modules\Mailbox;

use App\Models\Company;
use App\Modules\Mailbox\Drivers\Driver;
use App\Modules\Mailbox\Drivers\GraphDriver;
use App\Modules\Mailbox\Drivers\ImapDriver;
use App\Modules\Mailbox\Drivers\Pop3Driver;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Folder;
use App\Modules\Mailbox\Models\Message;
use App\Modules\Mailbox\Protocol\MailProtocolException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MailboxService
{
    protected array $drivers = [];

    /** Pour les tests : remplace le pilote d'un fournisseur. */
    public static array $fakes = [];

    public function driver(Account $a): Driver
    {
        if (isset(self::$fakes[$a->provider])) {
            return self::$fakes[$a->provider];
        }

        return $this->drivers[$a->provider] ??= match ($a->provider) {
            'microsoft' => new GraphDriver,
            'pop3' => new Pop3Driver,
            default => new ImapDriver,
        };
    }

    public function close(): void
    {
        foreach ($this->drivers as $d) {
            if ($d instanceof ImapDriver) {
                $d->close();
            }
        }
        $this->drivers = [];
    }

    /** Synchronise un compte (verrou : une seule synchro à la fois par compte). */
    public function sync(Account $a, bool $force = false): array
    {
        // Connexion Gmail / Outlook coupée par le super admin : le compte n'est plus relevé
        if (in_array($a->provider, ['google', 'microsoft'], true) && ! OAuth::enabled($a->provider)) {
            $a->forceFill(['status' => 'error', 'error' => 'Connexion '.($a->provider === 'google' ? 'Gmail' : 'Outlook').' désactivée par l\'administrateur de la plateforme.'])->save();

            return ['skipped' => true, 'disabled' => true];
        }
        $lock = Cache::lock('mailbox-sync-'.$a->id, 600);
        if (! $lock->get()) {
            return ['skipped' => true];
        }
        try {
            app()->instance('tenant', Company::find($a->company_id));
            $r = $this->driver($a)->sync($a);
            $a->forceFill(['status' => 'active', 'error' => null, 'last_sync_at' => now(), 'next_sync_at' => now()->addMinutes($this->interval($a))])->save();
            if ($a->calendar_sync && in_array($a->provider, ['microsoft', 'google'], true) && $a->user_id
                && ($force || ! $a->stateValue('cal_at') || $a->stateValue('cal_at') < time() - 600)) {
                try {
                    $r['calendar'] = app(CalendarSync::class)->sync($a);
                } catch (\Throwable $e) {
                    $r['calendar_error'] = $e->getMessage();
                }
                $a->putState(['cal_at' => time()]);
                $a->saveQuietly();
            }

            return $r;
        } catch (MailProtocolException|\DomainException $e) {
            $a->forceFill(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500), 'next_sync_at' => now()->addMinutes(15)])->save();
            throw $e;
        } finally {
            $this->close();
            $lock->release();
        }
    }

    protected function interval(Account $a): int
    {
        // Boîte ouverte dans l'ERP : relève chaque minute ; sinon toutes les 3 min (10 min en POP3)
        if (Cache::has('mailbox-live-'.$a->id)) {
            return 1;
        }

        return $a->provider === 'pop3' ? 10 : 3;
    }

    public function runDue(int $max = 20): array
    {
        $done = 0;
        $errors = 0;
        $accounts = Account::withoutGlobalScopes()->where('status', '!=', 'paused')->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))
            ->orderBy('next_sync_at')->limit($max)->get();
        foreach ($accounts as $a) {
            $company = Company::find($a->company_id);
            if (! $company || ! $company->hasModule('mailbox')) {
                $a->forceFill(['next_sync_at' => now()->addHour()])->saveQuietly();

                continue;
            }
            try {
                $this->sync($a);
                $done++;
            } catch (\Throwable) {
                $errors++;
            }
        }
        $sent = $this->processOutbox();

        return ['synced' => $done, 'errors' => $errors, 'sent' => $sent];
    }

    // ------------------------------------------------------------------ actions sur les messages

    public function flags(Message $m, array $flags): void
    {
        $a = $m->account;
        Store::flags($m, $flags);
        // Copie locale d'un envoi pas encore remplacée par celle du serveur : rien à changer à distance
        if ($a->provider !== 'pop3' && str_starts_with((string) $m->remote_id, 'local:')) {
            Store::counts($m->folder);

            return;
        }
        $this->driver($a)->setFlags($a, $m, $flags);
        Store::counts($m->folder);
    }

    public function move(Message $m, Folder $target): void
    {
        abort_unless($target->account_id === $m->account_id, 422, 'Déplacement possible uniquement dans le même compte.');
        $from = $m->folder;
        if ($m->account->provider !== 'pop3' && str_starts_with((string) $m->remote_id, 'local:')) {
            $m->forceFill(['folder_id' => $target->id])->save(); // copie locale : déplacée ici seulement
        } else {
            $this->driver($m->account)->move($m->account, $m, $target);
        }
        Store::counts($from);
        Store::counts($target);
    }

    public function createFolder(Account $a, string $name, ?Folder $parent = null): Folder
    {
        $name = trim($name);
        ($name !== '' && mb_strlen($name) <= 80) || throw new \DomainException('Donnez un nom de 1 à 80 caractères.');
        abort_if($parent && $parent->account_id !== $a->id, 422, 'Le dossier parent appartient à un autre compte.');
        $f = $this->driver($a)->createFolder($a, $name, $parent);
        Store::counts($f);

        return $f;
    }

    public function renameFolder(Folder $f, string $name): void
    {
        $name = trim($name);
        ($name !== '' && mb_strlen($name) <= 80) || throw new \DomainException('Donnez un nom de 1 à 80 caractères.');
        $f->role === 'other' || throw new \DomainException('Les dossiers système (réception, envoyés…) ne se renomment pas.');
        $this->driver($f->account)->renameFolder($f->account, $f, $name);
    }

    public function deleteFolder(Folder $f): void
    {
        $f->role === 'other' || throw new \DomainException('Les dossiers système (réception, envoyés…) ne se suppriment pas.');
        $a = $f->account;
        // sous-dossiers d'abord (les serveurs IMAP refusent de supprimer un dossier qui en contient)
        $children = Folder::where('account_id', $a->id)->where('id', '!=', $f->id)->where('name', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $f->name.' › ').'%')->get()
            ->sortByDesc(fn ($c) => substr_count($c->name, ' › '));
        foreach ([...$children, $f] as $x) {
            if ($x === $f || $a->provider !== 'microsoft') { // Microsoft 365 supprime les sous-dossiers avec leur parent
                $this->driver($a)->deleteFolder($a, $x);
            }
            Message::withoutGlobalScopes()->where('folder_id', $x->id)->forceDelete();
            $x->delete();
        }
    }

    /** Supprimer : vers la corbeille, ou définitivement si le message y est déjà. */
    public function delete(Message $m): void
    {
        $trash = Folder::where('account_id', $m->account_id)->where('role', 'trash')->first();
        $from = $m->folder;
        if ($trash && $m->folder_id !== $trash->id) {
            $this->move($m, $trash);

            return;
        }
        if ($m->account->provider !== 'pop3' && str_starts_with((string) $m->remote_id, 'local:')) {
            $m->delete(); // copie locale d'un envoi : supprimée ici seulement
        } else {
            $this->driver($m->account)->destroy($m->account, $m);
        }
        Store::counts($from);
    }

    public function attachment(Message $m, string $attId): ?array
    {
        $key = 'mb-att-'.$m->id.'-'.md5($attId);
        if ($hit = Cache::get($key)) {
            return unserialize(gzuncompress($hit));
        }
        $att = $this->driver($m->account)->attachment($m->account, $m, $attId);
        if ($att && strlen($att['content']) < 5 * 1024 * 1024) {
            Cache::put($key, gzcompress(serialize($att)), now()->addHours(6));
        }

        return $att;
    }

    public function loadBody(Message $m): void
    {
        $this->driver($m->account)->loadBody($m->account, $m);
    }

    // ------------------------------------------------------------------ envoi

    /** Met un envoi en file (id fourni par le poste : rejouer la requête ne double jamais l'envoi). */
    public function queue(Account $a, int $userId, string $id, array $payload, ?array $mail = null): array
    {
        $existing = DB::table('mb_outbox')->where('id', $id)->first();
        if ($existing) {
            return (array) $existing;
        }
        $sendAt = ! empty($payload['send_at']) ? \Carbon\Carbon::parse($payload['send_at']) : null;
        DB::table('mb_outbox')->insert(['id' => $id, 'company_id' => $a->company_id, 'account_id' => $a->id, 'user_id' => $userId, 'payload' => json_encode($payload),
            'fingerprint' => $mail ? Antispam::fingerprint($mail) : null, 'recipients' => $mail ? max(1, count($mail['to']) + count($mail['cc']) + count($mail['bcc'])) : 1,
            'send_at' => $sendAt && $sendAt->isFuture() ? $sendAt : null, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        if (! $sendAt || ! $sendAt->isFuture()) {
            $this->deliver($id);
        }

        return (array) DB::table('mb_outbox')->where('id', $id)->first();
    }

    public function deliver(string $id): bool
    {
        $claimed = DB::table('mb_outbox')->where('id', $id)->where('status', 'queued')->update(['status' => 'sending', 'updated_at' => now()]);
        if (! $claimed) {
            return false;
        }
        $row = DB::table('mb_outbox')->where('id', $id)->first();
        $a = Account::withoutGlobalScopes()->find($row->account_id);
        app()->instance('tenant', Company::find($row->company_id));
        $user = \App\Models\User::find($row->user_id);
        $payload = json_decode($row->payload, true);
        try {
            if (! $a) {
                throw new \DomainException('Compte de messagerie supprimé.');
            }
            $mail = Composer::build($a, $payload, $user);
            if ($until = $a->stateValue('send_blocked_until')) {
                if ($until > time()) {
                    throw new \DomainException('Envois suspendus jusqu\'à '.date('H:i', $until).' après plusieurs refus du serveur.');
                }
            }
            $this->driver($a)->send($a, $mail);
            Antispam::recordSuccess($a);
            $this->keepSentCopy($a, $mail);
            DB::table('mb_outbox')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'attempts' => $row->attempts + 1, 'updated_at' => now(),
                'payload' => json_encode(array_merge($payload, ['attachments' => array_map(fn ($x) => array_diff_key($x, ['data' => 1]), $payload['attachments'] ?? [])]))]);
            if ($mail['original'] && in_array($mail['mode'], ['reply', 'reply_all'], true)) {
                try {
                    Store::flags($mail['original'], ['answered' => true]);
                    if ($a->provider !== 'microsoft') {
                        $this->driver($a)->setFlags($a, $mail['original'], ['answered' => true]);
                    }
                } catch (\Throwable) {
                }
            }
            if ($a->provider === 'microsoft') {
                $a->forceFill(['next_sync_at' => now()])->saveQuietly(); // l'élément envoyé arrive par la synchro
            }

            return true;
        } catch (\Throwable $e) {
            if ($a && $e instanceof MailProtocolException) {
                Antispam::recordFailure($a, $e->getMessage());
            }
            $attempts = $row->attempts + 1;
            $final = $e instanceof \DomainException || $attempts >= 4;
            DB::table('mb_outbox')->where('id', $id)->update(['status' => $final ? 'failed' : 'queued', 'attempts' => $attempts, 'error' => mb_substr($e->getMessage(), 0, 500),
                'send_at' => $final ? $row->send_at : now()->addMinutes([1, 5, 30][$attempts - 1] ?? 30), 'updated_at' => now()]);

            return false;
        } finally {
            $this->close();
        }
    }

    /**
     * Le message envoyé apparaît tout de suite dans « Éléments envoyés » et dans la conversation, même quand le serveur
     * ne renvoie pas l'identifiant de sa copie (Microsoft 365, Gmail, serveurs sans UIDPLUS) : copie locale, remplacée
     * par celle du serveur à la synchronisation suivante.
     */
    protected function keepSentCopy(Account $a, array $mail): void
    {
        try {
            $mid = Mime::mid($mail['message_id'] ?? null);
            if (! $mid || Message::withoutGlobalScopes()->where('account_id', $a->id)->where('message_id', $mid)->exists()) {
                return;
            }
            $sent = Folder::where('account_id', $a->id)->where('role', 'sent')->first();
            if (! $sent) {
                return;
            }
            $raw = Composer::email($mail)->toString();
            Store::upsert($a, $sent, 'local:'.trim($mid, '<>'), Mime::parse($raw), ['seen' => true], strlen($raw));
            Store::counts($sent);
        } catch (\Throwable) {
            // la copie n'est pas bloquante : le message est parti
        }
    }

    public function processOutbox(): int
    {
        $n = 0;
        foreach (DB::table('mb_outbox')->where('status', 'queued')->where(fn ($q) => $q->whereNull('send_at')->orWhere('send_at', '<=', now()))->orderBy('created_at')->limit(30)->pluck('id') as $id) {
            $n += $this->deliver($id) ? 1 : 0;
        }
        // envois restés bloqués « en cours » (processus interrompu)
        DB::table('mb_outbox')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'queued', 'updated_at' => now()]);

        return $n;
    }
}
