<?php

namespace App\Modules\Mailbox;

use App\Modules\Documents\DocumentAccess;
use App\Modules\Documents\Models\DocFile;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Message;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Prépare un envoi à partir de ce que l'application transmet :
 * to/cc/bcc [{name, email}], subject, html, mode (new|reply|reply_all|forward), message_id (message d'origine),
 * attachments [{type: upload, name, mime, data(base64)} | {type: document, id} | {type: original, id}], read_receipt, importance.
 */
class Composer
{
    public const MAX_TOTAL = 25 * 1024 * 1024;

    public static function build(Account $a, array $p, $user = null): array
    {
        $orig = ! empty($p['message_id']) ? Message::find($p['message_id']) : null;
        $atts = [];
        $total = 0;
        foreach ($p['attachments'] ?? [] as $x) {
            $type = $x['type'] ?? 'upload';
            if ($type === 'upload') {
                $content = base64_decode((string) ($x['data'] ?? ''), true);
                if ($content === false) {
                    throw new \DomainException('Pièce jointe illisible : '.($x['name'] ?? ''));
                }
                $att = ['name' => (string) ($x['name'] ?? 'piece-jointe'), 'mime' => (string) ($x['mime'] ?? 'application/octet-stream'), 'content' => $content];
            } elseif ($type === 'document') {
                $f = DocFile::find($x['id'] ?? null);
                $bytes = $f && (! $user || DocumentAccess::canSee($user, $f->folder_id)) ? app(\App\Modules\Documents\DocumentService::class)->bytes($f) : null;
                if ($bytes === null) {
                    throw new \DomainException('Document introuvable ou non autorisé.');
                }
                $att = ['name' => $f->name, 'mime' => $f->mime ?: 'application/octet-stream', 'content' => $bytes];
            } elseif ($type === 'original' && $orig) {
                $att = app(MailboxService::class)->attachment($orig, (string) $x['id']);
                if (! $att) {
                    continue;
                }
            } else {
                continue;
            }
            $total += strlen($att['content']);
            if ($total > self::MAX_TOTAL) {
                throw new \DomainException('Les pièces jointes dépassent 25 Mo : partagez plutôt un lien depuis les Documents.');
            }
            $atts[] = $att;
        }
        $list = fn ($v) => array_values(array_filter(array_map(fn ($r) => is_string($r) ? ['email' => trim($r)] : ['name' => $r['name'] ?? null, 'email' => trim((string) ($r['email'] ?? ''))], (array) ($v ?? [])),
            fn ($r) => filter_var($r['email'], FILTER_VALIDATE_EMAIL)));
        $to = $list($p['to'] ?? []);
        $cc = $list($p['cc'] ?? []);
        $bcc = $list($p['bcc'] ?? []);
        if (! $to && ! $cc && ! $bcc) {
            throw new \DomainException('Ajoutez au moins un destinataire valide.');
        }
        $refs = $orig ? trim(($orig->references ? $orig->references.' ' : '').($orig->message_id ?? '')) : null;
        $html = (string) ($p['html'] ?? '');

        return [
            'from' => ['name' => $a->display_name, 'email' => $a->email],
            'to' => $to, 'cc' => $cc, 'bcc' => $bcc,
            'subject' => mb_substr(trim((string) ($p['subject'] ?? '')), 0, 300) ?: '(sans objet)',
            'html' => '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1b1f24">'.$html.'</body></html>',
            'text' => trim(html_entity_decode(strip_tags(preg_replace(['#<br\s*/?>#i', '#</(p|div|li|tr|h\d)>#i', '#<(style|script)[^>]*>.*?</\1>#is'], ["\n", "\n", ''], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'attachments' => $atts,
            'mode' => in_array($p['mode'] ?? 'new', ['new', 'reply', 'reply_all', 'forward'], true) ? ($p['mode'] ?? 'new') : 'new',
            'original' => $orig,
            'in_reply_to' => in_array($p['mode'] ?? '', ['reply', 'reply_all'], true) ? $orig?->message_id : null,
            'references' => $refs ? mb_substr($refs, -3000) : null,
            'read_receipt' => (bool) ($p['read_receipt'] ?? false),
            'importance' => in_array($p['importance'] ?? 'normal', ['low', 'normal', 'high'], true) ? ($p['importance'] ?? 'normal') : 'normal',
            'message_id' => '<'.bin2hex(random_bytes(12)).'@'.(substr(strrchr($a->email, '@'), 1) ?: 'yomara').'>',
        ];
    }

    public static function email(array $mail): Email
    {
        $addr = fn (array $r) => new Address($r['email'], (string) ($r['name'] ?? ''));
        $e = (new Email)->from($addr($mail['from']))->subject($mail['subject'])->html($mail['html'])->text($mail['text'] ?: ' ');
        foreach (['to', 'cc', 'bcc'] as $k) {
            if ($mail[$k]) {
                $e->{$k}(...array_map($addr, $mail[$k]));
            }
        }
        foreach ($mail['attachments'] as $x) {
            $e->attach($x['content'], $x['name'], $x['mime']);
        }
        $h = $e->getHeaders();
        $h->addIdHeader('Message-ID', trim($mail['message_id'], '<>'));
        if ($mail['in_reply_to']) {
            $h->addIdHeader('In-Reply-To', trim($mail['in_reply_to'], '<>'));
        }
        if ($mail['references']) {
            preg_match_all('/<([^>]+)>/', $mail['references'], $m);
            if ($m[1]) {
                $h->addIdHeader('References', $m[1]);
            }
        }
        if ($mail['read_receipt']) {
            $h->addMailboxHeader('Disposition-Notification-To', $mail['from']['email']);
        }
        if ($mail['importance'] === 'high') {
            $e->priority(Email::PRIORITY_HIGH);
        } elseif ($mail['importance'] === 'low') {
            $e->priority(Email::PRIORITY_LOW);
        }
        $h->addTextHeader('X-Mailer', 'Yomara ERP');

        return $e;
    }
}
