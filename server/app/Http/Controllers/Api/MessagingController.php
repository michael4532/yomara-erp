<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\ActivityLog;
use App\Models\MessagingChannel;
use App\Models\MessagingLog;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Canaux d'envoi de la société et envoi unitaire (document, relance, message). */
class MessagingController extends Controller
{
    public function __construct(protected MessagingService $messaging) {}

    protected static function mask(array $settings, array $secret): array
    {
        $out = [];
        foreach ($settings as $k => $v) {
            $out[$k] = in_array($k, $secret, true) && $v ? str_repeat('•', 6).(strlen((string) $v) > 8 ? substr((string) $v, -3) : '') : $v;
        }

        return $out;
    }

    public function setup()
    {
        $company = app('tenant');
        $channels = MessagingChannel::all()->keyBy('channel');
        $providers = [];
        foreach (MessagingService::PROVIDERS as $channel => $list) {
            foreach ($list as $key => $meta) {
                $providers[$channel][] = ['key' => $key] + $meta + ['locked' => ! empty($meta['module']) && ! $company->hasModule($meta['module'])];
            }
        }

        // Réglages (identifiants des fournisseurs) : seulement pour qui gère la société ; les autres voient l'état des canaux
        $admin = request()->user()->hasPermission('core.settings.manage');

        return [
            'providers' => $providers,
            'channels' => $channels->map(fn ($c) => [
                'channel' => $c->channel, 'provider' => $c->provider, 'enabled' => $c->enabled,
                'settings' => $admin ? self::mask((array) $c->settings, MessagingService::PROVIDERS[$c->channel][$c->provider]['secret'] ?? []) : [],
            ]),
            'available' => $this->messaging->available($company),
            // Boîtes mail de l'utilisateur (module YoMail) : envoi depuis sa propre adresse
            'mailboxes' => $company->hasModule('mailbox') && \Illuminate\Support\Facades\Schema::hasTable('mb_accounts')
                ? \App\Modules\Mailbox\Models\Account::where('user_id', request()->user()->id)->get(['id', 'email', 'display_name', 'status'])->values() : [],
            'platform_mail' => MessagingService::platformMailReady() ? MessagingService::platformUsage($company) : null,
            'modules' => ['sms' => $company->hasModule('sms'), 'whatsapp' => $company->hasModule('whatsapp'), 'mail_server' => $company->hasModule('mail_server'), 'marketing' => $company->hasModule('marketing')],
        ];
    }

    public function update(Request $r, string $channel)
    {
        abort_unless(isset(MessagingService::PROVIDERS[$channel]), 404);
        $data = $r->validate([
            'provider' => ['required', Rule::in(array_keys(MessagingService::PROVIDERS[$channel]))],
            'settings' => 'sometimes|array',
            'settings.*' => 'nullable|string|max:2000',
            'enabled' => 'sometimes|boolean',
        ]);
        $meta = MessagingService::PROVIDERS[$channel][$data['provider']];
        if (! empty($meta['module']) && ! app('tenant')->hasModule($meta['module'])) {
            return response()->json(['message' => 'Activez d\'abord le module correspondant dans la boutique.'], 422);
        }
        $row = MessagingChannel::firstOrNew(['channel' => $channel]);
        $current = $row->exists && $row->provider === $data['provider'] ? (array) $row->settings : [];
        foreach ($data['settings'] ?? [] as $k => $v) {
            if (! array_key_exists($k, $meta['fields'])) {
                continue;
            }
            if (in_array($k, $meta['secret'], true) && ($v === null || $v === '' || str_starts_with((string) $v, '••'))) {
                continue; // secret laissé vide : conservé
            }
            $current[$k] = $v;
        }
        $row->fill(['provider' => $data['provider'], 'settings' => $current, 'enabled' => $data['enabled'] ?? true]);
        $row->save();
        ActivityLog::record('messaging.channel.updated', $row, ['channel' => $channel, 'provider' => $data['provider']]);

        return $this->setup();
    }

    public function test(Request $r)
    {
        $data = $r->validate(['channel' => 'required|in:email,sms,whatsapp', 'to' => 'required|string|max:190']);
        $company = app('tenant');
        try {
            $res = $this->messaging->send($company, $data['channel'], new Message(
                to: $data['to'],
                subject: 'Test d\'envoi — '.$company->name,
                html: '<p>Bonjour,</p><p>Ce message confirme que l\'envoi '.($data['channel'] === 'email' ? 'd\'e-mails' : 'de messages').' fonctionne depuis <b>'.e(config('erp.name')).'</b>.</p>',
                text: 'Test d\'envoi depuis '.config('erp.name').' ('.$company->name.') : la configuration fonctionne.',
            ), 'test', $r->user()->id);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $res->ok ? ['ok' => true] : response()->json(['message' => 'Échec : '.$res->error], 422);
    }

    /** Envoi d'un document (PDF joint) ou d'un message à un contact. */
    public function send(Request $r)
    {
        $data = $r->validate([
            'channel' => 'required|in:email,sms,whatsapp',
            'to' => 'required|string|max:190',
            'to_name' => 'nullable|string|max:190',
            'subject' => 'nullable|string|max:190',
            'message' => 'required|string|max:10000',
            'context' => 'nullable|string|max:50',
            'attachment' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png',
            'from_account_id' => 'nullable|integer',
            'cc' => 'nullable|string|max:500',
            // Fiche du document dans l'e-mail (facultatif)
            'doc_label' => 'nullable|string|max:60', 'doc_number' => 'nullable|string|max:60', 'doc_total' => 'nullable|string|max:60',
            'doc_date' => 'nullable|string|max:30', 'doc_due' => 'nullable|string|max:30', 'pay_url' => 'nullable|url|max:500',
        ]);
        $company = app('tenant');
        // Envoi depuis la boîte mail de l'utilisateur (son SMTP, sa signature, copie dans « Envoyés »)
        if ($data['channel'] === 'email' && ! empty($data['from_account_id']) && $company->hasModule('mailbox')) {
            return $this->sendFromMailbox($r, $data);
        }
        $attachments = [];
        $mediaUrl = null;
        if ($f = $r->file('attachment')) {
            if ($data['channel'] === 'email') {
                $attachments[] = ['name' => $f->getClientOriginalName(), 'data' => file_get_contents($f->getRealPath()), 'mime' => $f->getMimeType()];
            } else {
                // WhatsApp / SMS : lien public non devinable vers le document (supprimé après 30 jours)
                $dir = public_path('uploads/shared/'.$company->id);
                File::ensureDirectoryExists($dir);
                // Extension déduite du contenu réel (jamais celle du nom envoyé) : pas de page HTML déguisée
                $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$f->getMimeType()] ?? null;
                abort_unless($ext, 422, 'Pièce jointe refusée : PDF, JPG ou PNG uniquement.');
                $name = Str::random(40).'.'.$ext;
                $f->move($dir, $name);
                $mediaUrl = url('uploads/shared/'.$company->id.'/'.$name);
            }
        }
        $text = $data['message'];
        if ($mediaUrl && $data['channel'] === 'sms') {
            $text .= "\n".$mediaUrl;
        }
        try {
            $res = $this->messaging->send($company, $data['channel'], new Message(
                to: $data['to'], toName: $data['to_name'] ?? null, subject: $data['subject'] ?? $company->name,
                html: $data['channel'] === 'email' ? \App\Support\MailTemplates::layout($company, $text, self::docOptions($data)) : null, text: $text,
                attachments: $attachments, mediaUrl: $data['channel'] === 'whatsapp' ? $mediaUrl : null,
                replyTo: $company->email,
            ), $data['context'] ?? null, $r->user()->id);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $res->ok ? ['ok' => true, 'id' => $res->id] : response()->json(['message' => 'Échec de l\'envoi : '.$res->error], 422);
    }

    /** Fiche du document et bouton « Payer en ligne » de la mise en page des e-mails. */
    protected static function docOptions(array $d): array
    {
        $card = array_filter([
            $d['doc_label'] ?? 'Document' => $d['doc_number'] ?? null, 'Date' => $d['doc_date'] ?? null,
            'Montant' => $d['doc_total'] ?? null, 'Échéance' => $d['doc_due'] ?? null,
        ], fn ($v) => (string) $v !== '');

        return ['card' => isset($d['doc_number']) && $d['doc_number'] !== '' ? $card : [], 'button' => ! empty($d['pay_url']) ? ['Payer en ligne', $d['pay_url']] : null];
    }

    protected function sendFromMailbox(Request $r, array $data)
    {
        $a = \App\Modules\Mailbox\Models\Account::find((int) $data['from_account_id']);
        abort_unless($a && $a->readableBy($r->user()->id), 404, 'Boîte mail introuvable.');
        $sig = DB::table('mb_signatures')->where('company_id', $a->company_id)->where(fn ($q) => $q->where('account_id', $a->id)->orWhere(fn ($w) => $w->where('user_id', $r->user()->id)->whereNull('account_id')))
            ->orderByDesc('is_default')->orderByDesc('account_id')->value('html');
        $html = \App\Support\MailTemplates::layout(app('tenant'), $data['message'], self::docOptions($data) + ['signature' => $sig]);
        $atts = [];
        if ($f = $r->file('attachment')) {
            $atts[] = ['type' => 'upload', 'name' => $f->getClientOriginalName(), 'mime' => $f->getMimeType(), 'data' => base64_encode(file_get_contents($f->getRealPath()))];
        }
        $payload = ['id' => (string) Str::uuid(), 'account_id' => $a->id, 'to' => [['email' => $data['to'], 'name' => $data['to_name'] ?? null]],
            'cc' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['cc'] ?? ''))))), 'subject' => $data['subject'] ?? app('tenant')->name, 'html' => $html,
            'mode' => 'new', 'attachments' => $atts];
        try {
            $mail = \App\Modules\Mailbox\Composer::build($a, $payload, $r->user());
            \App\Modules\Mailbox\Antispam::check($a, $mail);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $row = app(\App\Modules\Mailbox\MailboxService::class)->queue($a, $r->user()->id, $payload['id'], $payload, $mail);
        if ($row['status'] === 'failed') {
            return response()->json(['message' => 'Échec de l\'envoi : '.$row['error']], 422);
        }
        if (! empty($data['context'])) {
            MessagingLog::create(['company_id' => app('tenant')->id, 'channel' => 'email', 'provider' => 'mailbox:'.$a->email, 'to' => $data['to'], 'subject' => $payload['subject'],
                'status' => 'sent', 'context' => $data['context'], 'user_id' => $r->user()->id]);
        }

        return ['ok' => true, 'id' => $payload['id'], 'from' => $a->email];
    }

    public function log(Request $r)
    {
        return ['data' => MessagingLog::latest('id')->limit(200)->get()];
    }

    // ---------- Super admin : serveur de messagerie de la plateforme ----------

    public function platform()
    {
        $c = (array) PlatformSetting::get('mail.platform', []);
        unset($c['password_enc']);

        return ['settings' => $c + ['has_password' => ! empty(PlatformSetting::get('mail.platform', [])['password_enc'] ?? null)]];
    }

    public function updatePlatform(Request $r)
    {
        $data = $r->validate([
            'host' => 'nullable|string|max:190', 'port' => 'nullable|integer|min:1|max:65535', 'encryption' => 'nullable|in:tls,ssl,none',
            'username' => 'nullable|string|max:190', 'password' => 'nullable|string|max:500', 'from_email' => 'nullable|email|max:190',
            'from_name' => 'nullable|string|max:190', 'quota' => 'nullable|integer|min:0|max:10000000',
        ]);
        $current = (array) PlatformSetting::get('mail.platform', []);
        if (! empty($data['password'])) {
            $current['password_enc'] = Crypt::encryptString($data['password']);
        }
        unset($data['password']);
        PlatformSetting::put('mail.platform', array_merge($current, array_filter($data, fn ($v) => $v !== null)));

        return $this->platform();
    }
}
