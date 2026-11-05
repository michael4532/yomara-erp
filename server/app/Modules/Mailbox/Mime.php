<?php

namespace App\Modules\Mailbox;

use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;

/** Lecture des messages bruts (RFC 822 / MIME) et nettoyage du HTML reçu. */
class Mime
{
    public static function parse(string $raw): array
    {
        $m = (new MailMimeParser)->parse($raw, false);
        $addr = function (string $h) use ($m): array {
            $hd = $m->getHeader($h);
            if (! $hd || ! method_exists($hd, 'getAddresses')) {
                return [];
            }
            $out = [];
            foreach ($hd->getAddresses() as $a) {
                if ($a->getEmail()) {
                    $out[] = ['name' => self::clean((string) $a->getName()) ?: null, 'email' => mb_strtolower($a->getEmail())];
                }
            }

            return $out;
        };
        $from = $addr(HeaderConsts::FROM)[0] ?? null;
        $date = null;
        $dh = $m->getHeader(HeaderConsts::DATE);
        if ($dh && method_exists($dh, 'getDateTime') && $dh->getDateTime()) {
            $date = \Carbon\Carbon::instance($dh->getDateTime())->setTimezone(config('app.timezone'));
        }
        $html = $m->getHtmlContent();
        $text = $m->getTextContent();
        $atts = [];
        foreach ($m->getAllAttachmentParts() as $i => $p) {
            if ($p === $m->getHtmlPart() || $p === $m->getTextPart()) {
                continue;
            }
            $cid = trim((string) $p->getContentId(), '<> ');
            $name = $p->getFilename() ?: ($cid ? 'image-'.$i : 'piece-jointe-'.($i + 1));
            $atts[] = ['id' => (string) $i, 'name' => self::clean($name), 'mime' => strtolower((string) $p->getContentType()), 'size' => strlen((string) $p->getContent()),
                'cid' => $cid ?: null, 'inline' => $cid !== '' && strtolower((string) $p->getContentDisposition()) !== 'attachment'];
        }
        $refs = trim((string) $m->getHeaderValue('References'));

        return [
            'message_id' => self::mid($m->getHeaderValue(HeaderConsts::MESSAGE_ID)),
            'in_reply_to' => self::mid($m->getHeaderValue(HeaderConsts::IN_REPLY_TO)),
            'references' => $refs ?: null,
            'subject' => self::clean((string) $m->getSubject()),
            'from_name' => $from['name'] ?? null,
            'from_email' => $from['email'] ?? null,
            'to' => $addr(HeaderConsts::TO),
            'cc' => $addr(HeaderConsts::CC),
            'bcc' => $addr(HeaderConsts::BCC),
            'reply_to' => $addr(HeaderConsts::REPLY_TO)[0]['email'] ?? null,
            'date' => $date,
            'html' => $html,
            'text' => $text,
            'attachments' => $atts,
        ];
    }

    /** @return array{name: string, mime: string, content: string}|null */
    public static function attachment(string $raw, string $id): ?array
    {
        $m = (new MailMimeParser)->parse($raw, false);
        $p = $m->getAllAttachmentParts()[(int) $id] ?? null;

        return $p ? ['name' => $p->getFilename() ?: 'piece-jointe', 'mime' => strtolower((string) $p->getContentType()), 'content' => (string) $p->getContent()] : null;
    }

    public static function mid(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr('<'.trim($v, '<> ').'>', 0, 255);
    }

    /** Clé de conversation : sujet sans préfixes RE/TR/FW + interlocuteurs normalisés. */
    public static function threadKey(?string $subject, ?string $references, ?string $inReplyTo, ?string $messageId): string
    {
        $first = null;
        if ($references && preg_match('/<[^>]+>/', $references, $m)) {
            $first = $m[0];
        }
        $root = $first ?: $inReplyTo ?: $messageId;
        if ($root) {
            return sha1('r'.$root);
        }
        $s = mb_strtolower(trim((string) $subject));
        $s = preg_replace('/^((re|tr|fw|fwd|réf|ref|aw|wg|sv)\s*(\[\d+\])?\s*:\s*)+/iu', '', $s);

        return sha1('s'.trim($s));
    }

    public static function snippet(?string $text, ?string $html): string
    {
        $t = $text ?: html_entity_decode(strip_tags(preg_replace('#<(style|script|head)[^>]*>.*?</\1>#is', ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/^>.*$/m', '', (string) $t);

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $t)), 0, 280);
    }

    public static function clean(string $s): string
    {
        $s = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');

        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s));
    }

    /**
     * HTML reçu → HTML sûr : pas de script, de formulaire, d'iframe ni d'attribut on*, liens ouverts dans un nouvel onglet,
     * images cid: remplacées par l'adresse de la pièce jointe.
     */
    public static function sanitize(?string $html, array $cidUrls = []): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        $html = self::clean($html);
        if (mb_strlen($html) > 2_000_000) {
            $html = mb_substr($html, 0, 2_000_000);
        }
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="ymb-root">'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT);
        libxml_clear_errors();
        $xp = new \DOMXPath($doc);
        foreach ($xp->query('//script|//iframe|//frame|//frameset|//object|//embed|//applet|//form|//input|//button|//textarea|//select|//meta|//link|//base|//title|//svg//script') as $n) {
            $n->parentNode?->removeChild($n);
        }
        foreach ($xp->query('//*') as $el) {
            /** @var \DOMElement $el */
            $remove = [];
            foreach ($el->attributes as $a) {
                $name = strtolower($a->name);
                $val = trim($a->value);
                if (str_starts_with($name, 'on') || in_array($name, ['formaction', 'srcdoc', 'ping'], true)) {
                    $remove[] = $a->name;
                } elseif (in_array($name, ['href', 'src', 'background', 'action', 'xlink:href'], true) && preg_match('/^\s*(javascript|vbscript|data:text\/html)/i', $val)) {
                    $remove[] = $a->name;
                } elseif ($name === 'style' && preg_match('/expression\s*\(|javascript:|behavior\s*:/i', $val)) {
                    $remove[] = $a->name;
                }
            }
            foreach ($remove as $r) {
                $el->removeAttribute($r);
            }
            $tag = strtolower($el->tagName);
            if ($tag === 'a' && $el->hasAttribute('href')) {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer');
            }
            if ($tag === 'img' && preg_match('/^cid:(.+)$/i', $el->getAttribute('src'), $m)) {
                $cid = trim(rawurldecode($m[1]), '<> ');
                if (isset($cidUrls[$cid])) {
                    $el->setAttribute('src', $cidUrls[$cid]);
                }
            }
        }
        $root = $doc->getElementById('ymb-root');
        $out = '';
        foreach ($root?->childNodes ?? [] as $c) {
            $out .= $doc->saveHTML($c);
        }

        return $out;
    }

    public static function textToHtml(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $h = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $h = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $h);

        return '<div style="white-space:pre-wrap;font-family:inherit">'.$h.'</div>';
    }
}
