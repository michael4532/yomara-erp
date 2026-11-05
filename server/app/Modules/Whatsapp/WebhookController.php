<?php

namespace App\Modules\Whatsapp;

use App\Http\Controllers\Controller;
use App\Messaging\Phone;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Réception WhatsApp : une adresse secrète par société, qui comprend l'API Cloud de Meta (vérification « hub.challenge »,
 * signature X-Hub-Signature-256), UltraMsg et Green API.
 */
class WebhookController extends Controller
{
    public function __construct(protected Inbox $inbox) {}

    protected function company(string $token): array
    {
        $s = DB::table('wa_settings')->where('webhook_token', $token)->first();
        abort_unless($s, 404);
        $c = Company::find($s->company_id);
        abort_unless($c && $c->hasModule('whatsapp'), 404);
        app()->instance('tenant', $c);

        return [$c, $s];
    }

    public function verify(Request $r, string $token)
    {
        [, $s] = $this->company($token);
        if ($r->query('hub_mode') === 'subscribe' || $r->query('hub.mode') === 'subscribe') {
            $given = $r->query('hub_verify_token', $r->query('hub.verify_token'));
            abort_unless(hash_equals((string) $s->verify_token, (string) $given), 403);

            return response((string) $r->query('hub_challenge', $r->query('hub.challenge')), 200)->header('Content-Type', 'text/plain');
        }

        return response('ok');
    }

    public function receive(Request $r, string $token)
    {
        [$c] = $this->company($token);
        $p = $r->json()->all() ?: $r->all();
        $provider = $this->inbox->channel($c)['provider'];
        if (($p['object'] ?? null) === 'whatsapp_business_account') {
            $cfg = $this->inbox->channel($c)['config'];
            if (! empty($cfg['app_secret'])) {
                $sig = (string) $r->header('X-Hub-Signature-256');
                abort_unless($sig && hash_equals('sha256='.hash_hmac('sha256', $r->getContent(), $cfg['app_secret']), $sig), 403, 'Signature invalide.');
            }
            $this->meta($c, $p, $cfg);
        } elseif (isset($p['event_type'], $p['data']) && $provider === 'ultramsg') {
            $this->ultramsg($c, $p);
        } elseif (isset($p['typeWebhook']) && $provider === 'greenapi') {
            $this->greenapi($c, $p);
        }

        return response()->json(['ok' => true]);
    }

    protected function meta(Company $c, array $p, array $cfg): void
    {
        foreach ($p['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $ch) {
                $v = $ch['value'] ?? [];
                $names = [];
                foreach ($v['contacts'] ?? [] as $ct) {
                    $names[$ct['wa_id'] ?? ''] = $ct['profile']['name'] ?? null;
                }
                foreach ($v['messages'] ?? [] as $m) {
                    $type = $m['type'] ?? 'text';
                    $media = null;
                    $body = null;
                    switch ($type) {
                        case 'text':
                            $body = $m['text']['body'] ?? '';
                            break;
                        case 'image': case 'document': case 'audio': case 'video': case 'sticker':
                            $obj = $m[$type];
                            $body = $obj['caption'] ?? null;
                            $media = function () use ($obj, $cfg, $type) {
                                $info = Http::withToken($cfg['access_token'] ?? '')->acceptJson()->timeout(20)->get('https://graph.facebook.com/'.Inbox::META_VERSION.'/'.$obj['id'])->throw()->json();
                                $bytes = Http::withToken($cfg['access_token'] ?? '')->withOptions(\App\Support\UrlGuard::pinned((string) $info['url']))->timeout(60)->get($info['url'])->throw()->body();

                                return [$bytes, $info['mime_type'] ?? ($obj['mime_type'] ?? null), $obj['filename'] ?? ($type.'.'.(explode('/', explode(';', (string) ($obj['mime_type'] ?? 'bin/bin'))[0])[1] ?? 'bin'))];
                            };
                            break;
                        case 'location':
                            $l = $m['location'];
                            $body = trim(($l['name'] ?? '').' '.($l['address'] ?? '')).' https://maps.google.com/?q='.($l['latitude'] ?? '').','.($l['longitude'] ?? '');
                            break;
                        case 'button':
                            $type = 'text';
                            $body = $m['button']['text'] ?? '';
                            break;
                        case 'interactive':
                            $type = 'text';
                            $body = $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '';
                            break;
                        case 'contacts':
                            $body = collect($m['contacts'] ?? [])->map(fn ($x) => ($x['name']['formatted_name'] ?? '').' '.($x['phones'][0]['phone'] ?? ''))->implode("\n");
                            break;
                        default:
                            $type = 'other';
                            $body = 'Message de type « '.($m['type'] ?? '?').' » (non affichable)';
                    }
                    $this->inbox->receive($c, ['phone' => preg_replace('/\D/', '', (string) $m['from']), 'name' => $names[$m['from']] ?? null, 'id' => $m['id'] ?? null,
                        'type' => $type, 'body' => $body, 'media' => $media, 'at' => isset($m['timestamp']) ? Carbon::createFromTimestamp((int) $m['timestamp']) : now()]);
                }
                foreach ($v['statuses'] ?? [] as $st) {
                    $this->inbox->status($c, (string) $st['id'], (string) $st['status'], $st['errors'][0]['title'] ?? ($st['errors'][0]['message'] ?? null));
                }
            }
        }
    }

    protected function ultramsg(Company $c, array $p): void
    {
        $d = $p['data'];
        if (in_array($p['event_type'], ['message_ack'], true)) {
            $this->inbox->status($c, (string) ($d['id'] ?? ''), match ($d['ack'] ?? '') { 'delivered' => 'delivered', 'read', 'played' => 'read', default => 'sent' });

            return;
        }
        if ($p['event_type'] !== 'message_received' || ! empty($d['fromMe'])) {
            return;
        }
        $type = match ($d['type'] ?? 'chat') { 'chat' => 'text', 'image' => 'image', 'document' => 'document', 'ptt', 'audio' => 'audio', 'video' => 'video', 'location' => 'location', default => 'other' };
        $media = ! empty($d['media']) ? function () use ($d) {
            $res = Http::withOptions(\App\Support\UrlGuard::pinned((string) $d['media']))->timeout(60)->get($d['media'])->throw();

            return [$res->body(), $res->header('Content-Type') ?: null, $d['filename'] ?? basename(parse_url($d['media'], PHP_URL_PATH) ?: 'fichier')];
        } : null;
        $this->inbox->receive($c, ['phone' => Phone::digits(explode('@', (string) $d['from'])[0]) ?? preg_replace('/\D/', '', (string) $d['from']), 'name' => $d['pushname'] ?? null, 'id' => $d['id'] ?? null,
            'type' => $type, 'body' => $type === 'text' ? ($d['body'] ?? '') : ($d['caption'] ?? null), 'media' => $media]);
    }

    protected function greenapi(Company $c, array $p): void
    {
        if ($p['typeWebhook'] === 'outgoingMessageStatus') {
            $this->inbox->status($c, (string) ($p['idMessage'] ?? ''), match ($p['status'] ?? '') { 'delivered' => 'delivered', 'read' => 'read', 'failed', 'noAccount' => 'failed', default => 'sent' });

            return;
        }
        if ($p['typeWebhook'] !== 'incomingMessageReceived') {
            return;
        }
        $md = $p['messageData'] ?? [];
        $tm = $md['typeMessage'] ?? 'textMessage';
        $type = match ($tm) { 'textMessage', 'extendedTextMessage' => 'text', 'imageMessage' => 'image', 'documentMessage' => 'document', 'audioMessage' => 'audio', 'videoMessage' => 'video', 'locationMessage' => 'location', default => 'other' };
        $body = $md['textMessageData']['textMessage'] ?? $md['extendedTextMessageData']['text'] ?? $md['fileMessageData']['caption'] ?? null;
        $file = $md['fileMessageData'] ?? null;
        $media = $file && ! empty($file['downloadUrl']) ? function () use ($file) {
            $res = Http::withOptions(\App\Support\UrlGuard::pinned((string) $file['downloadUrl']))->timeout(60)->get($file['downloadUrl'])->throw();

            return [$res->body(), $file['mimeType'] ?? $res->header('Content-Type'), $file['fileName'] ?? 'fichier'];
        } : null;
        $sender = $p['senderData'] ?? [];
        $this->inbox->receive($c, ['phone' => preg_replace('/\D/', '', explode('@', (string) ($sender['chatId'] ?? ''))[0]), 'name' => $sender['senderName'] ?? ($sender['chatName'] ?? null),
            'id' => $p['idMessage'] ?? null, 'type' => $type, 'body' => $body, 'media' => $media, 'at' => isset($p['timestamp']) ? Carbon::createFromTimestamp((int) $p['timestamp']) : now()]);
    }

    /** Fichier envoyé, récupéré par le fournisseur WhatsApp (adresse au nom imprévisible). */
    public function file(string $name)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}\.[a-z0-9]{1,8}$/', $name), 404);
        $hit = null;
        foreach (Storage::disk('local')->directories('whatsapp') as $dir) {
            if (Storage::disk('local')->exists($dir.'/'.$name)) {
                $hit = $dir.'/'.$name;
                break;
            }
        }
        abort_unless($hit, 404);

        $full = Storage::disk('local')->path($hit);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($full) ?: 'application/octet-stream';

        return response()->file($full, \App\Support\SafeDownload::headers($mime, $name, true) + ['Cache-Control' => 'private, max-age=86400']);
    }
}
