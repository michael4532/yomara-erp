<?php

namespace App\Modules\Mailbox\Drivers;

use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Folder;
use App\Modules\Mailbox\Models\Message;
use App\Modules\Mailbox\OAuth;
use App\Modules\Mailbox\Protocol\MailProtocolException;
use App\Modules\Mailbox\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft 365 / Outlook.com / Exchange Online par Microsoft Graph : dossiers, synchronisation différentielle (delta),
 * drapeaux, déplacement, pièces jointes, envoi depuis la boîte (réponse dans la conversation), boîtes partagées.
 */
class GraphDriver implements Driver
{
    public const BASE = 'https://graph.microsoft.com/v1.0';

    public const FIELDS = 'subject,from,toRecipients,ccRecipients,bccRecipients,replyTo,receivedDateTime,isRead,flag,hasAttachments,internetMessageId,conversationId,bodyPreview,body,isDraft';

    public const ROLES = ['inbox' => 'inbox', 'sentitems' => 'sent', 'drafts' => 'drafts', 'deleteditems' => 'trash', 'junkemail' => 'junk', 'archive' => 'archive'];

    public static function root(Account $a): string
    {
        $u = $a->setting('graph_user');

        return self::BASE.($u ? '/users/'.rawurlencode($u) : '/me');
    }

    public function http(Account $a)
    {
        return Http::withToken(OAuth::token($a))->acceptJson()->timeout(30)->retry(2, 800, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException
            || ($e instanceof \Illuminate\Http\Client\RequestException && in_array($e->response->status(), [429, 503, 504], true)), false);
    }

    protected function call(Account $a, string $method, string $url, array $body = [], array $headers = [])
    {
        $url = str_starts_with($url, 'https://') ? $url : self::root($a).$url;
        $r = $this->http($a)->withHeaders($headers)->send($method, $url, $body ? ['json' => $body] : []);
        if ($r->status() === 401) {
            throw new MailProtocolException('Microsoft a refusé l\'accès : reconnectez le compte '.$a->email.'.');
        }
        if ($r->failed()) {
            throw new MailProtocolException('Microsoft 365 : '.($r->json('error.message') ?: 'erreur '.$r->status()), $r->status());
        }

        return $r;
    }

    public function test(Account $a): array
    {
        $r = $this->call($a, 'GET', '/mailFolders/inbox?$select=displayName,totalItemCount,unreadItemCount')->json();

        return ['inbox' => $r['totalItemCount'] ?? 0];
    }

    public function sync(Account $a): array
    {
        $stats = ['new' => 0, 'updated' => 0, 'deleted' => 0];
        $folders = $this->syncFolders($a);
        usort($folders, fn ($x, $y) => ($x->role === 'inbox' ? 0 : 1) <=> ($y->role === 'inbox' ? 0 : 1));
        foreach (array_slice($folders, 0, 60) as $f) {
            if (! $f->sync) {
                continue;
            }
            try {
                foreach ($this->syncFolder($a, $f) as $k => $v) {
                    $stats[$k] += $v;
                }
            } catch (MailProtocolException $e) {
                if ($e->getCode() === 0 || $e->getCode() === 401) {
                    throw $e;
                }
                $f->forceFill(['state' => array_merge($f->state ?? [], ['error' => mb_substr($e->getMessage(), 0, 200)])])->save();
            }
        }

        return $stats;
    }

    /** @return Folder[] */
    protected function syncFolders(Account $a): array
    {
        $roles = $a->stateValue('roles');
        if (! $roles || ($a->stateValue('roles_at', 0) < time() - 86400)) {
            $roles = [];
            foreach (array_keys(self::ROLES) as $wk) {
                try {
                    $roles[$this->call($a, 'GET', '/mailFolders/'.$wk.'?$select=id')->json('id')] = self::ROLES[$wk];
                } catch (MailProtocolException) {
                }
            }
            $a->putState(['roles' => $roles, 'roles_at' => time()]);
            $a->saveQuietly();
        }
        $list = [];
        $url = '/mailFolders?$top=100&$select=id,displayName,childFolderCount,totalItemCount,unreadItemCount';
        while ($url) {
            $r = $this->call($a, 'GET', $url)->json();
            foreach ($r['value'] ?? [] as $x) {
                $list[] = $x + ['path' => $x['displayName']];
                if (($x['childFolderCount'] ?? 0) > 0 && count($list) < 80) {
                    $cr = $this->call($a, 'GET', '/mailFolders/'.$x['id'].'/childFolders?$top=50&$select=id,displayName,childFolderCount')->json();
                    foreach ($cr['value'] ?? [] as $ch) {
                        $list[] = $ch + ['path' => $x['displayName'].'/'.$ch['displayName']];
                    }
                }
            }
            $url = $r['@odata.nextLink'] ?? null;
        }
        $out = [];
        $keep = [];
        foreach ($list as $x) {
            $role = $roles[$x['id']] ?? 'other';
            if ($role === 'other' && in_array(mb_strtolower($x['displayName']), ['conversation history', 'historique des conversations', 'sync issues', 'problèmes de synchronisation', 'outbox', 'boîte d\'envoi', 'rss feeds', 'flux rss'], true)) {
                continue;
            }
            $keep[] = $x['id'];
            $out[] = Store::folder($a, $x['id'], Store::label($x['path'], '/', $role), $role);
        }
        Store::pruneFolders($a, $keep);

        return $out;
    }

    protected function syncFolder(Account $a, Folder $f): array
    {
        $stats = ['new' => 0, 'updated' => 0, 'deleted' => 0];
        $st = $f->state ?? [];
        $url = $st['next'] ?? $st['delta'] ?? '/mailFolders/'.$f->remote_id.'/messages/delta?$select='.self::FIELDS
            .'&$filter='.rawurlencode('receivedDateTime ge '.now()->subDays(max(1, $a->sync_days))->utc()->format('Y-m-d\TH:i:s\Z'));
        $headers = ['Prefer' => 'outlook.body-content-type="html", odata.maxpagesize=50'];
        $pages = 0;
        while ($url && $pages < 12) {
            $r = $this->call($a, 'GET', $url, [], $headers)->json();
            foreach ($r['value'] ?? [] as $x) {
                if (isset($x['@removed'])) {
                    $stats['deleted'] += Message::withoutGlobalScopes()->where('folder_id', $f->id)->where('remote_id', $x['id'])->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);

                    continue;
                }
                $exists = Message::withoutGlobalScopes()->where('folder_id', $f->id)->where('remote_id', $x['id'])->exists();
                if (! isset($x['receivedDateTime']) && $exists) { // changement de drapeaux seulement
                    $m = Message::withoutGlobalScopes()->where('folder_id', $f->id)->where('remote_id', $x['id'])->first();
                    Store::flags($m, self::flags($x));
                    $stats['updated']++;

                    continue;
                }
                Store::upsert($a, $f, $x['id'], self::normalize($x), self::flags($x), (int) mb_strlen($x['body']['content'] ?? ''));
                $exists ? $stats['updated']++ : $stats['new']++;
            }
            $pages++;
            if (isset($r['@odata.deltaLink'])) {
                $st['delta'] = $r['@odata.deltaLink'];
                unset($st['next']);
                $url = null;
            } else {
                $url = $r['@odata.nextLink'] ?? null;
                $st['next'] = $url;
            }
        }
        unset($st['error']);
        $f->forceFill(['state' => $st])->save();
        Store::retention($a, $f);
        Store::counts($f);

        return $stats;
    }

    public static function flags(array $x): array
    {
        $out = [];
        if (array_key_exists('isRead', $x)) {
            $out['seen'] = (bool) $x['isRead'];
        }
        if (isset($x['flag']['flagStatus'])) {
            $out['flagged'] = $x['flag']['flagStatus'] === 'flagged';
        }
        if (array_key_exists('isDraft', $x)) {
            $out['draft'] = (bool) $x['isDraft'];
        }

        return $out;
    }

    public static function normalize(array $x): array
    {
        $addr = fn ($r) => ['name' => $r['emailAddress']['name'] ?? null, 'email' => mb_strtolower($r['emailAddress']['address'] ?? '')];
        $list = fn ($k) => array_values(array_filter(array_map($addr, $x[$k] ?? []), fn ($r) => $r['email'] !== ''));
        $isHtml = strtolower($x['body']['contentType'] ?? 'html') === 'html';

        return [
            'message_id' => isset($x['internetMessageId']) ? mb_substr($x['internetMessageId'], 0, 255) : null,
            'thread' => isset($x['conversationId']) ? sha1('c'.$x['conversationId']) : null,
            'subject' => $x['subject'] ?? null,
            'from_name' => $x['from']['emailAddress']['name'] ?? null,
            'from_email' => $x['from']['emailAddress']['address'] ?? null,
            'to' => $list('toRecipients'), 'cc' => $list('ccRecipients'), 'bcc' => $list('bccRecipients'),
            'reply_to' => $x['replyTo'][0]['emailAddress']['address'] ?? null,
            'date' => isset($x['receivedDateTime']) ? Carbon::parse($x['receivedDateTime'])->setTimezone(config('app.timezone')) : now(),
            'html' => $isHtml ? ($x['body']['content'] ?? null) : null,
            'text' => $isHtml ? null : ($x['body']['content'] ?? null),
            'snippet' => isset($x['bodyPreview']) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $x['bodyPreview'])), 0, 280) : null,
            'has_attachments' => (bool) ($x['hasAttachments'] ?? false),
            'attachments' => [],
        ];
    }

    /** Liste des pièces jointes (chargée à l'ouverture du message). */
    public function loadBody(Account $a, Message $m): void
    {
        if (! $m->has_attachments && ! str_contains((string) $m->body_html, 'cid:')) {
            return;
        }
        $r = $this->call($a, 'GET', '/messages/'.$m->remote_id.'/attachments?$select=id,name,contentType,size,isInline')->json();
        $atts = [];
        foreach ($r['value'] ?? [] as $x) {
            if (($x['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') {
                continue; // éléments Outlook joints (itemAttachment) : non téléchargeables tels quels
            }
            $atts[] = ['id' => $x['id'], 'name' => $x['name'] ?? 'piece-jointe', 'mime' => $x['contentType'] ?? 'application/octet-stream', 'size' => (int) ($x['size'] ?? 0),
                'cid' => null, 'inline' => (bool) ($x['isInline'] ?? false)];
        }
        // identifiants cid des images intégrées
        if (str_contains((string) $m->body_html, 'cid:') && $atts) {
            foreach ($atts as &$att) {
                if ($att['inline']) {
                    $one = $this->call($a, 'GET', '/messages/'.$m->remote_id.'/attachments/'.$att['id'].'?$select=id,contentId')->json();
                    $att['cid'] = isset($one['contentId']) ? trim($one['contentId'], '<>') : null;
                }
            }
            unset($att);
        }
        $m->forceFill(['attachments' => $atts, 'has_attachments' => (bool) array_filter($atts, fn ($x) => ! $x['inline']), 'body_loaded' => true])->save();
    }

    public function attachment(Account $a, Message $m, string $attId): ?array
    {
        $x = $this->call($a, 'GET', '/messages/'.$m->remote_id.'/attachments/'.rawurlencode($attId))->json();
        if (! isset($x['contentBytes'])) {
            return null;
        }

        return ['name' => $x['name'] ?? 'piece-jointe', 'mime' => $x['contentType'] ?? 'application/octet-stream', 'content' => base64_decode($x['contentBytes'])];
    }

    public function setFlags(Account $a, Message $m, array $flags): void
    {
        $body = [];
        if (array_key_exists('seen', $flags)) {
            $body['isRead'] = (bool) $flags['seen'];
        }
        if (array_key_exists('flagged', $flags)) {
            $body['flag'] = ['flagStatus' => $flags['flagged'] ? 'flagged' : 'notFlagged'];
        }
        if ($body) {
            $this->call($a, 'PATCH', '/messages/'.$m->remote_id, $body);
        }
    }

    public function move(Account $a, Message $m, Folder $target): void
    {
        $new = $this->call($a, 'POST', '/messages/'.$m->remote_id.'/move', ['destinationId' => $target->remote_id])->json('id');
        $dup = Message::withTrashed()->where('folder_id', $target->id)->where('remote_id', $new)->first();
        if ($dup) {
            $dup->forceDelete();
        }
        $m->forceFill(['folder_id' => $target->id, 'remote_id' => $new ?: $m->remote_id])->save();
    }

    public function createFolder(Account $a, string $name, ?Folder $parent = null): Folder
    {
        $x = $this->call($a, 'POST', $parent ? '/mailFolders/'.$parent->remote_id.'/childFolders' : '/mailFolders', ['displayName' => trim($name)])->json();
        $path = $parent ? str_replace(' › ', '/', $parent->name).'/'.trim($name) : trim($name);

        return Store::folder($a, (string) $x['id'], Store::label($path, '/'), 'other');
    }

    public function renameFolder(Account $a, Folder $f, string $name): void
    {
        $this->call($a, 'PATCH', '/mailFolders/'.$f->remote_id, ['displayName' => trim($name)]);
        $parts = explode(' › ', $f->name);
        $parts[count($parts) - 1] = trim($name);
        $f->forceFill(['name' => implode(' › ', $parts)])->save();
    }

    public function deleteFolder(Account $a, Folder $f): void
    {
        $this->call($a, 'DELETE', '/mailFolders/'.$f->remote_id);
    }

    public function destroy(Account $a, Message $m): void
    {
        $this->call($a, 'DELETE', '/messages/'.$m->remote_id);
        $m->delete();
    }

    public function send(Account $a, array $mail): void
    {
        $rcp = fn (array $l) => array_map(fn ($r) => ['emailAddress' => array_filter(['address' => $r['email'], 'name' => $r['name'] ?? null])], $l);
        $atts = [];
        foreach ($mail['attachments'] as $x) {
            if (strlen($x['content']) > 3 * 1024 * 1024) {
                throw new \DomainException('« '.$x['name'].' » dépasse 3 Mo, la limite d\'envoi direct de Microsoft 365 : partagez-la plutôt par un lien depuis les Documents.');
            }
            $atts[] = ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $x['name'], 'contentType' => $x['mime'], 'contentBytes' => base64_encode($x['content'])];
        }
        $msg = array_filter([
            'subject' => $mail['subject'],
            'body' => ['contentType' => 'HTML', 'content' => $mail['html']],
            'toRecipients' => $rcp($mail['to']), 'ccRecipients' => $rcp($mail['cc']), 'bccRecipients' => $rcp($mail['bcc']),
            'attachments' => $atts,
            'importance' => $mail['importance'],
            'isReadReceiptRequested' => $mail['read_receipt'] ?: null,
        ], fn ($v) => $v !== null && $v !== []);
        $orig = $mail['original'];
        if ($orig && $orig->account_id === $a->id && in_array($mail['mode'], ['reply', 'reply_all', 'forward'], true)) {
            // réponse dans la conversation Outlook : sujet et fil gérés par Exchange, contenu remplacé par le nôtre
            $action = $mail['mode'] === 'forward' ? 'forward' : ($mail['mode'] === 'reply_all' ? 'replyAll' : 'reply');
            $draft = $this->call($a, 'POST', '/messages/'.$orig->remote_id.'/create'.ucfirst($action), [])->json('id');
            unset($msg['attachments']);
            $this->call($a, 'PATCH', '/messages/'.$draft, $msg);
            foreach ($atts as $att) {
                $this->call($a, 'POST', '/messages/'.$draft.'/attachments', $att);
            }
            $this->call($a, 'POST', '/messages/'.$draft.'/send');

            return;
        }
        $this->call($a, 'POST', '/sendMail', ['message' => $msg, 'saveToSentItems' => true]);
    }
}
