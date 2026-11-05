<?php

namespace App\Modules\Signature;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Sequence;
use App\Models\User;
use App\Modules\Signature\Models\SignEvent;
use App\Modules\Signature\Models\Signer;
use App\Modules\Signature\Models\SignRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SignatureService
{
    public const DEFAULTS = ['otp' => true, 'reminder_days' => 3, 'max_reminders' => 3, 'expiry_days' => 30];

    public const MAX_PDF = 15 * 1024 * 1024;

    public function __construct(protected MessagingService $messaging) {}

    public function settings(Company $company): array
    {
        return array_merge(self::DEFAULTS, (array) data_get($company->settings, 'signature', []));
    }

    public function tz(Company $company): string
    {
        return $company->setting('timezone') ?: config('app.timezone');
    }

    public function at(Company $company, $date): string
    {
        $d = $date->copy()->setTimezone($this->tz($company));

        return $d->format('d/m/Y à H:i').' (UTC'.$d->format('P').')';
    }

    public function log(SignRequest $r, string $type, ?Signer $s = null, array $data = [], ?string $ip = null, ?string $ua = null): void
    {
        $e = new SignEvent(['request_id' => $r->id, 'signer_id' => $s?->id, 'user_id' => auth()->id(), 'type' => $type, 'ip' => $ip, 'user_agent' => $ua ? mb_substr($ua, 0, 250) : null, 'data' => $data ?: null]);
        $e->company_id = $r->company_id;
        $e->save();
    }

    /**
     * Nouvelle demande : PDF + signataires (externes par lien, internes dans l'application).
     *
     * @param  array  $signers  [['name','label','email','phone','user_id']]
     */
    public function create(Company $company, User $by, string $pdf, string $fileName, array $data, array $signers): SignRequest
    {
        if (strlen($pdf) > self::MAX_PDF) {
            throw new \DomainException('PDF trop lourd (15 Mo maximum).');
        }
        if (! str_starts_with(ltrim(substr($pdf, 0, 1024)), '%PDF-')) {
            throw new \DomainException('Le fichier doit être un PDF.');
        }
        $signers = array_values(array_filter($signers, fn ($s) => trim((string) ($s['name'] ?? '')) !== '' || ! empty($s['user_id'])));
        if (! $signers) {
            throw new \DomainException('Ajoutez au moins un signataire.');
        }
        if (count($signers) > 10) {
            throw new \DomainException('10 signataires au maximum.');
        }
        $users = User::where('company_id', $company->id)->where('is_active', true)->get()->keyBy('id');
        foreach ($signers as $i => $s) {
            if (! empty($s['user_id'])) {
                $u = $users[(int) $s['user_id']] ?? null;
                if (! $u) {
                    throw new \DomainException('Signataire interne inconnu ou désactivé.');
                }
                $signers[$i]['name'] = trim((string) ($s['name'] ?? '')) ?: $u->name;
                $signers[$i]['email'] ??= $u->email;
            }
            if (! empty($s['email']) && ! filter_var($s['email'], FILTER_VALIDATE_EMAIL)) {
                throw new \DomainException('Adresse e-mail invalide : '.$s['email']);
            }
        }

        return DB::transaction(function () use ($company, $by, $pdf, $fileName, $data, $signers) {
            $r = new SignRequest([
                'title' => mb_substr(trim($data['title'] ?? '') ?: pathinfo($fileName, PATHINFO_FILENAME), 0, 200),
                'message' => $data['message'] ?? null, 'sequential' => (bool) ($data['sequential'] ?? false),
                'expires_at' => now($this->tz($company))->addDays(max(1, (int) ($data['expires_days'] ?? $this->settings($company)['expiry_days'])))->toDateString(),
                'source_type' => $data['source_type'] ?? null, 'source_id' => $data['source_id'] ?? null,
            ]);
            $r->id = (string) Str::uuid();
            $r->company_id = $company->id;
            $r->number = Sequence::next($company->id, 'signature', 'SIG');
            $r->status = 'draft';
            $r->created_by = $by->id;
            $r->original_name = mb_substr(basename($fileName) ?: 'document.pdf', 0, 180);
            $r->original_path = 'signatures/'.$company->id.'/'.$r->id.'/original.pdf';
            $r->original_sha256 = hash('sha256', $pdf);
            $r->pages = SignaturePdf::countPages($pdf);
            Storage::disk('local')->put($r->original_path, $pdf);
            $r->save();
            foreach ($signers as $i => $s) {
                $token = Str::random(40);
                $x = new Signer([
                    'position' => $r->sequential ? $i + 1 : 1, 'name' => mb_substr(trim($s['name']), 0, 150), 'label' => isset($s['label']) && trim($s['label']) !== '' ? mb_substr(trim($s['label']), 0, 120) : null,
                    'email' => $s['email'] ?? null ?: null, 'phone' => $s['phone'] ?? null ?: null, 'user_id' => ! empty($s['user_id']) ? (int) $s['user_id'] : null,
                ]);
                $x->company_id = $company->id;
                $x->request_id = $r->id;
                $x->token = $token;
                $x->token_hash = hash('sha256', $token);
                $x->status = 'waiting';
                $x->save();
            }
            $this->log($r, 'created', null, ['by' => $by->name, 'file' => $r->original_name, 'sha256' => $r->original_sha256]);

            return $r;
        });
    }

    /** Envoi : les signataires dont c'est le tour reçoivent leur lien (ou une notification pour les internes). */
    public function send(Company $company, SignRequest $r): array
    {
        if ($r->status !== 'draft') {
            throw new \DomainException('Cette demande a déjà été envoyée.');
        }
        $r->forceFill(['status' => 'pending', 'sent_at' => now()])->save();

        return $this->activate($company, $r);
    }

    /** Ouvre le tour des signataires suivants et les prévient. */
    protected function activate(Company $company, SignRequest $r): array
    {
        $open = $r->signers()->get()->filter(fn ($s) => ! $s->done());
        if ($open->isEmpty()) {
            return [];
        }
        $turn = $r->sequential ? $open->where('position', $open->min('position')) : $open;
        $out = [];
        foreach ($turn as $s) {
            if ($s->status === 'waiting') {
                $s->forceFill(['status' => 'pending'])->save();
                $out[$s->id] = $this->notify($company, $r, $s);
            }
        }

        return $out;
    }

    /** Canal pour joindre un signataire externe (null : lien à transmettre soi-même). */
    public function notify(Company $company, SignRequest $r, Signer $s, bool $reminder = false): ?string
    {
        if ($s->user_id) {
            AppNotification::send($company->id, [$s->user_id], 'signature', $reminder ? 'Rappel : document à signer' : 'Document à signer', $r->title.' ('.$r->number.')', 'signature', $r->id);
            $s->forceFill(['notified_at' => now()])->save();
            $this->log($r, $reminder ? 'reminded' : 'sent', $s, ['channel' => 'app']);

            return 'app';
        }
        $date = $r->expires_at ? $r->expires_at->format('d/m/Y') : null;
        $text = ($reminder ? 'Rappel — ' : '')."Bonjour {$s->name},\n\n{$company->name} vous invite à signer électroniquement le document « {$r->title} ».".
            ($r->message ? "\n\n".$r->message : '')."\n\nConsultez et signez en ligne : ".$s->link().($date ? "\nLien valable jusqu'au $date." : '')."\n\nCordialement,\n{$company->name}";
        $ch = $this->deliver($company, $s, ($reminder ? 'Rappel : ' : '').'Document à signer — '.$r->title, $text, 'signature:'.$r->id);
        if ($ch) {
            $s->forceFill(['notified_at' => now()])->save();
        }
        $this->log($r, $ch ? ($reminder ? 'reminded' : 'sent') : 'link_shared', $s, $ch ? ['channel' => $ch, 'to' => Signer::mask($ch === 'email' ? $s->email : $s->phone)] : []);

        return $ch;
    }

    /** E-mail, puis WhatsApp, puis SMS selon ce qui est configuré et les coordonnées du signataire. */
    protected function deliver(Company $company, Signer $s, string $subject, string $text, string $context, ?array $order = null): ?string
    {
        $available = $this->messaging->available($company);
        foreach ($order ?? ['email', 'whatsapp', 'sms'] as $ch) {
            $to = $ch === 'email' ? $s->email : $s->phone;
            if (! $to || ! ($available[$ch] ?? null)) {
                continue;
            }
            try {
                $m = $ch === 'email'
                    ? new Message(to: $to, toName: $s->name, subject: $subject, html: nl2br(e($text)), text: $text, replyTo: $company->email)
                    : new Message(to: $to, toName: $s->name, text: $text);
                if ($this->messaging->send($company, $ch, $m, $context)->ok) {
                    return $ch;
                }
            } catch (\Throwable $e) {
                Log::warning('Signature : envoi impossible', ['channel' => $ch, 'error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /** Canal du code de vérification (null : pas de code possible, le lien personnel fait foi). */
    public function otpChannel(Company $company, Signer $s): ?string
    {
        if ($s->user_id || ! $this->settings($company)['otp']) {
            return null;
        }
        $a = $this->messaging->available($company);
        foreach (['sms' => $s->phone, 'whatsapp' => $s->phone, 'email' => $s->email] as $ch => $to) {
            if ($to && ($a[$ch] ?? null)) {
                return $ch;
            }
        }

        return null;
    }

    protected function otpHash(Signer $s, string $code): string
    {
        return hash_hmac('sha256', $s->id.':'.$code, (string) config('app.key'));
    }

    public function sendOtp(Company $company, SignRequest $r, Signer $s, ?string $ip = null): string
    {
        $this->assertCanAct($r, $s);
        $ch = $this->otpChannel($company, $s);
        if (! $ch) {
            throw new \DomainException('Aucun code n\'est nécessaire.');
        }
        if ($s->otp_sent_at && $s->otp_sent_at->gt(now()->subSeconds(60))) {
            throw new \DomainException('Un code vient d\'être envoyé. Patientez une minute avant d\'en demander un autre.');
        }
        $code = (string) random_int(100000, 999999);
        $s->forceFill(['otp_hash' => $this->otpHash($s, $code), 'otp_expires_at' => now()->addMinutes(10), 'otp_attempts' => 0, 'otp_channel' => $ch, 'otp_sent_at' => now()])->save();
        $text = "Votre code de signature {$company->name} : $code\nValable 10 minutes. Ne le communiquez à personne.";
        $sent = $this->deliver($company, $s, 'Votre code de signature : '.$code, $text, 'signature-otp:'.$r->id, [$ch]);
        if (! $sent) {
            throw new \DomainException('Le code n\'a pas pu être envoyé. Réessayez dans un instant.');
        }
        $this->log($r, 'otp_sent', $s, ['channel' => $ch, 'to' => Signer::mask($ch === 'email' ? $s->email : $s->phone)], $ip);

        return $ch;
    }

    public function verifyOtp(Company $company, SignRequest $r, Signer $s, string $code, ?string $ip = null): void
    {
        $this->assertCanAct($r, $s);
        if (! $s->otp_hash || ! $s->otp_expires_at) {
            throw new \DomainException('Demandez d\'abord votre code.');
        }
        if ($s->otp_attempts >= 5) {
            throw new \DomainException('Trop d\'essais : demandez un nouveau code.');
        }
        if ($s->otp_expires_at->isPast()) {
            throw new \DomainException('Code expiré : demandez un nouveau code.');
        }
        if (! hash_equals($s->otp_hash, $this->otpHash($s, preg_replace('/\D/', '', $code)))) {
            $s->forceFill(['otp_attempts' => $s->otp_attempts + 1])->save();
            $this->log($r, 'otp_failed', $s, [], $ip);
            throw new \DomainException('Code incorrect.');
        }
        $s->forceFill(['verified_at' => now(), 'verified_by' => $s->otp_channel, 'otp_hash' => null])->save();
        $this->log($r, 'verified', $s, ['channel' => $s->otp_channel], $ip);
    }

    /** Le signataire peut-il agir maintenant ? (demande ouverte, pas encore signé, c'est son tour) */
    public function assertCanAct(SignRequest $r, Signer $s): void
    {
        $this->expireIfNeeded($r);
        if ($r->status !== 'pending') {
            throw new \DomainException(match ($r->status) {
                'completed' => 'Ce document est déjà signé par toutes les parties.',
                'cancelled' => 'Cette demande de signature a été annulée.',
                'expired' => 'Cette demande de signature a expiré.',
                'declined' => 'Cette demande de signature a été refusée par un signataire.',
                default => 'Cette demande n\'est pas encore envoyée.',
            });
        }
        if ($s->done()) {
            throw new \DomainException($s->status === 'signed' ? 'Vous avez déjà signé ce document.' : 'Vous avez refusé de signer ce document.');
        }
        if ($s->status === 'waiting') {
            throw new \DomainException('Un autre signataire doit signer avant vous. Vous recevrez votre lien à votre tour.');
        }
    }

    public function viewed(SignRequest $r, Signer $s, ?string $ip, ?string $ua): void
    {
        if (in_array($s->status, ['pending'], true) && $r->status === 'pending') {
            $s->forceFill(['status' => 'viewed', 'viewed_at' => now()])->save();
            $this->log($r, 'viewed', $s, [], $ip, $ua);
        }
    }

    /** Signature : image PNG (data URI) tracée par le signataire. */
    public function sign(Company $company, SignRequest $r, Signer $s, string $png, ?string $ip, ?string $ua, bool $viaAccount = false): SignRequest
    {
        $this->assertCanAct($r, $s);
        if ($viaAccount !== (bool) $s->user_id) {
            throw new \DomainException($s->user_id ? 'Ce signataire signe depuis l\'application.' : 'Signature réservée au signataire.');
        }
        if (! $viaAccount && $this->otpChannel($company, $s) && ! $s->verified_at) {
            throw new \DomainException('Saisissez d\'abord le code de vérification reçu.');
        }
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($png), $m) || ! ($bin = base64_decode($m[1], true)) || strlen($bin) > 1024 * 1024 || ! @getimagesizefromstring($bin)) {
            throw new \DomainException('Tracez votre signature.');
        }
        $path = 'signatures/'.$company->id.'/'.$r->id.'/signer-'.$s->id.'.png';
        Storage::disk('local')->put($path, $bin);
        $s->forceFill([
            'status' => 'signed', 'signed_at' => now(), 'signature_path' => $path, 'ip' => $ip, 'user_agent' => $ua ? mb_substr($ua, 0, 250) : null,
            'viewed_at' => $s->viewed_at ?? now(), 'verified_at' => $s->verified_at ?? now(), 'verified_by' => $s->verified_by ?? ($viaAccount ? 'account' : 'link'),
        ])->save();
        $this->log($r, 'signed', $s, ['verified_by' => $s->verified_by], $ip, $ua);
        if ($r->created_by && $r->created_by !== $s->user_id) {
            AppNotification::send($company->id, [$r->created_by], 'signature', 'Document signé par '.$s->name, $r->title.' ('.$r->number.')', 'signature', $r->id);
        }
        if ($r->signers()->get()->every(fn ($x) => $x->status === 'signed')) {
            $this->complete($company, $r);
        } else {
            $this->activate($company, $r);
        }

        return $r->fresh();
    }

    public function decline(Company $company, SignRequest $r, Signer $s, ?string $reason, ?string $ip, ?string $ua, bool $viaAccount = false): void
    {
        $this->assertCanAct($r, $s);
        if ($viaAccount !== (bool) $s->user_id) {
            throw new \DomainException('Action réservée au signataire.');
        }
        $s->forceFill(['status' => 'declined', 'declined_at' => now(), 'decline_reason' => $reason ? mb_substr($reason, 0, 500) : null, 'ip' => $ip])->save();
        $r->forceFill(['status' => 'declined'])->save();
        $this->log($r, 'declined', $s, ['reason' => $reason], $ip, $ua);
        AppNotification::send($company->id, $this->managers($company, $r), 'signature', 'Signature refusée par '.$s->name, $r->title.($reason ? ' — « '.$reason.' »' : ''), 'signature', $r->id);
    }

    protected function managers(Company $company, SignRequest $r): array
    {
        return User::where('company_id', $company->id)->where('is_active', true)->get()
            ->filter(fn ($u) => $u->id === $r->created_by || $u->hasPermission('signature.manage'))->pluck('id')->all();
    }

    /** Toutes les signatures recueillies : document scellé, copies envoyées, devis transformé en commande. */
    public function complete(Company $company, SignRequest $r): void
    {
        $r->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->log($r, 'completed');
        try {
            app(SignaturePdf::class)->seal($company, $r->fresh(['signers', 'events']));
            $this->log($r, 'sealed', null, ['sha256' => $r->fresh()->signed_sha256, 'stamped' => $r->fresh()->stamped]);
        } catch (\Throwable $e) {
            Log::error('Signature : scellement impossible', ['request' => $r->id, 'error' => $e->getMessage()]);
        }
        $r->refresh();
        AppNotification::send($company->id, $this->managers($company, $r), 'signature', 'Document signé par toutes les parties', $r->title.' ('.$r->number.')', 'signature', $r->id);
        foreach ($r->signers as $s) {
            if (! $s->user_id) {
                $this->deliver($company, $s, 'Document signé — '.$r->title,
                    "Bonjour {$s->name},\n\nLe document « {$r->title} » est signé par toutes les parties. Téléchargez votre exemplaire signé : ".$s->link()."\n\nCordialement,\n{$company->name}", 'signature:'.$r->id);
            }
        }
        $this->afterCompleted($company, $r);
        $this->file($company, $r);
    }

    /** Module Documents : le PDF signé est classé dans « Documents signés », relié au client ou au salarié. */
    protected function file(Company $company, SignRequest $r): void
    {
        if (! $r->signed_path || ! $company->hasModule('documents')) {
            return;
        }
        try {
            $meta = ['source_type' => 'signature', 'source_id' => $r->id, 'description' => $r->title.' — '.$r->number, 'tags' => ['signé']];
            if ($r->source_type === 'sales_document' && class_exists(\App\Modules\Ventes\Models\SalesDocument::class)) {
                $meta['partner_id'] = \App\Modules\Ventes\Models\SalesDocument::find($r->source_id)?->partner_id;
            } elseif ($r->source_type === 'rh_document' && class_exists(\App\Modules\Rh\Models\HrDocument::class)) {
                $meta['employee_id'] = \App\Modules\Rh\Models\HrDocument::find($r->source_id)?->employee_id;
            }
            app(\App\Modules\Documents\DocumentService::class)->fileFromBytes($company, 'signatures', 'Documents signés', Storage::disk('local')->get($r->signed_path),
                pathinfo($r->original_name, PATHINFO_FILENAME).' - signé.pdf', array_filter($meta), ['signature.view', 'signature.manage']);
        } catch (\Throwable $e) {
            Log::warning('Signature : classement dans les documents impossible', ['request' => $r->id, 'error' => $e->getMessage()]);
        }
    }

    /** Devis signé : bon pour accord, commande créée (si aucune ne l'est déjà). */
    protected function afterCompleted(Company $company, SignRequest $r): void
    {
        if ($r->source_type !== 'sales_document' || ! $r->source_id || ! class_exists(\App\Modules\Ventes\Models\SalesDocument::class)) {
            return;
        }
        try {
            $q = \App\Modules\Ventes\Models\SalesDocument::find($r->source_id);
            if (! $q || $q->type !== 'quote' || $q->status !== 'validated' || \App\Modules\Ventes\Models\SalesDocument::where('source_id', $q->id)->where('status', '!=', 'cancelled')->exists()) {
                return;
            }
            $who = $r->signers->first(fn ($s) => ! $s->user_id)?->name ?? $r->signers->first()?->name;
            $order = $q->convertTo('order');
            $order->reference = $order->reference ?: 'Bon pour accord signé électroniquement — '.$who.' ('.$r->number.')';
            $order->save();
            $order->validateDocument();
            $this->log($r, 'completed', null, ['order' => $order->number]);
        } catch (\Throwable $e) {
            Log::warning('Signature : commande non créée', ['request' => $r->id, 'error' => $e->getMessage()]);
        }
    }

    public function remind(Company $company, SignRequest $r, ?Signer $only = null): int
    {
        if ($r->status !== 'pending') {
            throw new \DomainException('Seules les demandes en cours peuvent être relancées.');
        }
        $n = 0;
        foreach ($r->signers()->get() as $s) {
            if (in_array($s->status, ['pending', 'viewed'], true) && (! $only || $only->id === $s->id)) {
                $this->notify($company, $r, $s, true);
                $n++;
            }
        }
        $r->forceFill(['reminders' => min(255, $r->reminders + 1), 'reminded_at' => now()])->save();

        return $n;
    }

    public function cancel(SignRequest $r, ?string $reason): void
    {
        if (! in_array($r->status, ['draft', 'pending'], true)) {
            throw new \DomainException('Cette demande ne peut plus être annulée.');
        }
        $r->forceFill(['status' => 'cancelled'])->save();
        $this->log($r, 'cancelled', null, ['reason' => $reason]);
    }

    public function expireIfNeeded(SignRequest $r): void
    {
        if ($r->status === 'pending' && $r->expires_at && $r->expires_at->lt(today())) {
            $r->forceFill(['status' => 'expired'])->save();
            $this->log($r, 'expired');
        }
    }

    /** Tâche quotidienne : expiration et relances automatiques. */
    public function maintenance(Company $company): array
    {
        $set = $this->settings($company);
        $expired = $reminded = 0;
        foreach (SignRequest::where('status', 'pending')->get() as $r) {
            $this->expireIfNeeded($r);
            if ($r->status === 'expired') {
                $expired++;
                AppNotification::send($company->id, $this->managers($company, $r), 'signature', 'Demande de signature expirée', $r->title.' ('.$r->number.')', 'signature', $r->id);

                continue;
            }
            $days = (int) $set['reminder_days'];
            $last = $r->reminded_at ?? $r->sent_at;
            if ($days > 0 && $r->reminders < (int) $set['max_reminders'] && $last && $last->lte(now()->subDays($days))) {
                $reminded += $this->remind($company, $r);
            }
        }

        return ['expired' => $expired, 'reminded' => $reminded];
    }

    /** Vérification publique : le fichier correspond-il à un document signé ? */
    public function verify(string $bytes): ?array
    {
        $h = hash('sha256', $bytes);
        $r = SignRequest::withoutGlobalScopes()->where('signed_sha256', $h)->first();
        $kind = 'signed';
        if (! $r) {
            $r = SignRequest::withoutGlobalScopes()->where('original_sha256', $h)->whereIn('status', ['completed', 'pending'])->first();
            $kind = 'original';
        }
        if (! $r) {
            return null;
        }
        $company = Company::find($r->company_id);
        $signers = Signer::withoutGlobalScopes()->where('request_id', $r->id)->orderBy('position')->orderBy('id')->get();

        return ['kind' => $kind, 'request' => $r, 'company' => $company, 'signers' => $signers, 'sha256' => $h];
    }
}
