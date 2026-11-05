<?php

namespace App\Modules\Helpdesk\Inbound;

use App\Models\Company;
use App\Modules\Helpdesk\HelpdeskService;
use App\Modules\Helpdesk\Models\Attachment;
use App\Modules\Helpdesk\Models\Message;
use App\Modules\Helpdesk\Models\Ticket;
use App\Services\EntityWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Transforme les e-mails et messages WhatsApp reçus en tickets (ou en réponses aux tickets existants). */
class InboundService
{
    public const MAX_FILE = 15 * 1048576;

    public const MAX_PER_HOUR = 20;

    public const BLOCKED_EXT = ['exe', 'bat', 'cmd', 'com', 'scr', 'js', 'vbs', 'msi', 'ps1', 'jar', 'sh', 'php', 'phtml'];

    public const PUBLIC_DOMAINS = ['gmail.com', 'yahoo.com', 'yahoo.fr', 'hotmail.com', 'hotmail.fr', 'outlook.com', 'outlook.fr', 'live.com', 'live.fr', 'icloud.com', 'aol.com', 'orange.ci', 'gmx.com', 'proton.me', 'protonmail.com'];

    public function __construct(private HelpdeskService $hd) {}

    // ------------------------------------------------------------------ e-mail

    /** @return array{status: string, ticket: ?Ticket, reason?: string} */
    public function handleMail(Company $c, InboundMail $m, ?Mailbox $box = null): array
    {
        app()->instance('tenant', $c);
        if (! $m->fromEmail || ! filter_var($m->fromEmail, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'ignored', 'ticket' => null, 'reason' => 'Expéditeur invalide'];
        }
        if ($m->isAutomatic()) {
            return ['status' => 'ignored', 'ticket' => null, 'reason' => 'Message automatique'];
        }
        $own = array_filter(array_map('strtolower', [$box?->address, $box?->imap_username, $c->email]));
        if (in_array($m->fromEmail, $own, true)) {
            return ['status' => 'ignored', 'ticket' => null, 'reason' => 'Envoyé par notre propre adresse'];
        }
        if ($m->messageId && (Message::where('external_id', $m->messageId)->exists() || Ticket::where('external_id', $m->messageId)->exists())) {
            return ['status' => 'duplicate', 'ticket' => null];
        }
        $body = self::stripQuoted($m->body());
        $ticket = $this->findThread($m);
        if ($ticket && $ticket->status === 'closed' && $ticket->closed_at && $ticket->closed_at->lt(now()->subDays(30))) {
            $body = 'Suite de la demande '.$ticket->number." :\n\n".$body;
            $ticket = null;
        }
        if ($ticket) {
            $msg = $this->hd->customerMessage($c, $ticket, $body !== '' ? $body : '(pièce jointe)', $m->fromName ?: $ticket->contact_name, 'email');
            $msg->forceFill(['external_id' => $m->messageId])->save();
            $this->storeFiles($ticket, $m->attachments, $msg->id);
            $box?->increment('received');

            return ['status' => 'appended', 'ticket' => $ticket->fresh()];
        }
        if (Ticket::where('contact_email', $m->fromEmail)->where('created_at', '>=', now()->subHour())->count() >= self::MAX_PER_HOUR) {
            return ['status' => 'ignored', 'ticket' => null, 'reason' => 'Trop de demandes de cet expéditeur'];
        }
        $partner = $this->hd->partnerFor($m->fromName ?: Str::before($m->fromEmail, '@'), $m->fromEmail, null);
        $t = $this->createTicket($c, [
            'partner_id' => $partner->id, 'contact_name' => $m->fromName ?: $partner->name, 'contact_email' => $m->fromEmail, 'contact_phone' => $partner->phone,
            'subject' => self::cleanSubject($m->subject) ?: Str::limit($body, 80, '…') ?: 'Demande par e-mail', 'description' => $body !== '' ? $body : '(voir la pièce jointe)', 'channel' => 'email',
        ], $m->messageId);
        $this->storeFiles($t, $m->attachments, null);
        $box?->increment('received');

        return ['status' => 'created', 'ticket' => $t];
    }

    /** Réponse à un ticket : en-tête References posé par nos e-mails, sinon n° [SUP-…] dans l'objet avec un expéditeur cohérent. */
    public function findThread(InboundMail $m): ?Ticket
    {
        foreach ($m->references as $ref) {
            if (preg_match('/^ticket-([0-9a-f-]{36})@/i', $ref, $x) && ($t = Ticket::find($x[1]))) {
                return $t;
            }
        }
        if (preg_match('/\[(SUP-\d{4}-\d{3,6})\]/', $m->subject, $x) && ($t = Ticket::where('number', $x[1])->first())) {
            $known = array_filter(array_map('strtolower', [$t->contact_email, $t->partner_id ? \App\Modules\Core\Models\Partner::find($t->partner_id)?->email : null]));
            if (in_array($m->fromEmail, $known, true)) {
                return $t;
            }
            $domain = Str::after($m->fromEmail, '@');
            foreach ($known as $k) {
                if (Str::after($k, '@') === $domain && ! in_array($domain, self::PUBLIC_DOMAINS, true)) {
                    return $t;
                }
            }
        }

        return null;
    }

    public static function cleanSubject(string $s): string
    {
        $s = preg_replace('/\s*\[SUP-\d{4}-\d+\]\s*/', ' ', $s);
        do {
            $before = $s;
            $s = preg_replace('/^\s*(re|ré|tr|fw|fwd|aw|réf)\s*:\s*/iu', '', $s);
        } while ($s !== $before);

        return Str::limit(trim($s), 200, '');
    }

    /** Retire l'historique cité sous la réponse (Gmail, Outlook, Apple Mail, WhatsApp Web…). */
    public static function stripQuoted(string $text): string
    {
        $lines = preg_split('/\R/', $text);
        $out = [];
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $l = $lines[$i];
            $two = $l.' '.($lines[$i + 1] ?? '');
            if (preg_match('/^\s*##-.*(répondez|reply)/iu', $l)
                || preg_match('/^\s*(Le|On)\s.{4,200}(a écrit|wrote)\s*:\s*$/u', $l)
                || (preg_match('/^\s*(Le|On)\s/u', $l) && preg_match('/(a écrit|wrote)\s*:\s*$/u', $two) && ! preg_match('/(a écrit|wrote)/u', $l))
                || preg_match('/^\s*-{2,}\s*(Original Message|Message d\'origine|Message original|Forwarded message|Message transféré)/iu', $l)
                || preg_match('/^\s*_{8,}\s*$/', $l)
                || preg_match('/^\s*(De|From)\s*:\s*.+(@|<)/u', $l) && $i > 0 && trim($lines[$i - 1] ?? '') === '') {
                break;
            }
            if (preg_match('/^\s*>/', $l)) {
                continue;
            }
            $out[] = $l;
        }
        $t = trim(implode("\n", $out));
        $t = preg_replace('/\n*(Envoyé (de|depuis) mon \w+.*|Sent from my \w+.*|Obtenir Outlook pour .*)$/iu', '', $t);
        $t = trim($t);

        return $t !== '' ? $t : trim($text);
    }

    protected function createTicket(Company $c, array $data, ?string $externalId): Ticket
    {
        $id = (string) Str::uuid();
        Ticket::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(Ticket::class, $id, array_filter($data, fn ($v) => $v !== null && $v !== ''));
        } finally {
            Ticket::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \RuntimeException('Ticket refusé : '.($res['message'] ?? ''));
        }
        $t = Ticket::find($id);
        if ($externalId) {
            $t->forceFill(['external_id' => $externalId])->save();
        }

        return $t;
    }

    /** @param array<int, array{name: string, mime: ?string, data: string}> $files */
    public function storeFiles(Ticket $t, array $files, ?string $messageId, bool $public = true, ?string $by = null): array
    {
        $out = [];
        foreach ($files as $f) {
            $name = Str::limit(preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/', '_', $f['name'] ?: 'piece-jointe'), 180, '');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $size = strlen($f['data']);
            if ($size === 0 || $size > self::MAX_FILE || in_array($ext, self::BLOCKED_EXT, true)) {
                continue;
            }
            $id = (string) Str::uuid();
            $path = 'helpdesk/'.$t->company_id.'/'.$t->id.'/'.$id.($ext ? '.'.$ext : '');
            Storage::disk('local')->put($path, $f['data']);
            $a = new Attachment;
            $a->forceFill(['id' => $id, 'ticket_id' => $t->id, 'message_id' => $messageId, 'name' => $name, 'mime' => $f['mime'] ?? null, 'size' => $size, 'path' => $path, 'public' => $public, 'uploaded_by' => $by]);
            $a->save();
            $out[] = $a;
        }

        return $out;
    }

    // ------------------------------------------------------------------ webhooks e-mail (Mailgun, SendGrid, Postmark, Brevo, MIME brut)

    /** @return InboundMail[] */
    public static function mailsFromRequest(Request $r): array
    {
        $raw = $r->input('email') ?? $r->input('body-mime') ?? (str_contains((string) $r->header('Content-Type'), 'message/rfc822') ? $r->getContent() : null);
        if (is_string($raw) && $raw !== '') {
            return [InboundMail::fromRaw($raw)];
        }
        if ($r->has('items') && is_array($r->input('items'))) { // Brevo
            return array_map(fn ($i) => new InboundMail(
                $i['From']['Address'] ?? $i['Sender']['Address'] ?? '', $i['From']['Name'] ?? null, (string) ($i['Subject'] ?? ''), (string) ($i['ExtractedMarkdownMessage'] ?? $i['RawTextBody'] ?? ''), $i['RawHtmlBody'] ?? null,
                trim((string) ($i['MessageId'] ?? ''), '<>') ?: null, array_map(fn ($x) => trim($x, '<>'), array_filter([$i['InReplyTo'] ?? null, ...(array) ($i['References'] ?? [])])),
                [], (array) ($i['Headers'] ?? [])), $r->input('items'));
        }
        if ($r->has('From') || $r->has('FromFull')) { // Postmark
            $headers = [];
            foreach ((array) $r->input('Headers', []) as $h) {
                $headers[$h['Name'] ?? ''] = $h['Value'] ?? '';
            }
            preg_match_all('/<([^>]+)>/', ($headers['In-Reply-To'] ?? '').' '.($headers['References'] ?? ''), $refs);

            return [new InboundMail((string) ($r->input('FromFull.Email') ?? self::emailOf((string) $r->input('From'))), $r->input('FromFull.Name') ?: $r->input('FromName'), (string) $r->input('Subject', ''),
                (string) ($r->input('StrippedTextReply') ?: $r->input('TextBody', '')), $r->input('HtmlBody'), trim((string) $r->input('MessageID', ''), '<>') ?: null, $refs[1],
                array_map(fn ($a) => ['name' => $a['Name'] ?? 'piece-jointe', 'mime' => $a['ContentType'] ?? null, 'data' => base64_decode($a['Content'] ?? '')], (array) $r->input('Attachments', [])), $headers)];
        }
        // Mailgun (sender, body-plain…) ou SendGrid Inbound Parse (from, text, html, headers)
        $from = (string) ($r->input('sender') ?: $r->input('from', ''));
        $headers = [];
        $rawHeaders = (string) ($r->input('message-headers') ?: $r->input('headers', ''));
        if (str_starts_with(trim($rawHeaders), '[')) {
            foreach ((array) json_decode($rawHeaders, true) as $h) {
                $headers[$h[0] ?? ''] = $h[1] ?? '';
            }
        } else {
            foreach (preg_split('/\R/', $rawHeaders) as $line) {
                if (str_contains($line, ':')) {
                    $headers[trim(Str::before($line, ':'))] = trim(Str::after($line, ':'));
                }
            }
        }
        preg_match_all('/<([^>]+)>/', ($r->input('In-Reply-To') ?? $headers['In-Reply-To'] ?? '').' '.($r->input('References') ?? $headers['References'] ?? ''), $refs);
        $files = [];
        foreach ($r->allFiles() as $f) {
            foreach (is_array($f) ? $f : [$f] as $file) {
                $files[] = ['name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType(), 'data' => (string) file_get_contents($file->getRealPath())];
            }
        }
        $fromHeader = (string) ($r->input('from') ?: $r->input('From', $from));

        return [new InboundMail(self::emailOf($from) ?: self::emailOf($fromHeader), self::nameOf($fromHeader), (string) ($r->input('subject') ?? $r->input('Subject', '')),
            (string) ($r->input('stripped-text') ?: ($r->input('body-plain') ?: $r->input('text', ''))), $r->input('body-html') ?? $r->input('html'),
            trim((string) ($r->input('Message-Id') ?? $headers['Message-ID'] ?? $headers['Message-Id'] ?? ''), '<> ') ?: null, $refs[1], $files, $headers)];
    }

    public static function emailOf(string $s): string
    {
        return preg_match('/<([^>]+@[^>]+)>/', $s, $m) ? trim($m[1]) : (filter_var(trim($s), FILTER_VALIDATE_EMAIL) ? trim($s) : (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $s, $m) ? $m[0] : ''));
    }

    public static function nameOf(string $s): ?string
    {
        $n = trim(preg_replace('/<[^>]*>/', '', $s), " \"'");

        return $n !== '' && ! str_contains($n, '@') ? $n : null;
    }

    // ------------------------------------------------------------------ relève IMAP

    public function fetchMailbox(Company $c, Mailbox $box, int $limit = 30): array
    {
        $out = ['created' => 0, 'appended' => 0, 'ignored' => 0];
        $imap = new ImapClient($box->imap_host, $box->imap_port, $box->imap_encryption);
        try {
            $imap->connect((string) $box->imap_username, (string) $box->password());
            $imap->select($box->imap_folder ?: 'INBOX');
            $uids = $imap->uidsAfter($box->last_uid);
            if ($box->last_uid === 0 && count($uids) > $limit) {
                $uids = array_slice($uids, -5); // première relève : seulement les derniers messages
                $box->last_uid = $uids[0] - 1;
            }
            foreach (array_slice($uids, 0, $limit) as $uid) {
                $raw = $imap->fetchRaw($uid);
                if ($raw) {
                    try {
                        $res = $this->handleMail($c, InboundMail::fromRaw($raw), $box);
                        $out[$res['status'] === 'duplicate' ? 'ignored' : $res['status']] = ($out[$res['status'] === 'duplicate' ? 'ignored' : $res['status']] ?? 0) + 1;
                    } catch (\Throwable $e) {
                        report($e);
                        $out['ignored']++;
                    }
                }
                $imap->markSeen($uid);
                $box->last_uid = $uid;
            }
            $box->forceFill(['last_check_at' => now(), 'last_error' => null, 'last_uid' => $box->last_uid])->save();
        } catch (\Throwable $e) {
            $box->forceFill(['last_check_at' => now(), 'last_error' => Str::limit($e->getMessage(), 250)])->save();
            $out['error'] = $e->getMessage();
        } finally {
            $imap->logout();
        }

        return $out;
    }

    // ------------------------------------------------------------------ WhatsApp

    /**
     * Messages reçus, au même format quel que soit le fournisseur.
     *
     * @return array<int, array{id: string, from: string, name: ?string, text: string, media: ?array}>
     */
    public static function whatsappMessages(array $p): array
    {
        $out = [];
        if (($p['object'] ?? null) === 'whatsapp_business_account') { // API Cloud Meta
            foreach ((array) ($p['entry'] ?? []) as $e) {
                foreach ((array) ($e['changes'] ?? []) as $ch) {
                    $v = $ch['value'] ?? [];
                    $names = [];
                    foreach ((array) ($v['contacts'] ?? []) as $ct) {
                        $names[$ct['wa_id'] ?? ''] = $ct['profile']['name'] ?? null;
                    }
                    foreach ((array) ($v['messages'] ?? []) as $m) {
                        $type = $m['type'] ?? 'text';
                        $media = in_array($type, ['image', 'document', 'audio', 'video'], true) ? ['meta_id' => $m[$type]['id'] ?? null, 'name' => $m[$type]['filename'] ?? ($type.'-'.substr(preg_replace('/\W/', '', (string) ($m['id'] ?? '')), -6)), 'mime' => $m[$type]['mime_type'] ?? null] : null;
                        $out[] = ['id' => (string) ($m['id'] ?? ''), 'from' => (string) ($m['from'] ?? ''), 'name' => $names[$m['from'] ?? ''] ?? null,
                            'text' => (string) ($m['text']['body'] ?? $m[$type]['caption'] ?? ($m['button']['text'] ?? '')), 'media' => $media];
                    }
                }
            }
        } elseif (isset($p['typeWebhook'])) { // Green API
            if ($p['typeWebhook'] === 'incomingMessageReceived') {
                $md = $p['messageData'] ?? [];
                $file = $md['fileMessageData'] ?? null;
                $out[] = ['id' => (string) ($p['idMessage'] ?? ''), 'from' => Str::before((string) ($p['senderData']['chatId'] ?? ''), '@'), 'name' => $p['senderData']['senderName'] ?? null,
                    'text' => (string) ($md['textMessageData']['textMessage'] ?? $md['extendedTextMessageData']['text'] ?? $file['caption'] ?? ''),
                    'media' => $file ? ['url' => $file['downloadUrl'] ?? null, 'name' => $file['fileName'] ?? 'fichier', 'mime' => $file['mimeType'] ?? null] : null];
            }
        } elseif (isset($p['event_type']) || isset($p['data']['from'])) { // UltraMsg
            $d = $p['data'] ?? [];
            if (($p['event_type'] ?? 'message_received') === 'message_received' && empty($d['fromMe'])) {
                $out[] = ['id' => (string) ($d['id'] ?? ''), 'from' => Str::before((string) ($d['from'] ?? ''), '@'), 'name' => $d['pushname'] ?? null, 'text' => (string) ($d['body'] ?? ''),
                    'media' => ! empty($d['media']) ? ['url' => $d['media'], 'name' => $d['filename'] ?? 'fichier', 'mime' => null] : null];
            }
        }

        return array_values(array_filter($out, fn ($m) => $m['from'] !== '' && ! str_contains($m['from'], '-') && ($m['text'] !== '' || $m['media'])));
    }

    /** @return array{status: string, ticket: ?Ticket} */
    public function handleWhatsapp(Company $c, array $wa, ?Mailbox $box = null): array
    {
        app()->instance('tenant', $c);
        $extId = 'wa:'.$wa['id'];
        if ($wa['id'] !== '' && (Message::where('external_id', $extId)->exists() || Ticket::where('external_id', $extId)->exists())) {
            return ['status' => 'duplicate', 'ticket' => null];
        }
        $digits = preg_replace('/\D/', '', $wa['from']);
        $tail = substr($digits, -8);
        $file = $wa['media'] ? $this->downloadMedia($c, $wa['media']) : null;
        // Conversation en cours avec ce numéro (ouverte, ou résolue depuis moins de 7 jours)
        $t = Ticket::whereNotNull('contact_phone')->where(fn ($q) => $q->whereIn('status', ['new', 'open', 'pending'])->orWhere(fn ($q) => $q->where('status', 'resolved')->where('resolved_at', '>=', now()->subDays(7))))
            ->orderByDesc('updated_at')->get()->first(fn ($x) => str_ends_with(preg_replace('/\D/', '', $x->contact_phone), $tail));
        $text = trim($wa['text']) !== '' ? trim($wa['text']) : '(pièce jointe)';
        if ($t) {
            $msg = $this->hd->customerMessage($c, $t, $text, $wa['name'] ?: $t->contact_name, 'whatsapp');
            $msg->forceFill(['external_id' => $extId])->save();
            if ($file) {
                $this->storeFiles($t, [$file], $msg->id);
            }
            $box?->increment('received');

            return ['status' => 'appended', 'ticket' => $t->fresh()];
        }
        if (Ticket::where('contact_phone', 'like', '%'.$tail)->where('created_at', '>=', now()->subHour())->count() >= self::MAX_PER_HOUR) {
            return ['status' => 'ignored', 'ticket' => null];
        }
        $phone = '+'.$digits;
        $partner = $this->hd->partnerFor($wa['name'] ?: $phone, null, $phone);
        $t = $this->createTicket($c, ['partner_id' => $partner->id, 'contact_name' => $wa['name'] ?: $partner->name, 'contact_phone' => $partner->phone ?: $phone, 'contact_email' => $partner->email,
            'subject' => Str::limit(preg_replace('/\s+/', ' ', $text), 80, '…'), 'description' => $text, 'channel' => 'whatsapp'], $extId);
        if ($file) {
            $this->storeFiles($t, [$file], null);
        }
        $box?->increment('received');

        return ['status' => 'created', 'ticket' => $t];
    }

    /** Télécharge le fichier reçu (lien direct, ou média de l'API Meta avec le jeton de la société). */
    protected function downloadMedia(Company $c, array $media): ?array
    {
        try {
            $url = $media['url'] ?? null;
            $token = null;
            if (! $url && ! empty($media['meta_id'])) {
                $cfg = (array) (\App\Models\MessagingChannel::withoutGlobalScopes()->where(['company_id' => $c->id, 'channel' => 'whatsapp'])->first()?->settings ?? []);
                $token = $cfg['access_token'] ?? null;
                $meta = Http::withToken((string) $token)->timeout(20)->get('https://graph.facebook.com/'.($cfg['api_version'] ?? 'v21.0').'/'.$media['meta_id']);
                $url = $meta->json('url');
                $media['mime'] ??= $meta->json('mime_type');
            }
            if (! $url || ! preg_match('#^https://#', $url)) {
                return null;
            }
            $res = ($token ? Http::withToken($token) : Http::asJson())->withOptions(\App\Support\UrlGuard::pinned((string) $url))->timeout(30)->get($url);
            if (! $res->successful() || strlen($res->body()) > self::MAX_FILE) {
                return null;
            }
            $name = $media['name'] ?? 'fichier';
            if (! pathinfo($name, PATHINFO_EXTENSION) && ($mime = $media['mime'] ?? $res->header('Content-Type'))) {
                $name .= '.'.(['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf', 'audio/ogg' => 'ogg', 'video/mp4' => 'mp4'][explode(';', $mime)[0]] ?? 'bin');
            }

            return ['name' => $name, 'mime' => $media['mime'] ?? $res->header('Content-Type'), 'data' => $res->body()];
        } catch (\Throwable) {
            return null;
        }
    }
}
