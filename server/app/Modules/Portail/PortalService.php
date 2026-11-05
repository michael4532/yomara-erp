<?php

namespace App\Modules\Portail;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Portail\Models\PortalAccess;
use App\Modules\Portail\Models\PortalEvent;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Str;

class PortalService
{
    public function __construct(protected MessagingService $messaging) {}

    public function access(Partner $partner): PortalAccess
    {
        return PortalAccess::firstOrCreate(['partner_id' => $partner->id]);
    }

    /** Canaux par lesquels on peut envoyer le lien à ce client. */
    public function channels(Company $company, Partner $partner): array
    {
        $available = $this->messaging->available($company);

        return array_values(array_filter([
            $partner->email && ($available['email'] ?? null) ? 'email' : null,
            $partner->phone && ($available['whatsapp'] ?? null) ? 'whatsapp' : null,
            $partner->phone && ($available['sms'] ?? null) ? 'sms' : null,
        ]));
    }

    /** Envoie le lien d'accès (ou le renvoie). Retourne le canal utilisé, null si rien n'a été envoyé. */
    public function invite(Company $company, Partner $partner, ?string $channel = null, bool $reminder = false): array
    {
        $access = $this->access($partner);
        if (! $access->active) {
            $access->forceFill(['active' => true])->save();
        }
        $channels = $this->channels($company, $partner);
        $channel = $channel && in_array($channel, $channels, true) ? $channel : ($channel === 'none' ? null : ($channels[0] ?? null));
        $url = $access->url();
        $sent = null;
        if ($channel) {
            $text = $reminder
                ? "Bonjour {$partner->name}, voici votre lien d'accès à votre espace client {$company->name} :\n$url"
                : "Bonjour {$partner->name}, {$company->name} vous ouvre votre espace client : factures, paiement en ligne, devis, commandes et livraisons, au même endroit.\n$url";
            $message = $channel === 'email'
                ? new Message(to: $partner->email, toName: $partner->name, subject: 'Votre espace client '.$company->name, html: $this->emailHtml($company, $partner, $url, $reminder), text: $text, replyTo: $company->email)
                : new Message(to: $partner->phone, toName: $partner->name, text: $text);
            try {
                $this->messaging->send($company, $channel, $message, 'portal:'.$partner->id);
                $sent = $channel;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if (! $reminder) {
            $access->forceFill(['invited_at' => now(), 'invited_via' => $sent])->save();
            $this->log($partner->id, 'invite', null, ['via' => $sent], auth()->id());
        }

        return ['access' => $access, 'url' => $url, 'sent_via' => $sent];
    }

    protected function emailHtml(Company $company, Partner $partner, string $url, bool $reminder): string
    {
        $name = e($partner->name);
        $co = e($company->name);
        $intro = $reminder ? 'Voici votre lien d\'accès à votre espace client.' : "$co vous ouvre votre espace client : consultez et payez vos factures, acceptez vos devis en ligne, suivez vos commandes et livraisons.";

        return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#0B1B3A">
  <h2 style="margin:0 0 12px">Bonjour $name,</h2>
  <p style="line-height:1.5">$intro</p>
  <p style="margin:24px 0"><a href="$url" style="background:#1F6FEB;color:#fff;padding:14px 22px;border-radius:10px;text-decoration:none;font-weight:bold">Ouvrir mon espace client</a></p>
  <p style="color:#5B6B85;font-size:13px">Aucun mot de passe n'est nécessaire : ce lien vous est personnel, ne le transférez pas.</p>
  <p style="color:#5B6B85;font-size:13px">$co</p>
</div>
HTML;
    }

    /** @return array{0: Company, 1: PortalAccess, 2: Partner} */
    public function resolve(string $token): array
    {
        abort_unless(strlen($token) >= 32, 404);
        $access = PortalAccess::withoutGlobalScopes()->where('token', $token)->where('active', true)->first();
        abort_unless($access, 404);
        $company = Company::find($access->company_id);
        abort_unless($company && $company->hasModule('portail'), 404);
        app()->instance('tenant', $company);
        $partner = Partner::find($access->partner_id);
        abort_unless($partner, 404);

        return [$company, $access, $partner];
    }

    public function log(string $partnerId, string $type, ?string $documentId = null, ?array $data = null, ?int $userId = null): PortalEvent
    {
        return PortalEvent::create(['partner_id' => $partnerId, 'type' => $type, 'document_id' => $documentId, 'data' => $data, 'user_id' => $userId,
            'ip' => $userId ? null : request()?->ip(), 'read_at' => $userId || in_array($type, ['login', 'view', 'invite'], true) ? now() : null]);
    }

    /** Documents visibles par le client : validés, à son nom. */
    public function documents(Partner $partner, ?array $types = null)
    {
        return SalesDocument::where('partner_id', $partner->id)->where('status', 'validated')
            ->whereIn('type', $types ?? ['quote', 'order', 'invoice', 'credit_note'])
            ->orderByDesc('date')->orderByDesc('number');
    }

    /** Statut d'un devis vu par le client. */
    public function quoteState(SalesDocument $q): string
    {
        if (SalesDocument::where('source_id', $q->id)->where('status', '!=', 'cancelled')->exists()) {
            return 'accepted';
        }
        $last = PortalEvent::where('document_id', $q->id)->whereIn('type', ['quote_accepted', 'quote_refused'])->latest('id')->first();
        if ($last?->type === 'quote_refused') {
            return 'refused';
        }
        if ($last?->type === 'quote_accepted') {
            return 'accepted';
        }
        if ($q->due_date && $q->due_date->lt(today())) {
            return 'expired';
        }

        return 'pending';
    }

    /** Acceptation en ligne : bon pour accord (nom, date, IP), puis commande confirmée. */
    public function acceptQuote(Company $company, Partner $partner, SalesDocument $q, string $signer): ?SalesDocument
    {
        if ($this->quoteState($q) !== 'pending') {
            throw new \DomainException('Ce devis ne peut plus être accepté en ligne.');
        }
        $order = null;
        if ($company->setting('portal_quote_to_order', true)) {
            $order = $q->convertTo('order');
            $order->reference = $order->reference ?: 'Bon pour accord en ligne — '.$signer;
            $order->save();
            $order->validateDocument();
        }
        $this->log($partner->id, 'quote_accepted', $q->id, ['signer' => $signer, 'order_id' => $order?->id, 'order_number' => $order?->number, 'total' => $q->total]);
        $this->notifyStaff($company, 'Devis accepté en ligne : '.$q->number,
            $partner->name.' a accepté le devis '.$q->number.' ('.number_format($q->total, 0, ',', ' ').' '.$q->currency.')'.($order ? ' — commande '.$order->number.' créée.' : '.'),
            $order?->id ?? $q->id);

        return $order;
    }

    public function refuseQuote(Company $company, Partner $partner, SalesDocument $q, ?string $reason): void
    {
        if ($this->quoteState($q) !== 'pending') {
            throw new \DomainException('Ce devis a déjà reçu une réponse.');
        }
        $this->log($partner->id, 'quote_refused', $q->id, ['reason' => $reason]);
        $this->notifyStaff($company, 'Devis refusé : '.$q->number, $partner->name.($reason ? ' : '.$reason : ' a décliné le devis.'), $q->id);
    }

    public function message(Company $company, Partner $partner, string $subject, string $body, ?string $documentId = null): PortalEvent
    {
        $e = $this->log($partner->id, 'message', $documentId, ['subject' => $subject, 'body' => $body]);
        $this->notifyStaff($company, 'Message de '.$partner->name.' : '.$subject, Str::limit($body, 160), $partner->id, 'portail');

        return $e;
    }

    /** Réponse de l'équipe : enregistrée, visible dans le portail et envoyée au client. */
    public function reply(Company $company, Partner $partner, string $body, User $user): PortalEvent
    {
        $e = $this->log($partner->id, 'reply', null, ['body' => $body, 'author' => $user->name], $user->id);
        PortalEvent::where('partner_id', $partner->id)->where('type', 'message')->whereNull('read_at')->update(['read_at' => now()]);
        $access = $this->access($partner);
        $channels = $this->channels($company, $partner);
        $text = "{$company->name} vous a répondu :\n\n$body\n\nVotre espace client : ".$access->url('messages');
        try {
            if (in_array('email', $channels, true)) {
                $this->messaging->send($company, 'email', new Message(to: $partner->email, toName: $partner->name, subject: 'Réponse de '.$company->name,
                    html: nl2br(e($text)), text: $text, replyTo: $company->email), 'portal:'.$partner->id);
            } elseif ($channels) {
                $this->messaging->send($company, $channels[0], new Message(to: $partner->phone, toName: $partner->name, text: $text), 'portal:'.$partner->id);
            }
        } catch (\Throwable $ex) {
            report($ex);
        }

        return $e;
    }

    public function notifyStaff(Company $company, string $title, string $body, ?string $ref, string $module = 'ventes'): void
    {
        $users = User::where('company_id', $company->id)->where('is_active', true)->get()
            ->filter(fn ($u) => $u->hasPermission('portail.manage') || $u->hasPermission('ventes.manage'))->pluck('id');
        AppNotification::send($company->id, $users, 'portal', $title, $body, $module, $ref);
    }
}
