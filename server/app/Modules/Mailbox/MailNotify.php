<?php

namespace App\Modules\Mailbox;

use App\Models\AppNotification;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Folder;
use App\Modules\Mailbox\Models\Message;
use App\Support\Push;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Notifications YoMail sur le téléphone :
 * - nouvel e-mail reçu dans la boîte de réception (regroupés quand plusieurs arrivent ensemble) ;
 * - réponse reçue à un e-mail envoyé ;
 * - e-mail non envoyé, e-mail programmé parti, premier clic sur un lien suivi, boîte en erreur (aussi dans la cloche).
 * Chaque utilisateur peut couper les notifications d'une boîte (state.mute = ids des utilisateurs).
 */
class MailNotify
{
    /** Messages arrivés pendant la synchronisation en cours : [account_id => [Message, …]] */
    protected static array $arrived = [];

    /** Appelé par Store::upsert pour chaque message créé. */
    public static function collect(Account $a, Folder $f, Message $m): void
    {
        if ($f->role !== 'inbox' || $m->seen || ! $a->last_sync_at) {
            return; // première synchronisation : pas de rafale de notifications pour l'historique
        }
        if ($m->date && $m->date->lt(now()->subDay())) {
            return; // ancien message rapatrié (dossier resynchronisé)
        }
        if ($m->from_email && mb_strtolower($m->from_email) === mb_strtolower($a->email)) {
            return; // copie d'un message envoyé par soi-même
        }
        static::$arrived[$a->id][] = $m;
    }

    /** Fin de synchronisation : une notification par boîte (un e-mail = son expéditeur et son objet, plusieurs = un résumé). */
    public static function flush(Account $a): void
    {
        $list = static::$arrived[$a->id] ?? [];
        unset(static::$arrived[$a->id]);
        $users = static::recipients($a);
        if (! $list || ! $users) {
            return;
        }
        $ids = collect($list)->pluck('id')->all();
        // le message a pu être lu ou supprimé entre-temps (autre appareil)
        $list = Message::withoutGlobalScopes()->whereIn('id', $ids)->where('seen', false)->orderByDesc('date')->get()->all();
        if (! $list) {
            return;
        }
        $data = ['type' => 'mail', 'module' => 'mailbox', 'ref' => (string) $list[0]->id];
        if (count($list) === 1) {
            $m = $list[0];
            $who = $m->from_name ?: ($m->from_email ?: 'Expéditeur inconnu');
            $title = static::isReply($a, $m) ? 'Réponse de '.$who : $who;
            $body = Str::limit($m->subject ?: '(sans objet)', 90);
            if ($m->snippet) {
                $body .= "\n".Str::limit(trim($m->snippet), 140);
            }
            if ($m->has_attachments) {
                $body .= "\n📎 Pièce jointe";
            }
        } else {
            $title = count($list).' nouveaux e-mails · '.$a->email;
            $body = collect($list)->take(4)->map(fn ($m) => Str::limit(($m->from_name ?: $m->from_email).' : '.($m->subject ?: '(sans objet)'), 80))->implode("\n");
            if (count($list) > 4) {
                $body .= "\n… et ".(count($list) - 4).' autre(s)';
            }
        }
        Push::queue($users, $title, $body, $data + ['account' => (string) $a->id]);
    }

    /** Réponse à un e-mail envoyé depuis cette boîte. */
    protected static function isReply(Account $a, Message $m): bool
    {
        if (! $m->in_reply_to) {
            return false;
        }

        return Message::withoutGlobalScopes()->where('account_id', $a->id)->where('message_id', $m->in_reply_to)
            ->whereIn('folder_id', Folder::where('account_id', $a->id)->where('role', 'sent')->pluck('id'))->exists();
    }

    /** Propriétaire de la boîte, ou membres d'une boîte partagée, moins ceux qui ont coupé ses notifications. */
    public static function recipients(Account $a): array
    {
        $users = $a->user_id ? [(int) $a->user_id] : array_map('intval', $a->shared_with ?? []);
        $mute = array_map('intval', (array) $a->stateValue('mute', []));

        return array_values(array_diff($users, $mute));
    }

    /** E-mail définitivement non envoyé : l'auteur est prévenu (cloche + téléphone). */
    public static function failed(object $row, string $error): void
    {
        if ((int) $row->attempts === 0 && ! $row->send_at) {
            return; // envoi immédiat : l'erreur s'affiche déjà à l'écran de l'utilisateur
        }
        $p = json_decode($row->payload, true) ?: [];
        AppNotification::send((int) $row->company_id, [(int) $row->user_id], 'mail', 'E-mail non envoyé : '.Str::limit($p['subject'] ?? '(sans objet)', 80),
            'À '.static::to($p).' — '.Str::limit($error, 200), 'mailbox', (string) $row->id);
    }

    /** E-mail programmé parti à l'heure prévue. */
    public static function scheduledSent(object $row): void
    {
        if (! $row->send_at || \Illuminate\Support\Carbon::parse($row->send_at)->lt(\Illuminate\Support\Carbon::parse($row->created_at)->addMinutes(2))) {
            return; // envoi immédiat (ou « annuler l'envoi » de quelques secondes) : pas de notification
        }
        $p = json_decode($row->payload, true) ?: [];
        AppNotification::send((int) $row->company_id, [(int) $row->user_id], 'mail', 'E-mail programmé envoyé : '.Str::limit($p['subject'] ?? '(sans objet)', 80),
            'À '.static::to($p), 'mailbox', (string) $row->id);
    }

    /** Premier clic d'un destinataire sur un lien suivi. */
    public static function firstClick(object $track): void
    {
        $out = $track->outbox_id ? DB::table('mb_outbox')->where('id', $track->outbox_id)->first() : null;
        if (! $out) {
            return;
        }
        $p = json_decode($out->payload, true) ?: [];
        AppNotification::send((int) $out->company_id, [(int) $out->user_id], 'mail', 'Lien ouvert : '.Str::limit($track->subject ?: ($p['subject'] ?? '(sans objet)'), 80),
            static::to($p).' a cliqué sur un lien de votre e-mail.', 'mailbox', (string) $out->id);
    }

    /** Boîte qui passe en erreur (mot de passe changé, accès retiré…) : une seule notification jusqu'à ce qu'elle refonctionne. */
    public static function accountError(Account $a, string $error): void
    {
        if ($a->stateValue('error_notified')) {
            return;
        }
        $a->putState(['error_notified' => true]);
        $a->saveQuietly();
        AppNotification::send((int) $a->company_id, static::recipients($a), 'mail', 'Boîte mail déconnectée : '.$a->email,
            'Les e-mails ne sont plus relevés. Ouvrez YoMail › Comptes pour vous reconnecter. '.Str::limit($error, 160), 'mailbox', (string) $a->id);
    }

    public static function accountOk(Account $a): void
    {
        if ($a->stateValue('error_notified')) {
            $a->putState(['error_notified' => null]);
            $a->saveQuietly();
        }
    }

    protected static function to(array $p): string
    {
        $to = collect($p['to'] ?? [])->map(fn ($x) => is_array($x) ? ($x['name'] ?? null ?: ($x['email'] ?? '')) : (string) $x)->filter()->values();

        return $to->isEmpty() ? 'votre destinataire' : $to->first().($to->count() > 1 ? ' et '.($to->count() - 1).' autre(s)' : '');
    }
}
