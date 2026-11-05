<?php

namespace App\Modules\Ventes;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Notifications de commande automatiques (comme un accusé de réception Odoo) :
 * - au client : commande reçue, confirmée, annulée — par e-mail aux couleurs de la société et/ou WhatsApp, SMS ;
 *   avec un ticket du service client (référence SUP-…) quand le module Service client est actif : la réponse du
 *   client à l'e-mail arrive dans ce ticket ;
 * - à l'équipe : notification dans l'application (et e-mail aux adresses choisies) à chaque nouvelle commande.
 * Commandes concernées : celles du site (WooCommerce, Shopify, boutique en ligne : créées sans utilisateur) ou toutes.
 * Les avis d'expédition et de livraison restent envoyés par le module Livraisons.
 */
class OrderNotifier
{
    public const EVENTS = [
        'received' => 'Commande reçue',
        'confirmed' => 'Commande confirmée',
        'cancelled' => 'Commande annulée',
    ];

    public const DEFAULT_TEXTS = [
        'received' => 'Votre commande {numero} a été reçue et est en cours d\'examen par notre service client. Nous revenons vers vous très vite.',
        'confirmed' => 'Bonne nouvelle : votre commande {numero} est confirmée. Nous préparons vos articles et vous prévenons dès l\'expédition.',
        'cancelled' => 'Votre commande {numero} a été annulée. Si ce n\'est pas ce que vous souhaitiez, répondez simplement à ce message.',
    ];

    /** Envois déjà faits pendant cette requête (une commande peut être enregistrée plusieurs fois de suite). */
    protected static array $done = [];

    public function __construct(protected MessagingService $messaging) {}

    public function settings(Company $c): array
    {
        $s = (array) (($c->settings ?? [])['order_notify'] ?? []);

        return [
            'enabled' => (bool) ($s['enabled'] ?? true),
            'scope' => in_array($s['scope'] ?? 'online', ['online', 'all'], true) ? ($s['scope'] ?? 'online') : 'online',
            'events' => array_replace(['received' => true, 'confirmed' => true, 'cancelled' => true], array_map('boolval', (array) ($s['events'] ?? []))),
            'email' => (bool) ($s['email'] ?? true),
            'whatsapp' => (bool) ($s['whatsapp'] ?? true),
            'sms' => (bool) ($s['sms'] ?? false),
            'ticket' => (bool) ($s['ticket'] ?? true),
            'team' => (bool) ($s['team'] ?? true),
            'team_emails' => array_values(array_filter((array) ($s['team_emails'] ?? []), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))),
            'texts' => array_replace(self::DEFAULT_TEXTS, array_filter((array) ($s['texts'] ?? []), fn ($v) => is_string($v) && trim($v) !== '')),
            'signature' => (string) ($s['signature'] ?? ''),
        ];
    }

    public function saveSettings(Company $c, array $v): array
    {
        $cur = $this->settings($c);
        $next = [
            'enabled' => (bool) ($v['enabled'] ?? $cur['enabled']),
            'scope' => in_array($v['scope'] ?? $cur['scope'], ['online', 'all'], true) ? ($v['scope'] ?? $cur['scope']) : 'online',
            'events' => array_intersect_key(array_map('boolval', (array) ($v['events'] ?? $cur['events'])), self::EVENTS),
            'email' => (bool) ($v['email'] ?? $cur['email']),
            'whatsapp' => (bool) ($v['whatsapp'] ?? $cur['whatsapp']),
            'sms' => (bool) ($v['sms'] ?? $cur['sms']),
            'ticket' => (bool) ($v['ticket'] ?? $cur['ticket']),
            'team' => (bool) ($v['team'] ?? $cur['team']),
            'team_emails' => array_values(array_slice(array_filter(array_map('trim', (array) ($v['team_emails'] ?? $cur['team_emails'])), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)), 0, 10)),
            'texts' => array_map(fn ($t) => mb_substr(trim((string) $t), 0, 1500), array_intersect_key((array) ($v['texts'] ?? $cur['texts']), self::EVENTS)),
            'signature' => mb_substr(trim((string) ($v['signature'] ?? $cur['signature'])), 0, 500),
        ];
        $s = (array) ($c->settings ?? []);
        $s['order_notify'] = $next;
        $c->forceFill(['settings' => $s])->save();

        return $this->settings($c->fresh());
    }

    /** Écouteur des événements métier (voir App\Integrations\Events). */
    public function onEvent(int $companyId, string $event, \Illuminate\Database\Eloquent\Model $m): void
    {
        $kind = ['order.created' => 'received', 'order.validated' => 'confirmed', 'order.cancelled' => 'cancelled'][$event] ?? null;
        if (! $kind || ! $m instanceof SalesDocument || $m->type !== 'order' || ! Schema::hasTable('order_notices')) {
            return;
        }
        $c = Company::find($companyId);
        if (! $c || ! $c->hasModule('ventes')) {
            return;
        }
        $set = $this->settings($c);
        if (! $set['enabled'] || empty($set['events'][$kind]) || ($set['scope'] === 'online' && $m->user_id)) {
            return;
        }
        // L'événement part à l'enregistrement de l'en-tête, avant les lignes : l'envoi attend la fin de la requête
        // (ou de la tâche) pour relire la commande complète.
        // commande créée et confirmée dans la même opération (site, import) : un seul message, l'accusé de réception
        if ($kind === 'confirmed' && isset(self::$pending[$m->id.'|received'])) {
            return;
        }
        self::$pending[$m->id.'|'.$kind] = [$companyId, $m->id, $kind];
        if (! self::$hooked) {
            self::$hooked = true;
            app()->terminating(fn () => $this->flush());
            \Illuminate\Support\Facades\Queue::after(fn () => $this->flush());
        }
    }

    /** @var array<string, array{int, string, string}> notifications en attente de la fin de la requête */
    protected static array $pending = [];

    protected static bool $hooked = false;

    /** Envoie les notifications en attente (fin de requête HTTP, de commande ou de tâche de file). */
    public function flush(): void
    {
        while ($job = array_shift(self::$pending)) {
            [$companyId, $id, $kind] = $job;
            try {
                $c = Company::find($companyId);
                $doc = SalesDocument::withoutGlobalScopes()->where('company_id', $companyId)->find($id);
                if (! $c || ! $doc) {
                    continue;
                }
                if ($kind !== 'cancelled' && $doc->status === 'cancelled') {
                    continue;
                }
                $this->notify($c, $doc, $kind);
            } catch (\Throwable $e) {
                Log::warning('Notification de commande : '.$e->getMessage());
            }
        }
    }

    public function notify(Company $c, SalesDocument $doc, string $kind, ?array $set = null, bool $force = false): array
    {
        $set ??= $this->settings($c);
        $key = $doc->id.'|'.$kind;
        if (! $force && (isset(self::$done[$key]) || DB::table('order_notices')->where('document_id', $doc->id)->where('event', $kind)->exists())) {
            return ['sent' => [], 'skipped' => 'déjà envoyé'];
        }
        self::$done[$key] = true;
        $prev = app()->bound('tenant') ? app('tenant') : null;
        app()->instance('tenant', $c);
        try {
            $partner = $doc->partner_id ? Partner::withoutGlobalScopes()->find($doc->partner_id) : null;
            $email = filter_var($partner?->email, FILTER_VALIDATE_EMAIL) ?: null;
            $phone = $partner?->phone ?: null;
            $name = trim((string) ($partner?->name ?: $doc->partner_name ?: ''));
            $ticket = ($set['ticket'] && $c->hasModule('helpdesk') && ($email || $phone)) ? $this->ticketFor($c, $doc, $name, $email, $phone, $kind) : null;
            $sent = [];
            $vars = $this->vars($c, $doc, $name);
            $text = strtr($set['texts'][$kind] ?? self::DEFAULT_TEXTS[$kind], $vars);
            $avail = $this->messaging->available($c);
            if ($set['email'] && $email && ($avail['email'] ?? null)) {
                $subject = self::EVENTS[$kind].' N° '.$vars['{numero}'].($ticket ? ' ['.$ticket->number.']' : '').' — '.$c->name;
                $headers = $ticket ? ['References' => '<ticket-'.$ticket->id.'@yomara.erp>', 'X-Yomara-Ticket' => (string) $ticket->number] : [];
                $replyTo = $ticket ? (\App\Modules\Helpdesk\Inbound\Mailbox::where('company_id', $c->id)->value('address') ?: $c->email) : $c->email;
                try {
                    $r = $this->messaging->send($c, 'email', new Message(to: $email, toName: $name, subject: $subject, html: $this->html($c, $doc, $kind, $text, $vars, $ticket, $set),
                        text: $text.($ticket ? "\n\nRéférence de votre demande : ".$ticket->number."\n".$ticket->publicUrl() : ''), headers: $headers, replyTo: $replyTo ?: null), 'order:'.$doc->id);
                    if ($r->ok) {
                        $sent[] = 'email';
                    }
                } catch (\Throwable $e) {
                    Log::info('Notification de commande (e-mail) : '.$e->getMessage());
                }
            }
            if ($phone && ($set['whatsapp'] || $set['sms'])) {
                $short = 'Bonjour'.($name ? ' '.Str::before($name, ' ') : '').', '.$text.($ticket ? ' Réf. '.$ticket->number.' : '.$ticket->publicUrl() : '').' — '.$c->name;
                foreach (['whatsapp', 'sms'] as $ch) {
                    if ($set[$ch] && ($avail[$ch] ?? null)) {
                        try {
                            if ($this->messaging->send($c, $ch, new Message(to: $phone, toName: $name, text: $short), 'order:'.$doc->id)->ok) {
                                $sent[] = $ch;
                                break; // WhatsApp d'abord, le SMS seulement s'il n'est pas disponible
                            }
                        } catch (\Throwable $e) {
                            Log::info('Notification de commande ('.$ch.') : '.$e->getMessage());
                        }
                    }
                }
            }
            if ($kind === 'received' && $set['team']) {
                $this->alertTeam($c, $doc, $name, $vars, $set, $ticket);
            }
            DB::table('order_notices')->updateOrInsert(['document_id' => $doc->id, 'event' => $kind], [
                'company_id' => $c->id, 'channels' => implode(',', $sent), 'ticket_id' => $ticket?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['sent' => $sent, 'ticket' => $ticket?->number];
        } finally {
            $prev ? app()->instance('tenant', $prev) : app()->forgetInstance('tenant');
        }
    }

    public function vars(Company $c, SalesDocument $doc, string $name): array
    {
        $cur = $doc->currency === 'XOF' || ! $doc->currency ? 'F CFA' : $doc->currency;

        return [
            '{numero}' => (string) ($doc->reference ?: $doc->number),
            '{client}' => $name,
            '{entreprise}' => $c->name,
            '{total}' => number_format((float) $doc->total, 0, ',', ' ').' '.$cur,
            '{date}' => optional($doc->date)->format('d/m/Y') ?? now()->format('d/m/Y'),
        ];
    }

    /** Un ticket par commande : la réponse du client à l'e-mail y arrive (référence SUP-… dans l'objet). */
    protected function ticketFor(Company $c, SalesDocument $doc, string $name, ?string $email, ?string $phone, string $kind): ?\App\Modules\Helpdesk\Models\Ticket
    {
        $existing = DB::table('order_notices')->where('document_id', $doc->id)->whereNotNull('ticket_id')->value('ticket_id');
        if ($existing && ($t = \App\Modules\Helpdesk\Models\Ticket::withoutGlobalScopes()->find($existing))) {
            return $t;
        }
        if ($kind !== 'received') {
            return null;
        }
        $id = (string) Str::uuid();
        $T = \App\Modules\Helpdesk\Models\Ticket::class;
        $T::$systemWrite = true;
        \App\Modules\Helpdesk\HelpdeskService::$silentAck = true;
        try {
            $res = app(\App\Services\EntityWriter::class)->upsert($T, $id, array_filter([
                'subject' => 'Commande '.($doc->reference ?: $doc->number),
                'description' => 'Commande '.($doc->reference ?: $doc->number).' ('.$doc->number.') du '.optional($doc->date)->format('d/m/Y').', '.number_format((float) $doc->total, 0, ',', ' ').' '.($doc->currency ?: 'XOF')
                    ."\n\n".mb_substr((string) $doc->notes, 0, 1500),
                'contact_name' => $name ?: null, 'contact_email' => $email, 'contact_phone' => $phone, 'partner_id' => $doc->partner_id,
                'channel' => 'web', 'priority' => 'normal',
            ], fn ($v) => $v !== null && $v !== ''));
        } catch (\Throwable $e) {
            Log::info('Ticket de commande non créé : '.$e->getMessage());

            return null;
        } finally {
            $T::$systemWrite = false;
            \App\Modules\Helpdesk\HelpdeskService::$silentAck = false;
        }

        return ($res['status'] ?? null) === \App\Services\EntityWriter::APPLIED ? $T::withoutGlobalScopes()->find($id) : null;
    }

    protected function alertTeam(Company $c, SalesDocument $doc, string $name, array $vars, array $set, $ticket): void
    {
        $users = User::withoutGlobalScopes()->where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('ventes.manage'));
        $title = 'Nouvelle commande '.$vars['{numero}'];
        $body = ($name ?: 'Un client').' · '.$vars['{total}'].($ticket ? ' · ticket '.$ticket->number : '');
        rescue(fn () => AppNotification::send($c->id, $users->pluck('id'), 'order', $title, $body, 'ventes', (string) $doc->id), null, false);
        if ($set['team_emails'] && ($this->messaging->available($c)['email'] ?? null)) {
            $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto"><h2 style="margin:0 0 8px">'.e($title).'</h2><p>'.e($body).'</p>'
                .$this->linesTable($doc).'<p><a href="'.e(url('/app/')).'">Ouvrir '.e(config('erp.name', 'Yomara ERP')).'</a></p></div>';
            foreach ($set['team_emails'] as $to) {
                rescue(fn () => $this->messaging->send($c, 'email', new Message(to: $to, subject: $title.' — '.$vars['{total}'], html: $html, text: $title."\n".$body), 'order:'.$doc->id), null, false);
            }
        }
    }

    protected function linesTable(SalesDocument $doc): string
    {
        $rows = '';
        foreach (DB::table('trade_document_lines')->where('document_id', $doc->id)->orderBy('sort')->limit(40)->get() as $l) {
            $rows .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #EEF1F6">'.e(Str::limit((string) $l->description, 90)).'</td>'
                .'<td style="padding:8px 10px;border-bottom:1px solid #EEF1F6;text-align:center">'.e(rtrim(rtrim(number_format((float) $l->quantity, 2, ',', ' '), '0'), ',')).'</td>'
                .'<td style="padding:8px 10px;border-bottom:1px solid #EEF1F6;text-align:right;white-space:nowrap">'.e(number_format((float) ($l->total ?? $l->quantity * $l->unit_price), 0, ',', ' ')).'</td></tr>';
        }
        if ($rows === '') {
            return '';
        }

        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;margin:14px 0">'
            .'<tr style="background:#F5F7FB;color:#5B6B85;font-size:12px"><td style="padding:8px 10px">Article</td><td style="padding:8px 10px;text-align:center">Qté</td><td style="padding:8px 10px;text-align:right">Montant</td></tr>'
            .$rows.'<tr><td colspan="2" style="padding:10px;font-weight:bold">Total</td><td style="padding:10px;text-align:right;font-weight:bold;white-space:nowrap">'.e(number_format((float) $doc->total, 0, ',', ' ').' '.($doc->currency === 'XOF' || ! $doc->currency ? 'F CFA' : $doc->currency)).'</td></tr></table>';
    }

    public function html(Company $c, SalesDocument $doc, string $kind, string $text, array $vars, $ticket, array $set): string
    {
        $logo = $c->logo && (str_starts_with($c->logo, 'http') || str_starts_with($c->logo, 'data:image')) ? '<img src="'.e($c->logo).'" alt="" style="max-height:46px;max-width:180px">' : '<b style="font-size:20px">'.e($c->name).'</b>';
        $color = ['received' => '#1F6FEB', 'confirmed' => '#12B76A', 'cancelled' => '#E5484D'][$kind];
        $contact = implode(' | ', array_filter([$c->phone, $c->email, $c->address]));

        return '<!doctype html><html lang="fr"><body style="margin:0;background:#F1F4FA;font-family:Segoe UI,Roboto,Arial,sans-serif;color:#0B1530">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#fff;border-radius:14px;overflow:hidden">'
            .'<tr><td style="padding:22px 28px;border-bottom:3px solid '.$color.'"><table width="100%" role="presentation"><tr>'
            .'<td style="vertical-align:middle"><div style="font-size:12px;color:#5B6B85">'.($ticket ? 'Votre demande au service client' : 'Votre commande').'</div>'
            .'<div style="font-size:19px;font-weight:800;color:'.$color.';margin-top:4px">'.e(self::EVENTS[$kind]).' N° '.e($vars['{numero}']).($ticket ? ' <span style="color:#5B6B85;font-weight:600">(#'.e($ticket->number).')</span>' : '').'</div></td>'
            .'<td style="text-align:right;vertical-align:middle">'.$logo.'</td></tr></table></td></tr>'
            .'<tr><td style="padding:22px 28px;font-size:15px;line-height:1.6">'
            .($ticket ? '<p style="color:#94A3B8;font-size:11px;margin:0 0 12px">'.e(\App\Modules\Helpdesk\HelpdeskService::REPLY_MARKER).'</p>' : '')
            .'<p style="margin:0 0 12px">Bonjour'.($vars['{client}'] ? ' '.e($vars['{client}']) : '').',</p>'
            .'<p style="margin:0 0 12px">'.nl2br(e($text)).'</p>'
            .($ticket ? '<p style="margin:0 0 12px">La référence de votre demande est <b>'.e($ticket->number).'</b>. Pour toute information complémentaire, répondez simplement à cet e-mail.</p>' : '<p style="margin:0 0 12px">Pour toute question, répondez simplement à cet e-mail.</p>')
            .($kind !== 'cancelled' ? $this->linesTable($doc) : '')
            .($ticket ? '<p style="margin:18px 0 6px"><a href="'.e($ticket->publicUrl()).'" style="display:inline-block;background:'.$color.';color:#fff;text-decoration:none;font-weight:700;padding:11px 20px;border-radius:8px">Suivre ma demande</a></p>' : '')
            .($set['signature'] ? '<p style="margin:16px 0 0;white-space:pre-line">'.e($set['signature']).'</p>' : '')
            .'</td></tr>'
            .'<tr><td style="padding:16px 28px;border-top:1px solid #EEF1F6;font-size:12px;color:#5B6B85;text-align:center"><b>'.e($c->name).'</b>'.($contact ? '<br>'.e($contact) : '').'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }
}
