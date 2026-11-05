<?php

namespace App\Modules\Helpdesk;

use App\Http\Controllers\Controller;
use App\Modules\Helpdesk\Inbound\InboundService;
use App\Modules\Helpdesk\Inbound\Mailbox;
use App\Modules\Helpdesk\Models\Attachment;
use App\Modules\Helpdesk\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class HelpdeskController extends Controller
{
    public function __construct(private HelpdeskService $svc) {}

    public function stats(Request $r)
    {
        return ['data' => $this->svc->stats((int) $r->query('days', 30))];
    }

    /** Personnes qui traitent les tickets (pour l'attribution). */
    public function agents()
    {
        return ['data' => \App\Models\User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($u) => $u->hasPermission('helpdesk.agent'))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()];
    }

    public function settings()
    {
        $c = app('tenant');

        return ['data' => $this->svc->settings($c) + ['help_url' => url('/aide/'.$c->slug), 'contact_url' => url('/aide/'.$c->slug.'/contact')]];
    }

    public function saveSettings(Request $r)
    {
        $v = $r->validate([
            'hours' => 'sometimes|array', 'hours.start' => ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'hours.end' => ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'hours.days' => 'sometimes|array|min:1', 'hours.days.*' => 'integer|between:1,7',
            'auto_close_days' => 'sometimes|integer|min:1|max:60', 'auto_assign' => 'sometimes|boolean', 'ack' => 'sometimes|boolean', 'csat' => 'sometimes|boolean',
            'kb_public' => 'sometimes|boolean', 'contact_form' => 'sometimes|boolean',
        ]);
        if (isset($v['hours']['start'], $v['hours']['end']) && $v['hours']['end'] <= $v['hours']['start']) {
            return response()->json(['message' => 'L\'heure de fermeture doit suivre l\'ouverture.'], 422);
        }

        return ['data' => $this->svc->saveSettings(app('tenant'), $v)];
    }

    // ---------- Réception des e-mails et de WhatsApp ----------

    public function inbound()
    {
        return ['data' => Mailbox::for(app('tenant')->id)->toPublic()];
    }

    public function saveInbound(Request $r)
    {
        $v = $r->validate([
            'address' => 'nullable|email|max:255', 'imap_enabled' => 'sometimes|boolean', 'imap_host' => 'nullable|string|max:255', 'imap_port' => 'sometimes|integer|between:1,65535',
            'imap_encryption' => 'sometimes|in:ssl,tls,none', 'imap_username' => 'nullable|string|max:255', 'imap_password' => 'nullable|string|max:255', 'imap_folder' => 'sometimes|string|max:120',
            'whatsapp_enabled' => 'sometimes|boolean',
        ]);
        $box = Mailbox::for(app('tenant')->id);
        if (array_key_exists('imap_password', $v)) {
            if ($v['imap_password'] !== null && $v['imap_password'] !== '') {
                $box->imap_password_enc = Crypt::encryptString($v['imap_password']);
            }
            unset($v['imap_password']);
        }
        if (! empty($v['imap_enabled']) && (empty($v['imap_host'] ?? $box->imap_host) || empty($v['imap_username'] ?? $box->imap_username) || ! ($box->imap_password_enc))) {
            return response()->json(['message' => 'Indiquez le serveur, l\'identifiant et le mot de passe de la boîte.'], 422);
        }
        if (isset($v['imap_host']) && $v['imap_host'] !== $box->imap_host) {
            $box->last_uid = 0;
        }
        $box->fill($v)->save();

        return ['data' => $box->fresh()->toPublic()];
    }

    /** Relève la boîte maintenant (teste aussi la connexion). */
    public function fetchNow(InboundService $in)
    {
        $box = Mailbox::for(app('tenant')->id);
        if (! $box->imap_host || ! $box->imap_username || ! $box->imap_password_enc) {
            return response()->json(['message' => 'Boîte de réception non configurée.'], 422);
        }
        $res = $in->fetchMailbox(app('tenant'), $box);
        if (isset($res['error'])) {
            return response()->json(['message' => $res['error'], 'data' => $box->fresh()->toPublic()], 422);
        }

        return ['data' => $box->fresh()->toPublic(), 'result' => $res];
    }

    /** Pièce jointe déposée par l'équipe (envoyée avec la prochaine réponse au client si elle est visible du client). */
    public function upload(Request $r, string $id, InboundService $in)
    {
        $r->validate(['file' => 'required|file|max:15360', 'public' => 'sometimes|boolean']);
        $t = Ticket::findOrFail($id);
        $f = $r->file('file');
        $saved = $in->storeFiles($t, [['name' => $f->getClientOriginalName(), 'mime' => $f->getClientMimeType(), 'data' => (string) file_get_contents($f->getRealPath())]], null, $r->boolean('public', true), $r->user()->name);
        if (! $saved) {
            return response()->json(['message' => 'Fichier refusé (type interdit ou plus de 15 Mo).'], 422);
        }

        return response()->json(['data' => $saved[0]->toSyncArray()], 201);
    }

    public function download(string $id)
    {
        $a = Attachment::findOrFail($id);
        abort_unless(Storage::disk('local')->exists($a->path), 404);

        return Storage::disk('local')->download($a->path, $a->name);
    }
}
