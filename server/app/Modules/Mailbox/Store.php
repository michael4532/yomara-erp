<?php

namespace App\Modules\Mailbox;

use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Folder;
use App\Modules\Mailbox\Models\Message;
use Illuminate\Support\Facades\DB;

/** Enregistrement local des dossiers et messages synchronisés (commun à tous les fournisseurs). */
class Store
{
    protected static array $partners = [];

    public static function folder(Account $a, string $remoteId, string $name, string $role = 'other'): Folder
    {
        $f = Folder::firstOrNew(['account_id' => $a->id, 'remote_id' => $remoteId]);
        $f->name = mb_substr($name, 0, 190);
        if (! $f->exists || $f->role === 'other' || $role !== 'other') {
            $f->role = $role;
        }
        if (! $f->exists) {
            // les dossiers volumineux ou sans intérêt ne sont pas synchronisés par défaut
            $f->sync = ! in_array($role, ['junk'], true);
        }
        $f->save();

        return $f;
    }

    /** Libellés simples des dossiers spéciaux. */
    public const LABELS = ['inbox' => 'Boîte de réception', 'sent' => 'Envoyés', 'drafts' => 'Brouillons', 'trash' => 'Corbeille', 'junk' => 'Spam', 'archive' => 'Archives'];

    /**
     * Nom lisible d'un dossier : « INBOX.Sent » → « Envoyés », « INBOX.Clients.Abidjan » → « Clients › Abidjan ».
     * Les serveurs cPanel/Courier préfixent tout par « INBOX. » : ce préfixe n'est jamais affiché.
     */
    public static function label(string $full, ?string $delim, string $role = 'other'): string
    {
        if (isset(self::LABELS[$role])) {
            return self::LABELS[$role];
        }
        $delim = $delim ?: (str_starts_with(strtoupper($full), 'INBOX.') ? '.' : '/');
        $parts = $delim !== '' ? explode($delim, $full) : [$full];
        if (count($parts) > 1 && in_array(strtoupper($parts[0]), ['INBOX', '[GMAIL]', '[GOOGLE MAIL]'], true)) {
            array_shift($parts);
        }
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($x) => $x !== ''));

        return $parts ? implode(' › ', $parts) : $full;
    }

    /** Supprime les dossiers disparus côté serveur et leurs messages. */
    public static function pruneFolders(Account $a, array $keepRemoteIds): void
    {
        $gone = Folder::where('account_id', $a->id)->where('remote_id', 'not like', 'local:%')->whereNotIn('remote_id', $keepRemoteIds)->pluck('id');
        if ($gone->isNotEmpty()) {
            Message::withoutGlobalScopes()->whereIn('folder_id', $gone)->forceDelete();
            Folder::whereIn('id', $gone)->delete();
        }
    }

    /**
     * Crée ou met à jour un message à partir de données normalisées (voir Mime::parse).
     * $p : subject, from_name, from_email, to, cc, bcc, reply_to, date, html, text, attachments, message_id, in_reply_to, references, thread
     */
    public static function upsert(Account $a, Folder $f, string $remoteId, array $p, array $flags = [], int $size = 0, bool $bodyLoaded = true): Message
    {
        $m = Message::withTrashed()->firstOrNew(['folder_id' => $f->id, 'remote_id' => $remoteId]);
        $isNew = ! $m->exists;
        $html = isset($p['html']) && $p['html'] !== null ? Mime::sanitize($p['html']) : null;
        if ($html === null && ! empty($p['text'])) {
            $html = Mime::textToHtml($p['text']);
        }
        $text = $p['text'] ?? null;
        if (($text === null || trim($text) === '') && $html) {
            $text = trim(html_entity_decode(strip_tags(preg_replace(['#<(style|script)[^>]*>.*?</\1>#is', '#<br\s*/?>#i', '#</(p|div|tr|li|h\d)>#i'], [' ', "\n", "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $atts = array_values($p['attachments'] ?? []);
        if (empty($p['thread']) && ! empty($p['in_reply_to']) && $isNew) {
            // réponse à un message connu : même conversation que lui
            $p['thread'] = Message::withoutGlobalScopes()->where('company_id', $a->company_id)->where('account_id', $a->id)->where('message_id', $p['in_reply_to'])->value('thread_key');
        }
        $m->fill([
            'company_id' => $a->company_id,
            'account_id' => $a->id,
            'message_id' => $p['message_id'] ?? null,
            'in_reply_to' => $p['in_reply_to'] ?? null,
            'references' => isset($p['references']) ? mb_substr($p['references'], 0, 4000) : null,
            'thread_key' => $p['thread'] ?? Mime::threadKey($p['subject'] ?? null, $p['references'] ?? null, $p['in_reply_to'] ?? null, $p['message_id'] ?? $remoteId),
            'subject' => mb_substr((string) ($p['subject'] ?? ''), 0, 500) ?: null,
            'from_name' => isset($p['from_name']) ? mb_substr($p['from_name'], 0, 190) : null,
            'from_email' => isset($p['from_email']) ? mb_substr(mb_strtolower($p['from_email']), 0, 190) : null,
            'to' => $p['to'] ?? [],
            'cc' => $p['cc'] ?? [],
            'bcc' => $p['bcc'] ?? [],
            'reply_to' => $p['reply_to'] ?? null,
            'date' => $p['date'] ?? now(),
            'size' => $size,
        ]);
        if ($bodyLoaded || $isNew) {
            $m->body_html = $html;
            $m->body_text = $text !== null ? mb_substr($text, 0, 500000) : null;
            $m->snippet = $p['snippet'] ?? Mime::snippet($text, $html);
            $m->attachments = $atts;
            $m->has_attachments = $p['has_attachments'] ?? (bool) array_filter($atts, fn ($x) => empty($x['inline']));
            $m->body_loaded = $bodyLoaded;
        }
        foreach (['seen', 'flagged', 'answered', 'draft'] as $k) {
            if (array_key_exists($k, $flags)) {
                $m->{$k} = (bool) $flags[$k];
            }
        }
        if ($f->role === 'sent' || $f->role === 'drafts') {
            $m->seen = true;
        }
        $m->partner_id = $m->partner_id ?: self::partnerFor($a, $f->role === 'sent' ? ($m->to[0]['email'] ?? null) : $m->from_email);
        $m->deleted_at = null;
        $m->save();

        return $m;
    }

    public static function partnerFor(Account $a, ?string $email): ?string
    {
        if (! $email) {
            return null;
        }
        $email = mb_strtolower($email);
        $k = $a->company_id.'|'.$email;
        if (! array_key_exists($k, self::$partners)) {
            self::$partners[$k] = DB::table('partners')->where('company_id', $a->company_id)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])->value('id');
        }

        return self::$partners[$k];
    }

    public static function flags(Message $m, array $flags): void
    {
        $dirty = false;
        foreach (['seen', 'flagged', 'answered', 'draft'] as $k) {
            if (array_key_exists($k, $flags) && (bool) $m->{$k} !== (bool) $flags[$k]) {
                $m->{$k} = (bool) $flags[$k];
                $dirty = true;
            }
        }
        if ($dirty) {
            $m->save();
        }
    }

    public static function counts(Folder $f): void
    {
        $q = Message::withoutGlobalScopes()->where('folder_id', $f->id)->whereNull('deleted_at');
        $f->forceFill(['total' => (clone $q)->count(), 'unread' => (clone $q)->where('seen', false)->count()])->save();
    }

    public static function retention(Account $a, Folder $f): void
    {
        $days = max($a->sync_days, (int) (DB::table('mb_settings')->where('company_id', $a->company_id)->value('retention_days') ?? 90));
        Message::withoutGlobalScopes()->where('folder_id', $f->id)->whereNull('deleted_at')->where('date', '<', now()->subDays($days))->where('flagged', false)->update(['deleted_at' => now(), 'updated_at' => now()]);
        Message::withoutGlobalScopes()->where('folder_id', $f->id)->whereNotNull('deleted_at')->where('deleted_at', '<', now()->subDays(14))->forceDelete();
    }
}
