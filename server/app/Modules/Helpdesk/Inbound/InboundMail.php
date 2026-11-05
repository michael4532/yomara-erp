<?php

namespace App\Modules\Helpdesk\Inbound;

/** E-mail reçu, quelle que soit sa source (IMAP, webhook Mailgun / SendGrid / Postmark / Brevo, MIME brut). */
class InboundMail
{
    /** @param array<int, array{name: string, mime: ?string, data: string}> $attachments */
    public function __construct(
        public string $fromEmail,
        public ?string $fromName = null,
        public string $subject = '',
        public string $text = '',
        public ?string $html = null,
        public ?string $messageId = null,
        public array $references = [],
        public array $attachments = [],
        public array $headers = [],
    ) {
        $this->fromEmail = mb_strtolower(trim($fromEmail));
    }

    public function body(): string
    {
        $t = trim($this->text);
        if ($t === '' && $this->html) {
            $h = preg_replace(['/<(br|\/p|\/div|\/li|\/tr)[^>]*>/i', '/<style.*?<\/style>/is', '/<script.*?<\/script>/is'], ["\n", '', ''], $this->html);
            $t = trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $t));
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return is_array($v) ? implode(' ', $v) : (string) $v;
            }
        }

        return null;
    }

    /** Réponse automatique, liste de diffusion ou robot : à ignorer (évite les boucles). */
    public function isAutomatic(): bool
    {
        $auto = strtolower((string) $this->header('Auto-Submitted'));
        $prec = strtolower((string) $this->header('Precedence'));

        return ($auto !== '' && $auto !== 'no') || in_array($prec, ['bulk', 'junk', 'list', 'auto_reply'], true)
            || $this->header('X-Autoreply') || $this->header('X-Autorespond') || $this->header('List-Id')
            || preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply|bounce[s]?)@/i', $this->fromEmail)
            || preg_match('/^(réponse automatique|automatic reply|auto(matic)?[- ]?reply|out of office|absence)/iu', trim($this->subject));
    }

    public static function fromRaw(string $raw): self
    {
        $msg = (new \ZBateson\MailMimeParser\MailMimeParser)->parse($raw, false);
        $from = $msg->getHeader('From');
        $email = $from && method_exists($from, 'getEmail') ? (string) $from->getEmail() : '';
        $name = $from && method_exists($from, 'getPersonName') ? ($from->getPersonName() ?: null) : null;
        $refs = trim(($msg->getHeaderValue('In-Reply-To') ?? '').' '.($msg->getHeaderValue('References') ?? ''));
        $headers = [];
        foreach (['Auto-Submitted', 'Precedence', 'X-Autoreply', 'X-Autorespond', 'List-Id'] as $h) {
            if (($v = $msg->getHeaderValue($h)) !== null) {
                $headers[$h] = $v;
            }
        }
        $refsRaw = trim(($msg->getHeader('In-Reply-To')?->getRawValue() ?? '').' '.($msg->getHeader('References')?->getRawValue() ?? ''));
        preg_match_all('/<([^>]+)>/', $refsRaw, $m);
        $atts = [];
        foreach ($msg->getAllAttachmentParts() as $p) {
            $content = $p->getContent();
            if ($content === null || $content === '') {
                continue;
            }
            $atts[] = ['name' => $p->getFilename() ?: 'piece-jointe', 'mime' => $p->getContentType(), 'data' => $content];
        }

        return new self($email, $name, (string) $msg->getHeaderValue('Subject'), (string) $msg->getTextContent(), $msg->getHtmlContent(),
            trim((string) $msg->getHeaderValue('Message-ID'), '<> ') ?: null, $m[1] ?: array_filter(preg_split('/\s+/', $refs)), $atts, $headers);
    }
}
