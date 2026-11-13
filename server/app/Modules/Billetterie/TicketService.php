<?php

namespace App\Modules\Billetterie;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Modules\Billetterie\Models\Checkin;
use App\Modules\Billetterie\Models\Ticket;
use App\Modules\Billetterie\Models\TicketOrder;
use App\Modules\Billetterie\Models\TicketType;
use App\Modules\Evenements\Models\Event;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use App\Payments\InvoicePayments;
use App\Services\EntityWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TicketService
{
    /** Émission des billets d'une commande (quantités déjà réservées par la commande). */
    public static bool $issuing = false;

    // ------------------------------------------------------------------ contrôle d'accès

    public static function normalizeCode(string $code): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        return strlen($c) === 8 ? substr($c, 0, 4).'-'.substr($c, 4) : $c;
    }

    public function find(string $code): ?Ticket
    {
        $raw = trim($code);
        if (preg_match('#/billet/([A-Za-z0-9]{24,64})#', $raw, $m)) {
            $raw = $m[1];
        }

        return Ticket::where('token', $raw)->first() ?? Ticket::where('code', self::normalizeCode($raw))->first();
    }

    /** @return array{0: ?Ticket, 1: string, 2: ?string} [billet, ok|duplicate|refused, motif] */
    public function evaluate(string $eventId, string $code, ?Carbon $at = null): array
    {
        $at ??= now();
        $t = $this->find($code);
        if (! $t) {
            return [null, 'refused', 'Billet inconnu'];
        }
        if ($t->event_id !== $eventId) {
            return [$t, 'refused', 'Billet d\'un autre événement'];
        }
        if ($t->status !== 'valid') {
            return [$t, 'refused', 'Billet annulé'];
        }
        $day = $at->toDateString();
        if (($t->valid_from && $day < $t->valid_from->toDateString()) || ($t->valid_to && $day > $t->valid_to->toDateString())) {
            return [$t, 'refused', 'Valable du '.$t->valid_from?->format('d/m/Y').' au '.$t->valid_to?->format('d/m/Y')];
        }
        if (! $t->reentry) {
            $prev = Checkin::where('ticket_id', $t->id)->where('result', 'ok')->whereDate('scanned_at', $day)->orderBy('scanned_at')->first();
            if ($prev) {
                return [$t, 'duplicate', 'Déjà entré aujourd\'hui à '.$prev->scanned_at->timezone(config('app.timezone'))->format('H:i')];
            }
        }

        return [$t, 'ok', null];
    }

    public function scan(string $eventId, string $code, ?string $gate = null): array
    {
        $id = (string) Str::uuid();
        $res = app(EntityWriter::class)->upsert(Checkin::class, $id, ['event_id' => $eventId, 'code' => mb_substr($code, 0, 80), 'gate' => $gate]);
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException($res['message'] ?? 'Contrôle impossible.');
        }
        $c = Checkin::find($id);
        $t = $c->ticket_id ? Ticket::find($c->ticket_id) : null;

        return ['checkin' => $c->toSyncArray(), 'ticket' => $t?->toSyncArray(), 'type' => $t?->type_id ? TicketType::find($t->type_id)?->name : null];
    }

    // ------------------------------------------------------------------ commandes

    public function afterOrderWrite(TicketOrder $o, array $clientTickets, bool $creating): void
    {
        $event = Event::find($o->event_id);
        if ($creating && $o->channel === 'online' && $o->status === 'pending' && $o->total > 0) {
            $o->forceFill(['invoice_id' => $this->invoice($o, $event)])->save();
        }
        if ($o->status === 'paid' && ! Ticket::where('order_id', $o->id)->exists()) {
            if (! $o->invoice_id && $o->total > 0) {
                $id = $this->invoice($o, $event);
                $this->recordPayment(SalesDocument::find($id), (string) $o->payment_method);
                $o->invoice_id = $id;
            }
            $o->paid_at ??= now();
            $o->save();
            $this->issue($o, $clientTickets);
            $reg = app(RegistrationService::class);
            rescue(fn () => $reg->capture($o), null, false); // contact versé dans la liste de l'événement (Marketing)
            if ($o->channel === 'online' || ($o->buyer_email && in_array($o->channel, ['box_office', 'kiosk'], true) && (float) $o->total <= 0)) {
                $reg->confirm(app('tenant'), $o);
            }
        }
        if ($o->status === 'cancelled') {
            foreach (Ticket::where('order_id', $o->id)->where('status', 'valid')->get() as $t) {
                $t->status = 'cancelled';
                $t->save();
            }
            $doc = $o->invoice_id ? SalesDocument::find($o->invoice_id) : null;
            if ($doc && $doc->status === 'validated' && (float) $doc->amount_paid == 0.0) {
                SalesDocument::$systemWrite = true;
                try {
                    app(EntityWriter::class)->upsert(SalesDocument::class, $doc->id, ['status' => 'cancelled']);
                } finally {
                    SalesDocument::$systemWrite = false;
                }
            }
        }
    }

    protected function invoice(TicketOrder $o, Event $e): string
    {
        $rate = (float) $e->tax_rate;
        $lines = [];
        foreach ($o->items as $it) {
            if ((float) $it['unit_price'] <= 0) {
                continue;
            }
            $lines[] = ['id' => (string) Str::uuid(), 'description' => 'Billet « '.$it['name'].' » — '.$e->name, 'quantity' => $it['quantity'], 'unit' => 'billet',
                'unit_price' => round((float) $it['unit_price'] / (1 + $rate / 100), 2), 'tax_rate' => $rate, 'discount_percent' => 0];
        }
        $id = (string) Str::uuid();
        $data = ['type' => 'invoice', 'partner_id' => $o->partner_id, 'partner_name' => $o->buyer_name, 'date' => now()->toDateString(), 'status' => 'validated',
            'reference' => $o->number, 'currency' => $e->currency ?: app('tenant')->currency, 'lines' => $lines,
            'notes' => 'Billetterie '.$e->name.' — commande '.$o->number.($o->channel === 'online' ? ' (en ligne)' : ' (guichet)')];
        SalesDocument::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(SalesDocument::class, $id, $data);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Facture de la commande refusée : '.($res['message'] ?? ''));
        }

        return $id;
    }

    protected function recordPayment(SalesDocument $doc, string $method): void
    {
        $p = new SalesPayment(['document_id' => $doc->id, 'date' => now()->toDateString(), 'amount' => (float) $doc->total,
            'method' => in_array($method, ['cash', 'mobile_money', 'card', 'bank_transfer', 'cheque', 'other'], true) ? $method : 'cash']);
        $p->partner_id = $doc->partner_id;
        $p->save();
        $doc->refreshPaymentStatus();
    }

    /** Billets nominatifs de la commande ; ceux préparés par le guichet gardent leur code imprimé. */
    public function issue(TicketOrder $o, array $clientTickets = []): array
    {
        $pool = collect($clientTickets);
        $out = [];
        self::$issuing = true;
        try {
            foreach ($o->items as $it) {
                for ($i = 0; $i < (int) $it['quantity']; $i++) {
                    $pre = $pool->first(fn ($t) => $t['type_id'] === $it['type_id']);
                    if ($pre) {
                        $pool = $pool->reject(fn ($t) => $t['id'] === $pre['id']);
                    }
                    $id = $pre['id'] ?? (string) Str::uuid();
                    // Numéro déjà pris (deux bornes réglées sur le même numéro, hors ligne) : l'inscription n'est pas perdue,
                    // le billet reçoit un autre code ; le visiteur reste retrouvé par son téléphone ou son QR code.
                    if (! empty($pre['code']) && Ticket::withoutGlobalScopes()->where('company_id', $o->company_id)->where('code', strtoupper(trim($pre['code'])))->where('id', '!=', $id)->exists()) {
                        $pre['code'] = null;
                        $pre['token'] = null;
                    }
                    $res = app(EntityWriter::class)->upsert(Ticket::class, $id, array_filter([
                        'event_id' => $o->event_id, 'type_id' => $it['type_id'], 'holder_name' => ($pre['holder_name'] ?? null) ?: $o->buyer_name,
                        'holder_phone' => $o->buyer_phone, 'holder_email' => $o->buyer_email, 'company_name' => $o->buyer_company, 'code' => $pre['code'] ?? null, 'token' => $pre['token'] ?? null,
                    ], fn ($v) => $v !== null && $v !== ''));
                    if ($res['status'] !== EntityWriter::APPLIED) {
                        throw new \DomainException('Billet refusé : '.($res['message'] ?? ''));
                    }
                    $t = Ticket::find($id);
                    $t->forceFill(['order_id' => $o->id, 'price' => (float) $it['unit_price']])->save();
                    $out[] = $t;
                }
            }
        } finally {
            self::$issuing = false;
        }

        return $out;
    }

    /** Facture payée en ligne : commande payée, billets émis et envoyés. */
    public function onInvoicePaid(SalesDocument $doc): void
    {
        foreach (TicketOrder::where('invoice_id', $doc->id)->where('status', 'pending')->get() as $o) {
            $o->status = 'paid';
            $o->payment_method = 'online';
            $o->paid_at = now();
            $o->save();
            $this->afterOrderWrite($o->fresh(), [], false);
        }
    }

    public function sendTickets(Company $company, TicketOrder $o): bool
    {
        if (Ticket::where('order_id', $o->id)->exists()) {
            return app(RegistrationService::class)->confirm($company, $o); // e-mail complet : QR codes en PDF, agenda
        }
        $e = Event::find($o->event_id);
        $n = Ticket::where('order_id', $o->id)->count();
        $text = "Bonjour {$o->buyer_name},\n\nMerci pour votre commande {$o->number} : {$n} billet(s) pour « {$e->name} » ("
            .$e->start_date->format('d/m/Y').($e->venue ? ', '.$e->venue : '').").\n\nVos billets avec leur QR code : ".$o->publicUrl()."\nPrésentez le QR code à l'entrée (sur votre téléphone ou imprimé).\n\n{$company->name}";
        $messaging = app(MessagingService::class);
        $avail = $messaging->available($company);
        $sent = false;
        if ($o->buyer_email && ($avail['email'] ?? null)) {
            $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;line-height:1.5">'.nl2br(e($text)).'<p><a href="'.e($o->publicUrl()).'" style="display:inline-block;background:#1f6feb;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">Voir mes billets</a></p></div>';
            try {
                $sent = $messaging->send($company, 'email', new Message(to: $o->buyer_email, subject: 'Vos billets — '.$e->name, html: $html, text: $text, toName: $o->buyer_name), 'billetterie')->ok;
            } catch (\Throwable) {
            }
        }
        if ($o->buyer_phone && ($avail['whatsapp'] ?? null)) {
            try {
                $sent = $messaging->send($company, 'whatsapp', new Message(to: $o->buyer_phone, text: $text, toName: $o->buyer_name), 'billetterie')->ok || $sent;
            } catch (\Throwable) {
            }
        }

        return $sent;
    }

    public function payUrl(TicketOrder $o): ?string
    {
        $doc = $o->invoice_id ? SalesDocument::find($o->invoice_id) : null;

        return $doc ? InvoicePayments::url(app(InvoicePayments::class)->link($doc)) : null;
    }

    public static function qrSvg(string $data, int $size = 6): string
    {
        $svg = (new \TCPDF2DBarcode($data, 'QRCODE,M'))->getBarcodeSVGcode($size, $size, '#0B1B3A');

        return preg_replace('/^<\?xml.*?\?>\s*(<!DOCTYPE[^>]*>\s*)?/s', '', $svg);
    }

    // ------------------------------------------------------------------ badges exposants

    /** Badges nominatifs d'une réservation (un par nom). */
    public function exhibitorBadges(\App\Modules\Evenements\Models\Booking $b, array $names): array
    {
        if ($b->status !== 'confirmed') {
            throw new \DomainException('Badges réservés aux exposants confirmés.');
        }
        $type = TicketType::where('event_id', $b->event_id)->where('kind', 'exhibitor')->where('active', true)->orderBy('sort')->first();
        $out = [];
        foreach (array_slice(array_values(array_filter(array_map('trim', $names))), 0, 50) as $name) {
            $id = (string) Str::uuid();
            self::$issuing = true;
            try {
                $res = app(EntityWriter::class)->upsert(Ticket::class, $id, ['event_id' => $b->event_id, 'type_id' => $type?->id, 'kind' => 'exhibitor', 'booking_id' => $b->id, 'holder_name' => $name]);
            } finally {
                self::$issuing = false;
            }
            if ($res['status'] !== EntityWriter::APPLIED) {
                throw new \DomainException($res['message'] ?? 'Badge refusé.');
            }
            $out[] = Ticket::find($id);
        }

        return $out;
    }

    // ------------------------------------------------------------------ statistiques

    public function stats(Event $e): array
    {
        $tickets = Ticket::where('event_id', $e->id)->where('status', 'valid')->get();
        $today = now()->toDateString();
        $checks = Checkin::where('event_id', $e->id)->get();
        $byType = [];
        foreach (TicketType::where('event_id', $e->id)->orderBy('sort')->get() as $t) {
            $set = $tickets->where('type_id', $t->id);
            $byType[] = ['id' => $t->id, 'name' => $t->name, 'kind' => $t->kind, 'quantity' => $t->quantity, 'sold' => $set->count(), 'revenue' => round($set->sum('price'), 2),
                'checked_in' => $set->whereNotNull('checked_in_at')->count()];
        }
        $hours = [];
        foreach ($checks->where('result', 'ok')->filter(fn ($c) => $c->scanned_at->toDateString() === $today) as $c) {
            $h = (int) $c->scanned_at->format('G');
            $hours[$h] = ($hours[$h] ?? 0) + 1;
        }
        ksort($hours);
        $orders = TicketOrder::where('event_id', $e->id)->where('status', 'paid')->get();

        return [
            'tickets' => $tickets->whereIn('kind', ['visitor', 'vip'])->count(), 'badges' => $tickets->whereIn('kind', ['exhibitor', 'staff', 'press'])->count(),
            'revenue' => round($orders->sum('total'), 2), 'online_revenue' => round($orders->where('channel', 'online')->sum('total'), 2),
            'checked_in' => $tickets->whereNotNull('checked_in_at')->count(),
            'entries_today' => $checks->where('result', 'ok')->filter(fn ($c) => $c->scanned_at->toDateString() === $today)->count(),
            'refused_today' => $checks->where('result', '<>', 'ok')->filter(fn ($c) => $c->scanned_at->toDateString() === $today)->count(),
            'capacity' => $e->capacity, 'by_type' => $byType, 'by_hour' => collect($hours)->map(fn ($n, $h) => ['hour' => $h, 'count' => $n])->values()->all(),
        ];
    }
}
