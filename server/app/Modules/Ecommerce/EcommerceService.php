<?php

namespace App\Modules\Ecommerce;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Modules\Logistique\Models\Delivery;
use App\Modules\Logistique\Models\Zone;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteOrder;
use App\Modules\Sites\ShopService;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use App\Services\EntityWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Boutique en ligne : traitement des commandes de la boutique du site (et des commandes saisies depuis WhatsApp,
 * Facebook, le téléphone…), réservation du stock, facturation à l'expédition, livraison dans la Logistique,
 * encaissements, flux catalogue pour les réseaux sociaux et Google, statistiques.
 */
class EcommerceService
{
    public const CHANNELS = ['site' => 'Site internet', 'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'telephone' => 'Téléphone', 'boutique' => 'En boutique'];

    public const OPEN = ['new', 'confirmed', 'preparing', 'shipped'];

    public const PICKUP = 'Retrait en boutique';

    public function __construct(protected ShopService $shop, protected EntityWriter $writer) {}

    // ------------------------------------------------------------------ réglages

    public function settings(Company $c): array
    {
        $row = DB::table('ec_settings')->where('company_id', $c->id)->first();
        if (! $row) {
            DB::table('ec_settings')->insertOrIgnore(['company_id' => $c->id, 'feed_token' => Str::random(32), 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('ec_settings')->where('company_id', $c->id)->first();
        }

        return ['reserve_stock' => (bool) $row->reserve_stock, 'auto_delivery' => (bool) $row->auto_delivery, 'notify_customer' => (bool) $row->notify_customer,
            'alert_hours' => (int) $row->alert_hours, 'whatsapp_number' => $row->whatsapp_number, 'brand' => $row->brand, 'feed_token' => $row->feed_token];
    }

    public function saveSettings(Company $c, array $d): array
    {
        $this->settings($c);
        $row = ['updated_at' => now()];
        foreach (['reserve_stock', 'auto_delivery', 'notify_customer'] as $k) {
            if (array_key_exists($k, $d)) {
                $row[$k] = (bool) $d[$k];
            }
        }
        if (array_key_exists('alert_hours', $d)) {
            $row['alert_hours'] = max(1, min(240, (int) $d['alert_hours']));
        }
        if (array_key_exists('whatsapp_number', $d)) {
            $digits = preg_replace('/\D/', '', (string) $d['whatsapp_number']);
            $row['whatsapp_number'] = $digits === '' ? null : (strlen($digits) === 10 ? '225'.$digits : $digits);
        }
        if (array_key_exists('brand', $d)) {
            $row['brand'] = Str::limit(trim((string) $d['brand']), 120, '') ?: null;
        }
        DB::table('ec_settings')->where('company_id', $c->id)->update($row);

        return $this->settings($c);
    }

    public function resetFeed(Company $c): array
    {
        $this->settings($c);
        DB::table('ec_settings')->where('company_id', $c->id)->update(['feed_token' => Str::random(32), 'updated_at' => now()]);

        return $this->settings($c);
    }

    public function feedUrls(Company $c): array
    {
        $t = $this->settings($c)['feed_token'];

        return ['meta' => url('/boutique/flux/'.$t.'/meta.csv'), 'google' => url('/boutique/flux/'.$t.'/google.xml')];
    }

    /** Lien « Commander sur WhatsApp » à partager (statut, publication, bio). */
    public function whatsappLink(Company $c, ?string $text = null): ?string
    {
        $n = $this->settings($c)['whatsapp_number'];

        return $n ? 'https://wa.me/'.$n.'?text='.rawurlencode($text ?? 'Bonjour '.$c->name.', je souhaite passer une commande.') : null;
    }

    // ------------------------------------------------------------------ stock réservé

    /**
     * Quantités réservées par les commandes en ligne ouvertes qui n'ont pas encore fait sortir le stock
     * (commande client non facturée). Les commandes payées en ligne sont déjà facturées : leur stock est sorti.
     */
    public function reserved(Company $c, ?array $productIds = null, ?int $exceptOrder = null): array
    {
        if (! $this->settings($c)['reserve_stock']) {
            return [];
        }
        $orders = DB::table('ws_orders')->where('company_id', $c->id)->whereIn('status', self::OPEN)->whereNull('invoice_id')->whereNotNull('document_id')
            ->when($exceptOrder, fn ($q) => $q->where('id', '!=', $exceptOrder))->get(['id', 'items', 'document_id']);
        if ($orders->isEmpty()) {
            return [];
        }
        $docIds = $orders->pluck('document_id')->all();
        $open = DB::table('trade_documents')->whereIn('id', $docIds)->where('type', 'order')->where('status', '!=', 'cancelled')->whereNull('deleted_at')->pluck('id')->flip();
        $invoiced = DB::table('trade_documents')->whereIn('source_id', $docIds)->where('type', 'invoice')->where('status', '!=', 'cancelled')->whereNull('deleted_at')->pluck('source_id')->flip();
        $out = [];
        foreach ($orders as $o) {
            if (! isset($open[$o->document_id]) || isset($invoiced[$o->document_id])) {
                continue;
            }
            foreach ((array) json_decode((string) $o->items, true) as $it) {
                $pid = $it['product_id'] ?? null;
                if ($pid && ($productIds === null || in_array($pid, $productIds, true))) {
                    $out[$pid] = ($out[$pid] ?? 0) + (float) ($it['quantity'] ?? 0);
                }
            }
        }

        return $out;
    }

    protected function physical(Company $c, array $ids): array
    {
        if (! $ids || ! $c->hasModule('stock')) {
            return [];
        }

        return DB::table('stock_moves')->where('company_id', $c->id)->whereIn('product_id', $ids)->whereNull('deleted_at')->selectRaw('product_id, sum(quantity) q')->groupBy('product_id')
            ->pluck('q', 'product_id')->map(fn ($q) => (float) $q)->all();
    }

    /**
     * Disponibilité des commandes à préparer : le stock physique est attribué aux commandes dans l'ordre d'arrivée ;
     * une commande dont un article manque est signalée (rupture) avant d'être promise au client.
     */
    public function allocation(Company $c, $orders): array
    {
        $pending = $orders->filter(fn ($o) => in_array($o->status, ['new', 'confirmed', 'preparing'], true) && ! $o->invoice_id)->sortBy('id');
        $ids = $pending->flatMap(fn ($o) => collect($o->items)->pluck('product_id'))->filter()->unique()->values()->all();
        $tracked = $ids ? DB::table('products')->whereIn('id', $ids)->where('track_stock', true)->pluck('id')->all() : [];
        $left = $this->physical($c, $tracked);
        $out = [];
        foreach ($pending as $o) {
            $doc = $o->document_id ? DB::table('trade_documents')->where('id', $o->document_id)->value('type') : null;
            $short = [];
            foreach ($o->items as $it) {
                $pid = $it['product_id'] ?? null;
                if (! $pid || ! in_array($pid, $tracked, true)) {
                    continue;
                }
                $have = $left[$pid] ?? 0;
                if ($doc === 'order') { // une facture (paiement en ligne) a déjà fait sortir son stock
                    if ($have + 1e-9 < $it['quantity']) {
                        $short[] = ['name' => $it['name'], 'needed' => (float) $it['quantity'], 'available' => max(0, $have)];
                    }
                    $left[$pid] = $have - $it['quantity'];
                }
            }
            $out[$o->id] = $short;
        }

        return $out;
    }

    // ------------------------------------------------------------------ commandes

    public function site(): Site
    {
        return Site::first() ?? throw new \DomainException('Créez d\'abord votre site (module Site vitrine) pour ouvrir la boutique en ligne.');
    }

    public function isPickup(SiteOrder $o): bool
    {
        return $o->delivery_mode === self::PICKUP;
    }

    public function toApi(SiteOrder $o, ?Site $s = null, array $alloc = [], ?array $settings = null): array
    {
        $base = $this->shop->orderToApi($o, $s);
        $inv = $o->invoice_id ? SalesDocument::withoutGlobalScopes()->find($o->invoice_id) : null;
        $del = $o->delivery_id ? Delivery::withoutGlobalScopes()->find($o->delivery_id) : null;
        $alert = $settings['alert_hours'] ?? 24;
        $label = $o->status === 'shipped' && $this->isPickup($o) ? 'Prête au retrait' : $base['status_label'];

        return array_merge($base, [
            'status_label' => $label, 'channel' => $o->channel ?: 'site', 'channel_label' => self::CHANNELS[$o->channel ?: 'site'] ?? $o->channel,
            'pickup' => $this->isPickup($o),
            'invoice' => $inv ? ['id' => $inv->id, 'number' => $inv->number, 'total' => (float) $inv->total, 'paid' => (float) $inv->amount_paid, 'balance' => $inv->balance(), 'payment_status' => $inv->payment_status] : null,
            'delivery' => $del ? ['id' => $del->id, 'number' => $del->number, 'status' => $del->status, 'status_label' => Delivery::STATUSES[$del->status] ?? $del->status,
                'driver' => $del->driver_id ? DB::table('users')->where('id', $del->driver_id)->value('name') : null, 'scheduled_date' => $del->scheduled_date?->toDateString(),
                'cod_amount' => (float) $del->cod_amount, 'tracking_url' => $del->trackingUrl(), 'failed_reason' => $del->failed_reason] : null,
            'to_collect' => $o->payment_status === 'paid' || $o->status === 'cancelled' ? 0.0 : ($inv ? max(0, $inv->balance()) : (float) $o->total),
            'late' => $o->status === 'new' && $o->created_at && $o->created_at->lt(now()->subHours($alert)),
            'shortages' => $alloc[$o->id] ?? [],
        ]);
    }

    protected function find(int $id): SiteOrder
    {
        return SiteOrder::findOrFail($id);
    }

    protected function guardOpen(SiteOrder $o): void
    {
        if (in_array($o->status, ['delivered', 'cancelled'], true)) {
            throw new \DomainException('Commande '.$o->number.' clôturée ('.mb_strtolower(ShopService::STATUSES[$o->status]).').');
        }
    }

    /** Commande saisie par l'équipe (reçue sur WhatsApp, Facebook, au téléphone…) : même circuit que le site. */
    public function createManual(array $d): SiteOrder
    {
        $s = $this->site();
        $channel = isset(self::CHANNELS[$d['channel'] ?? '']) ? $d['channel'] : 'whatsapp';
        $o = $this->shop->placeOrder($s, $d + ['_manual' => true]);
        $o->forceFill(['channel' => $channel])->save();
        $o = $o->fresh();
        if (! empty($d['confirm'])) {
            $o = $this->confirm($o);
        }

        return $o;
    }

    public function confirm(SiteOrder $o): SiteOrder
    {
        $this->guardOpen($o);
        if ($o->status !== 'new') {
            return $o;
        }
        $o = $this->shop->setStatus($o, 'confirmed');
        $o->forceFill(['confirmed_at' => now()])->save();
        $this->notify($o, 'confirmed');

        return $o;
    }

    public function prepare(SiteOrder $o): SiteOrder
    {
        $this->guardOpen($o);
        if (in_array($o->status, ['new', 'confirmed'], true)) {
            $o = $this->shop->setStatus($o, 'preparing');
            $o->forceFill(['confirmed_at' => $o->confirmed_at ?? now()])->save();
        }

        return $o;
    }

    /** Facture de la commande (créée au besoin depuis la commande client) : c'est elle qui fait sortir le stock. */
    public function invoice(SiteOrder $o): SalesDocument
    {
        if ($o->invoice_id && ($inv = SalesDocument::find($o->invoice_id)) && $inv->status !== 'cancelled') {
            return $inv;
        }
        $doc = SalesDocument::with('lines')->find($o->document_id) ?? throw new \DomainException('Document de vente de la commande introuvable.');
        if ($doc->type === 'invoice') {
            $o->forceFill(['invoice_id' => $doc->id])->save();

            return $doc;
        }
        if ($doc->status === 'cancelled') {
            throw new \DomainException('La commande client '.$doc->number.' est annulée.');
        }
        // Déjà facturée à la main dans les Ventes ?
        $existing = SalesDocument::where('source_id', $doc->id)->where('type', 'invoice')->where('status', '!=', 'cancelled')->first();
        if ($existing) {
            $o->forceFill(['invoice_id' => $existing->id])->save();

            return $existing;
        }
        $id = (string) Str::uuid();
        SalesDocument::$systemWrite = true;
        try {
            $res = $this->writer->upsert(SalesDocument::class, $id, [
                'type' => 'invoice', 'partner_id' => $doc->partner_id, 'date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'status' => 'validated',
                'reference' => $o->number, 'source_id' => $doc->id, 'warehouse_id' => $doc->warehouse_id, 'branch_id' => $doc->branch_id, 'notes' => $doc->notes,
                'lines' => $doc->lines->map(fn ($l) => ['id' => (string) Str::uuid()] + $l->only(['product_id', 'description', 'quantity', 'unit', 'unit_price', 'discount_percent', 'tax_rate']))->all(),
            ]);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Facturation refusée : '.($res['message'] ?? 'erreur inconnue'));
        }
        $o->forceFill(['invoice_id' => $id])->save();

        return SalesDocument::find($id);
    }

    /** Expédition : facture (sortie de stock), bon de livraison dans la Logistique, client prévenu. */
    public function ship(SiteOrder $o, array $d = []): SiteOrder
    {
        $this->guardOpen($o);
        if ($o->status === 'shipped') {
            throw new \DomainException('Commande déjà expédiée.');
        }
        $c = app('tenant');
        $set = $this->settings($c);

        return DB::transaction(function () use ($o, $d, $c, $set) {
            $inv = $this->invoice($o);
            if (! $this->isPickup($o) && $c->hasModule('logistique') && ($d['delivery'] ?? $set['auto_delivery']) && ! $o->delivery_id) {
                $o->forceFill(['delivery_id' => $this->createDelivery($o, $inv, $d)])->save();
            }
            $o = $this->shop->setStatus($o, 'shipped');
            $o->forceFill(['shipped_at' => now(), 'confirmed_at' => $o->confirmed_at ?? now()])->save();
            $this->notify($o, 'shipped');

            return $o;
        });
    }

    protected function createDelivery(SiteOrder $o, SalesDocument $inv, array $d): string
    {
        $zone = Zone::where('name', $o->delivery_mode)->first();
        $partner = $o->partner_id ? DB::table('partners')->where('id', $o->partner_id)->first(['city']) : null;
        $id = (string) Str::uuid();
        Delivery::$systemWrite = true;
        try {
            $res = $this->writer->upsert(Delivery::class, $id, [
                'source_document_id' => $inv->id, 'partner_id' => $o->partner_id, 'recipient_name' => $o->name, 'recipient_phone' => $o->phone,
                'address' => Str::limit((string) $o->address, 250, ''), 'city' => $partner?->city, 'zone_id' => $zone?->id,
                'scheduled_date' => $d['date'] ?? now()->toDateString(), 'time_slot' => $d['time_slot'] ?? null, 'status' => 'planned', 'driver_id' => $d['driver_id'] ?? null,
                'items' => collect($o->items)->map(fn ($i) => ['description' => Str::limit($i['name'], 250, ''), 'quantity' => $i['quantity']])->values()->all(),
                'packages' => $d['packages'] ?? 1, 'fee' => (float) $o->delivery_fee, 'cod_amount' => $o->payment_status === 'paid' ? 0 : max(0, $inv->balance()),
                'notes' => trim('Commande en ligne '.$o->number.' · '.$o->delivery_mode.($o->note ? "\n".Str::limit($o->note, 500, '') : '')), 'notify' => true,
            ]);
        } finally {
            Delivery::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Bon de livraison refusé : '.($res['message'] ?? 'erreur inconnue'));
        }

        return $id;
    }

    /** Encaissement (Mobile Money reçu, paiement à la livraison, espèces au retrait…) enregistré sur la facture. */
    public function pay(SiteOrder $o, array $d): SiteOrder
    {
        if ($o->status === 'cancelled') {
            throw new \DomainException('Commande annulée.');
        }
        if ($o->payment_status === 'paid') {
            throw new \DomainException('Commande déjà encaissée.');
        }

        return DB::transaction(function () use ($o, $d) {
            $inv = $this->invoice($o);
            $due = $inv->balance();
            $amount = round(min($due, (float) ($d['amount'] ?? $due)), 2);
            if ($amount <= 0) {
                $o->forceFill(['payment_status' => 'paid'])->save();

                return $o;
            }
            $method = in_array($d['method'] ?? '', \App\Modules\Core\Models\Payment::METHODS, true) ? $d['method'] : ($o->payment_mode === 'momo' ? 'mobile_money' : 'cash');
            SalesPayment::$systemWrite = true;
            try {
                $res = $this->writer->upsert(SalesPayment::class, (string) Str::uuid(), array_filter([
                    'document_id' => $inv->id, 'date' => $d['date'] ?? now()->toDateString(), 'amount' => $amount, 'method' => $method,
                    'reference' => $d['reference'] ?? $o->number, 'notes' => 'Commande en ligne '.$o->number, 'treasury_account_id' => $d['treasury_account_id'] ?? null,
                ], fn ($v) => $v !== null));
            } finally {
                SalesPayment::$systemWrite = false;
            }
            if ($res['status'] !== EntityWriter::APPLIED) {
                throw new \DomainException('Encaissement refusé : '.($res['message'] ?? 'erreur inconnue'));
            }
            $o->refresh();
            $o->forceFill(['payment_method' => $method, 'payment_ref' => isset($d['reference']) ? Str::limit($d['reference'], 80, '') : $o->payment_ref])->save();
            if ($o->payment_status !== 'paid' && SalesDocument::find($inv->id)->payment_status === 'paid') {
                $o->forceFill(['payment_status' => 'paid'])->save();
            }

            return $o->fresh();
        });
    }

    /** Remise au client : livraison confirmée (ou retrait en boutique), avec l'encaissement s'il a lieu à ce moment. */
    public function deliver(SiteOrder $o, array $d = []): SiteOrder
    {
        $this->guardOpen($o);
        if (in_array($o->status, ['new', 'confirmed', 'preparing'], true)) {
            $o = $this->ship($o, ['delivery' => false]);
        }

        return DB::transaction(function () use ($o, $d) {
            $collect = ! empty($d['collected']) && $o->payment_status !== 'paid';
            if ($o->delivery_id && ($del = Delivery::find($o->delivery_id)) && ! in_array($del->status, ['delivered', 'cancelled'], true)) {
                // La livraison fait foi : l'encaissement à la livraison est enregistré par la Logistique
                Delivery::$systemWrite = true;
                try {
                    $this->writer->upsert(Delivery::class, $del->id, ['status' => 'delivered', 'cod_collected' => $collect ? (float) $del->cod_amount : 0,
                        'cod_method' => $collect ? ($d['method'] ?? 'cash') : null, 'signed_by' => $d['signed_by'] ?? null]);
                } finally {
                    Delivery::$systemWrite = false;
                }
                $o->refresh();
                $collect = $collect && $o->payment_status !== 'paid' && ! (float) $del->cod_amount;
            }
            if ($collect) {
                $o = $this->pay($o, ['method' => $d['method'] ?? 'cash', 'reference' => $d['reference'] ?? null]);
            }
            if ($o->status !== 'delivered') {
                $o = $this->shop->setStatus($o, 'delivered');
                $o->forceFill(['delivered_at' => now()])->save();
                $this->notify($o, 'delivered');
            }

            return $o->fresh();
        });
    }

    public function cancel(SiteOrder $o, ?string $reason = null): SiteOrder
    {
        $this->guardOpen($o);
        if ($o->invoice_id && ($inv = SalesDocument::find($o->invoice_id)) && $inv->status !== 'cancelled' && $inv->type === 'invoice' && $inv->id !== $o->document_id) {
            throw new \DomainException('Commande déjà facturée ('.$inv->number.') : faites un avoir dans les Ventes, puis annulez la commande.');
        }
        if ($o->delivery_id && ($del = Delivery::find($o->delivery_id)) && in_array($del->status, ['draft', 'planned'], true)) {
            Delivery::$systemWrite = true;
            try {
                $this->writer->upsert(Delivery::class, $del->id, ['status' => 'cancelled']);
            } finally {
                Delivery::$systemWrite = false;
            }
        }
        $o = $this->shop->setStatus($o, 'cancelled', $reason ? trim(($o->staff_note ? $o->staff_note."\n" : '').'Annulée : '.$reason) : null);
        $o->forceFill(['cancelled_at' => now()])->save();
        $this->notify($o, 'cancelled');

        return $o;
    }

    /** Suivi d'une livraison de la Logistique : la commande en ligne suit (livrée, échec). */
    public function onDelivery(Delivery $d): void
    {
        $o = SiteOrder::withoutGlobalScopes()->where('company_id', $d->company_id)->where('delivery_id', $d->id)->first();
        if (! $o || in_array($o->status, ['delivered', 'cancelled'], true)) {
            return;
        }
        if ($d->status === 'delivered') {
            $o->forceFill(['status' => 'delivered', 'delivered_at' => $d->delivered_at ?? now()])->save();
        } elseif ($d->status === 'failed') {
            $o->forceFill(['staff_note' => Str::limit(trim(($o->staff_note ? $o->staff_note."\n" : '').'Échec de livraison le '.now()->format('d/m/Y').($d->failed_reason ? ' : '.$d->failed_reason : '')), 1000, '')])->save();
        }
    }

    protected function notify(SiteOrder $o, string $event): void
    {
        $c = app('tenant');
        if (! $c || ! $this->settings($c)['notify_customer']) {
            return;
        }
        $s = Site::withoutGlobalScopes()->find($o->site_id);
        $track = $s ? $this->shop->trackUrl($s, $o) : null;
        $text = match ($event) {
            'confirmed' => "Bonjour {$o->name}, votre commande {$o->number} chez {$c->name} est confirmée. Nous la préparons.",
            'shipped' => $this->isPickup($o) ? "Bonjour {$o->name}, votre commande {$o->number} est prête : vous pouvez la retirer chez {$c->name}." : "Bonjour {$o->name}, votre commande {$o->number} de {$c->name} est en route.",
            'delivered' => "Bonjour {$o->name}, votre commande {$o->number} vous a été remise. Merci de votre confiance !",
            'cancelled' => "Bonjour {$o->name}, votre commande {$o->number} chez {$c->name} a été annulée. Contactez-nous pour toute question.",
            default => null,
        };
        if (! $text) {
            return;
        }
        if ($track && $event !== 'cancelled') {
            $text .= "\nSuivi : ".$track;
        }
        $m = app(MessagingService::class);
        try {
            $av = $m->available($c);
            if ($o->phone && ($av['whatsapp'] ?? null)) {
                $m->send($c, 'whatsapp', new Message(to: $o->phone, toName: $o->name, text: $text), 'boutique');
            } elseif ($o->phone && ($av['sms'] ?? null)) {
                $m->send($c, 'sms', new Message(to: $o->phone, toName: $o->name, text: $text), 'boutique');
            } elseif ($o->email && ($av['email'] ?? null)) {
                $m->send($c, 'email', new Message(to: $o->email, toName: $o->name, subject: 'Commande '.$o->number.' — '.$c->name, html: nl2br(e($text)), text: $text, replyTo: $c->email), 'boutique');
            }
        } catch (\Throwable) {
            // Un message non envoyé ne bloque jamais la commande
        }
    }

    // ------------------------------------------------------------------ bon de préparation

    public function pickingPdf(Company $c, $orders): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => $e(number_format((float) $v, 0, ',', ' ')).' F';
        $ids = $orders->flatMap(fn ($o) => collect($o->items)->pluck('product_id'))->filter()->unique()->values()->all();
        $products = $ids ? DB::table('products')->whereIn('id', $ids)->get(['id', 'sku', 'barcode', 'unit'])->keyBy('id') : collect();
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->AddPage();
        $head = '<table cellpadding="2"><tr><td width="60%"><b style="font-size:13pt">'.$e($c->name).'</b><br>Boutique en ligne</td><td width="40%" align="right"><b style="font-size:13pt;color:#1f6feb">BON DE PRÉPARATION</b><br>'
            .now()->format('d/m/Y H:i').' · '.$orders->count().' commande(s)</td></tr></table><br>';
        // Liste de prélèvement : total par article, toutes commandes confondues
        $tot = [];
        foreach ($orders as $o) {
            foreach ($o->items as $it) {
                $k = $it['product_id'] ?? $it['name'];
                $tot[$k] ??= ['name' => $it['name'], 'q' => 0, 'orders' => []];
                $tot[$k]['q'] += $it['quantity'];
                $tot[$k]['orders'][] = $o->number;
            }
        }
        uasort($tot, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $html = $head.'<h3>Liste de prélèvement</h3><table cellpadding="4" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="6%"></td><td width="44%">Article</td><td width="16%">Réf.</td><td width="10%" align="right">Qté</td><td width="24%">Commandes</td></tr>';
        foreach ($tot as $k => $r) {
            $p = $products[$k] ?? null;
            $html .= '<tr><td>☐</td><td>'.$e($r['name']).'</td><td>'.$e($p?->sku ?: $p?->barcode).'</td><td align="right"><b>'.$e(rtrim(rtrim(number_format($r['q'], 2, ',', ' '), '0'), ',')).'</b> '.$e($p?->unit).'</td><td>'.$e(implode(', ', array_unique($r['orders']))).'</td></tr>';
        }
        $html .= '</table>';
        $pdf->writeHTML($html);
        // Une fiche par commande, à glisser dans le colis
        foreach ($orders as $o) {
            $pdf->AddPage();
            $to = $this->toApi($o);
            $html = $head.'<table cellpadding="4"><tr><td width="55%"><b style="font-size:12pt">'.$e($o->number).'</b> · '.$e(self::CHANNELS[$o->channel ?: 'site'] ?? '').'<br>Commandée le '.$o->created_at->format('d/m/Y à H:i')
                .'<br><br><b>'.$e($o->name).'</b><br>'.$e($o->phone).($o->email ? '<br>'.$e($o->email) : '').'</td><td width="45%" bgcolor="#f3f6fb"><b>'.$e($o->delivery_mode).'</b>'
                .($o->address ? '<br>'.$e($o->address) : '').($to['delivery'] ? '<br>Livraison '.$e($to['delivery']['number']) : '').'<br><br>'
                .($to['to_collect'] > 0 ? '<b style="color:#b91c1c;font-size:11pt">À encaisser : '.$m($to['to_collect']).'</b>' : '<b style="color:#15803d;font-size:11pt">Payée</b>').'</td></tr></table><br>'
                .'<table cellpadding="4" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="6%"></td><td width="54%">Article</td><td width="10%" align="right">Qté</td><td width="15%" align="right">P.U. TTC</td><td width="15%" align="right">Total</td></tr>';
            foreach ($o->items as $it) {
                $html .= '<tr><td>☐</td><td>'.$e($it['name']).'</td><td align="right"><b>'.$e($it['quantity']).'</b></td><td align="right">'.$m($it['unit_price']).'</td><td align="right">'.$m($it['total']).'</td></tr>';
            }
            if ($o->discount > 0) {
                $html .= '<tr><td></td><td colspan="3">Code promo '.$e($o->coupon_code).'</td><td align="right">−'.$m($o->discount).'</td></tr>';
            }
            if ($o->delivery_fee > 0) {
                $html .= '<tr><td></td><td colspan="3">'.$e($o->delivery_mode).'</td><td align="right">'.$m($o->delivery_fee).'</td></tr>';
            }
            $html .= '<tr bgcolor="#eef2f8" style="font-weight:bold"><td></td><td colspan="3">Total TTC</td><td align="right">'.$m($o->total).'</td></tr></table>'
                .($o->note ? '<p><b>Note du client :</b> '.$e($o->note).'</p>' : '')
                .'<br><br><table cellpadding="4"><tr><td width="50%">Préparée par : ____________________</td><td width="50%">Contrôlée par : ____________________</td></tr></table>';
            $pdf->writeHTML($html);
        }

        return $pdf->Output('', 'S');
    }

    // ------------------------------------------------------------------ flux catalogue (Meta, Google)

    /** Articles du flux : un article par produit simple, une ligne par variante (même groupe). */
    public function feedItems(Company $c, Site $s): array
    {
        $catalog = $this->shop->catalog($s);
        $base = rtrim(app(\App\Modules\Sites\SiteService::class)->url($s), '/');
        $brand = $this->settings($c)['brand'] ?: $c->name;
        $cur = $c->currency ?: 'XOF';
        $out = [];
        foreach ($catalog as $p) {
            $link = $this->shop->productUrl($s, $p, $base);
            $desc = trim(strip_tags((string) $p['description'])) ?: $p['name'];
            $rows = $p['is_template'] ? array_map(fn ($v) => ['id' => $v['id'], 'title' => $p['name'].' — '.$v['label'], 'price' => $v['price'], 'stock' => $v['stock'], 'image' => $v['image'] ?: $p['image'], 'group' => $p['id']], $p['variants'])
                : [['id' => $p['id'], 'title' => $p['name'], 'price' => $p['price'], 'stock' => $p['stock'], 'image' => $p['image'], 'group' => null]];
            foreach ($rows as $r) {
                $compare = $p['compare_at'] && $p['compare_at'] > $r['price'] ? $p['compare_at'] : null;
                $out[] = ['id' => $r['id'], 'title' => Str::limit($r['title'], 150, ''), 'description' => Str::limit($desc, 5000, ''),
                    'availability' => $r['stock'] === null || $r['stock'] > 0 ? 'in stock' : 'out of stock', 'quantity' => $r['stock'] === null ? null : (int) max(0, floor($r['stock'])),
                    'condition' => 'new', 'price' => number_format($compare ?? $r['price'], 0, '.', '').' '.$cur, 'sale_price' => $compare ? number_format($r['price'], 0, '.', '').' '.$cur : '',
                    'link' => $link, 'image_link' => $r['image'] && str_starts_with($r['image'], 'http') ? $r['image'] : '', 'brand' => $brand,
                    'item_group_id' => $r['group'] ?? '', 'product_type' => $p['category'] ?? ''];
            }
        }

        return $out;
    }

    public function metaCsv(Company $c, Site $s): string
    {
        $cols = ['id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link', 'image_link', 'brand', 'item_group_id', 'product_type', 'quantity_to_sell_on_facebook'];
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $cols, ',', '"', '');
        foreach ($this->feedItems($c, $s) as $i) {
            fputcsv($h, [$i['id'], $i['title'], $i['description'], $i['availability'], $i['condition'], $i['price'], $i['sale_price'], $i['link'], $i['image_link'], $i['brand'], $i['item_group_id'], $i['product_type'], $i['quantity'] ?? ''], ',', '"', '');
        }
        rewind($h);

        return stream_get_contents($h);
    }

    public function googleXml(Company $c, Site $s): string
    {
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
            .'<title>'.$x($c->name).'</title><link>'.$x(rtrim(app(\App\Modules\Sites\SiteService::class)->url($s), '/').'/boutique').'</link><description>'.$x('Catalogue de '.$c->name).'</description>'."\n";
        foreach ($this->feedItems($c, $s) as $i) {
            $out .= '<item><g:id>'.$x($i['id']).'</g:id><title>'.$x($i['title']).'</title><description>'.$x($i['description']).'</description><link>'.$x($i['link']).'</link>'
                .($i['image_link'] ? '<g:image_link>'.$x($i['image_link']).'</g:image_link>' : '')
                .'<g:availability>'.str_replace(' ', '_', $i['availability']).'</g:availability><g:condition>new</g:condition><g:price>'.$x($i['price']).'</g:price>'
                .($i['sale_price'] ? '<g:sale_price>'.$x($i['sale_price']).'</g:sale_price>' : '').'<g:brand>'.$x($i['brand']).'</g:brand><g:identifier_exists>no</g:identifier_exists>'
                .($i['item_group_id'] ? '<g:item_group_id>'.$x($i['item_group_id']).'</g:item_group_id>' : '').($i['product_type'] ? '<g:product_type>'.$x($i['product_type']).'</g:product_type>' : '')."</item>\n";
        }

        return $out.'</channel></rss>';
    }

    // ------------------------------------------------------------------ statistiques

    public function stats(Site $s, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        $all = SiteOrder::where('site_id', $s->id)->whereBetween('created_at', [$start, $end])->get();
        $ok = $all->where('status', '!=', 'cancelled');
        $carts = DB::table('ws_carts')->where('site_id', $s->id)->whereBetween('created_at', [$start, $end])->get(['order_id', 'reminded_at', 'total']);
        $revenue = (float) $ok->sum('total');
        $days = [];
        for ($d = $start->copy(); $d->lte($end) && count($days) < 400; $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'orders' => 0, 'revenue' => 0.0];
        }
        foreach ($ok as $o) {
            $k = $o->created_at->toDateString();
            if (isset($days[$k])) {
                $days[$k]['orders']++;
                $days[$k]['revenue'] += (float) $o->total;
            }
        }
        $products = [];
        foreach ($ok as $o) {
            foreach ($o->items as $it) {
                $k = $it['product_id'] ?? $it['name'];
                $products[$k] ??= ['name' => $it['name'], 'quantity' => 0, 'revenue' => 0.0, 'orders' => 0];
                $products[$k]['quantity'] += $it['quantity'];
                $products[$k]['revenue'] += (float) $it['total'];
                $products[$k]['orders']++;
            }
        }
        usort($products, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $group = fn (string $key, array $labels) => $ok->groupBy(fn ($o) => $o->{$key} ?: 'site')->map(fn ($g, $k) => ['key' => $k, 'label' => $labels[$k] ?? $k, 'orders' => $g->count(), 'revenue' => (float) $g->sum('total')])
            ->sortByDesc('revenue')->values()->all();
        $shipped = $ok->filter(fn ($o) => $o->shipped_at);
        $prep = $shipped->count() ? round($shipped->avg(fn ($o) => $o->created_at->diffInMinutes($o->shipped_at)) / 60, 1) : null;
        $firstOrders = SiteOrder::where('site_id', $s->id)->where('status', '!=', 'cancelled')->whereNotNull('partner_id')->selectRaw('partner_id, min(id) first_id')->groupBy('partner_id')->pluck('first_id', 'partner_id');
        $newCustomers = $ok->filter(fn ($o) => $o->partner_id && ($firstOrders[$o->partner_id] ?? null) == $o->id)->count();
        $converted = $carts->whereNotNull('order_id')->count();

        return [
            'from' => $start->toDateString(), 'to' => $end->toDateString(),
            'orders' => $ok->count(), 'revenue' => $revenue, 'average_basket' => $ok->count() ? round($revenue / $ok->count()) : 0,
            'items' => (float) $ok->sum(fn ($o) => collect($o->items)->sum('quantity')),
            'cancelled' => $all->where('status', 'cancelled')->count(), 'cancel_rate' => $all->count() ? round($all->where('status', 'cancelled')->count() * 100 / $all->count(), 1) : 0,
            'paid' => (float) $ok->where('payment_status', 'paid')->sum('total'), 'to_collect' => (float) $ok->where('payment_status', '!=', 'paid')->sum('total'),
            'delivered' => $ok->where('status', 'delivered')->count(), 'prep_hours' => $prep,
            'customers' => $ok->pluck('partner_id')->filter()->unique()->count(), 'new_customers' => $newCustomers,
            'discounts' => (float) $ok->sum('discount'), 'delivery_fees' => (float) $ok->sum('delivery_fee'),
            'carts' => $carts->count(), 'carts_converted' => $converted, 'carts_recovered' => $carts->whereNotNull('order_id')->whereNotNull('reminded_at')->count(),
            'carts_abandoned_value' => (float) $carts->whereNull('order_id')->sum('total'), 'conversion' => $carts->count() ? round($converted * 100 / $carts->count(), 1) : null,
            'series' => array_values($days), 'top_products' => array_slice($products, 0, 10),
            'by_channel' => $group('channel', self::CHANNELS), 'by_payment' => $group('payment_mode', ShopService::PAYMENT_MODES),
            'by_delivery' => $group('delivery_mode', []),
            'coupons' => $ok->whereNotNull('coupon_code')->groupBy('coupon_code')->map(fn ($g, $k) => ['code' => $k, 'orders' => $g->count(), 'discount' => (float) $g->sum('discount'), 'revenue' => (float) $g->sum('total')])->values()->all(),
        ];
    }
}
