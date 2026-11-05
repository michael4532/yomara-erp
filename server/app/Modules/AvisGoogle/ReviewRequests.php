<?php

namespace App\Modules\AvisGoogle;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Messaging\Phone;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Demandes d'avis : envoyées à tous les clients concernés (sans tri selon leur satisfaction, conformément aux règles de
 * Google), après une vente, une facture payée, un rendez-vous honoré ou un ticket résolu, avec délai et fréquence maximale.
 */
class ReviewRequests
{
    public const TRIGGERS = ['invoice.paid' => 'Facture payée', 'receipt.created' => 'Vente en caisse (ticket)', 'appointment.completed' => 'Rendez-vous honoré', 'ticket.resolved' => 'Ticket du service client résolu'];

    public function __construct(protected GoogleProfile $gp) {}

    public function onEvent(int $companyId, string $event, Model $m): void
    {
        if (! isset(self::TRIGGERS[$event]) || ! \Illuminate\Support\Facades\Schema::hasTable('gb_settings')) {
            return;
        }
        $c = Company::find($companyId);
        if (! $c || ! $c->hasModule('avis_google')) {
            return;
        }
        $cfg = $this->gp->requestSettings($c);
        if (empty($cfg['enabled']) || ! in_array($event, (array) $cfg['triggers'], true)) {
            return;
        }
        $partnerId = $m->partner_id ?? null;
        $name = null;
        $phone = $m->contact_phone ?? ($m->phone ?? null);
        $email = $m->contact_email ?? ($m->email ?? null);
        if ($partnerId && ($p = DB::table('partners')->where('id', $partnerId)->first(['name', 'phone', 'email', 'type']))) {
            if ($p->type === 'supplier') {
                return;
            }
            $name = $p->name;
            $phone = $p->phone ?: $phone;
            $email = $p->email ?: $email;
        }
        $name ??= $m->partner_name ?? $m->contact_name ?? $m->customer_name ?? null;
        if ($name && preg_match('/^(client (divers|comptoir|de passage)|divers)$/i', trim($name))) {
            $name = null; // client anonyme de caisse
            $partnerId = null;
        }
        if (! $partnerId && ! $phone && ! $email) {
            return;
        }
        $this->create($c, ['partner_id' => $partnerId, 'name' => $name, 'phone' => $phone, 'email' => $email, 'context' => $event.':'.$m->getKey(),
            'send_at' => now()->addHours((int) $cfg['delay_hours'])]);
    }

    /** Programme une demande ; renvoie null si le client a déjà été sollicité récemment ou n'est pas joignable. */
    public function create(Company $c, array $d, bool $force = false): ?object
    {
        $cfg = $this->gp->requestSettings($c);
        $loc = DB::table('gb_locations')->where('company_id', $c->id)->when($d['location_id'] ?? null, fn ($q, $id) => $q->where('id', $id))->orderBy('id')->first();
        if (! $loc || ! $loc->review_uri) {
            if ($force) {
                throw new \DomainException('Connectez d\'abord votre fiche Google (ou choisissez votre établissement) pour obtenir le lien d\'avis.');
            }

            return null;
        }
        $available = app(MessagingService::class)->available($c);
        $channel = null;
        $to = null;
        foreach (! empty($d['channel']) ? [$d['channel']] : (array) $cfg['channels'] as $ch) {
            if (empty($available[$ch])) {
                continue;
            }
            $dest = $ch === 'email' ? (filter_var($d['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null) : Phone::e164($d['phone'] ?? null);
            if ($dest) {
                [$channel, $to] = [$ch, $dest];
                break;
            }
        }
        if (! $channel) {
            if ($force) {
                throw new \DomainException('Aucun moyen d\'envoi disponible pour ce client (WhatsApp, SMS ou e-mail configuré et coordonnée connue).');
            }

            return null;
        }
        if (! $force) {
            $recent = DB::table('gb_requests')->where('company_id', $c->id)->where('created_at', '>=', now()->subDays((int) $cfg['cooldown_days']))
                ->where(fn ($q) => $q->when($d['partner_id'] ?? null, fn ($w, $pid) => $w->where('partner_id', $pid))->orWhere('to', $to))->exists();
            if ($recent) {
                return null;
            }
        }
        $id = DB::table('gb_requests')->insertGetId(['company_id' => $c->id, 'location_id' => $loc->id, 'partner_id' => $d['partner_id'] ?? null, 'name' => isset($d['name']) ? mb_substr($d['name'], 0, 190) : null,
            'channel' => $channel, 'to' => $to, 'token' => GoogleProfile::newToken(40), 'context' => isset($d['context']) ? mb_substr($d['context'], 0, 60) : null, 'status' => 'scheduled',
            'send_at' => $d['send_at'] ?? now(), 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('gb_requests')->find($id);
        if (! $row->send_at || \Illuminate\Support\Carbon::parse($row->send_at)->lte(now())) {
            $this->send($c, $row);
        }

        return DB::table('gb_requests')->find($id);
    }

    public function send(Company $c, object $r): bool
    {
        $cfg = $this->gp->requestSettings($c);
        $link = url('/note/'.$r->token);
        $text = strtr((string) $cfg['message'], ['{nom}' => $r->name ? explode(' ', trim($r->name))[0] : '', '{societe}' => $c->name, '{lien}' => $link]);
        $text = preg_replace('/\s+,/', ',', preg_replace('/Bonjour\s+,/', 'Bonjour,', $text));
        $html = $r->channel === 'email' ? '<p>'.nl2br(e(str_replace($link, '', $text))).'</p><p style="margin:22px 0"><a href="'.e($link).'" style="background:#1A73E8;color:#fff;padding:12px 22px;border-radius:22px;text-decoration:none;font-weight:700">★ Donner mon avis</a></p>' : null;
        try {
            $res = app(MessagingService::class)->send($c, $r->channel, new Message(to: $r->to, toName: $r->name, subject: 'Votre avis compte pour '.$c->name, html: $html, text: $text), 'avis.demande');
            DB::table('gb_requests')->where('id', $r->id)->update(['status' => $res->ok ? 'sent' : 'failed', 'error' => $res->ok ? null : mb_substr((string) $res->error, 0, 300), 'sent_at' => now(), 'updated_at' => now()]);

            return $res->ok;
        } catch (\DomainException $e) {
            DB::table('gb_requests')->where('id', $r->id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'updated_at' => now()]);

            return false;
        }
    }

    public function sendDue(): int
    {
        $n = 0;
        foreach (DB::table('gb_requests')->where('status', 'scheduled')->where('send_at', '<=', now())->orderBy('send_at')->limit(100)->get() as $r) {
            $c = Company::find($r->company_id);
            if ($c && $c->hasModule('avis_google')) {
                app()->instance('tenant', $c);
                $n += $this->send($c, $r) ? 1 : 0;
            }
        }

        return $n;
    }
}
