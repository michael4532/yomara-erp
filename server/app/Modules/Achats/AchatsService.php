<?php

namespace App\Modules\Achats;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\PurchaseReceipt;
use App\Modules\Achats\Models\PurchaseRequest;
use App\Modules\Achats\Models\SupplierPrice;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\TradeDocumentLine;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use App\Services\EntityWriter;
use Illuminate\Support\Str;

class AchatsService
{
    public function __construct(protected EntityWriter $writer) {}

    // ---------- Réceptions ----------

    public function afterReceipt(PurchaseReceipt $r): void
    {
        $company = Company::find($r->company_id);
        if (! $company) {
            return;
        }
        if ($r->wasRecentlyCreated && $r->status === 'done') {
            $this->postReceipt($company, $r);
        } elseif ($r->wasChanged('status') && $r->status === 'cancelled') {
            $this->reverseReceipt($company, $r);
        }
        $this->refreshOrder($r->order_id);
    }

    protected function postReceipt(Company $company, PurchaseReceipt $r): void
    {
        $order = PurchaseDocument::find($r->order_id);
        $lines = TradeDocumentLine::where('document_id', $r->order_id)->get()->keyBy('id');
        $products = Product::whereIn('id', collect($r->lines)->pluck('product_id')->filter())->get()->keyBy('id');
        $warehouse = $r->warehouse_id ?: ($order?->warehouse_id ?: Warehouse::defaultFor($company->id, $order?->branch_id)->id);
        foreach ($r->lines as $l) {
            $p = ! empty($l['product_id']) ? $products->get($l['product_id']) : null;
            $line = $lines->get($l['order_line_id']);
            if (! $p || ! $line) {
                continue;
            }
            // Coût unitaire réel : prix net de remise, hors taxes
            $cost = $line->quantity > 0 ? round($line->subtotal / $line->quantity, 2) : $line->unit_price;
            $factor = (float) ($line->unit_factor ?: 1);
            $cost = round($cost / $factor, 4); // coût par unité de stock
            if ($company->hasModule('stock') && $p->type === 'product' && $p->track_stock) {
                app(\App\Modules\Lots\LotService::class)->moves($p, $warehouse, abs((float) $l['quantity']) * $factor, $l['lots'] ?? null, [
                    'type' => 'purchase', 'unit_cost' => $cost, 'reference' => $r->number, 'date' => $r->date->toDateString(),
                    'notes' => 'Réception '.$r->number.' — '.$order?->number, 'company_id' => $company->id, 'document_id' => $r->id,
                ], strict: true);
            }
            if ($company->setting('purchase_update_cost', true) && $cost > 0 && (float) $p->purchase_price !== $cost) {
                $p->purchase_price = $cost;
                $p->save();
            }
        }
        // La facture fournisseur issue de ce bon ne refera pas entrer le stock
        if ($order && ! $order->stock_posted) {
            $order->stock_posted = true;
            $order->saveQuietly();
        }
    }

    protected function reverseReceipt(Company $company, PurchaseReceipt $r): void
    {
        foreach (StockMove::where('document_id', $r->id)->get()->groupBy(fn ($m) => $m->product_id.'|'.$m->warehouse_id.'|'.$m->lot_id) as $g) {
            $qty = round($g->sum('quantity'), 3);
            if ($qty == 0) {
                continue;
            }
            $m = new StockMove(['product_id' => $g->first()->product_id, 'warehouse_id' => $g->first()->warehouse_id, 'quantity' => -$qty, 'type' => 'adjustment', 'lot_id' => $g->first()->lot_id,
                'reference' => $r->number, 'date' => now()->toDateString(), 'notes' => 'Annulation de la réception '.$r->number]);
            $m->company_id = $company->id;
            $m->document_id = $r->id;
            $m->save();
        }
        if (! PurchaseReceipt::where('order_id', $r->order_id)->where('status', 'done')->exists()) {
            $order = PurchaseDocument::find($r->order_id);
            if ($order && $order->stock_posted) {
                $order->stock_posted = false;
                $order->saveQuietly();
            }
        }
    }

    public function refreshOrder(string $orderId): void
    {
        $order = PurchaseDocument::find($orderId);
        if (! $order) {
            return;
        }
        $done = PurchaseReceipt::receivedByLine($order->id);
        $lines = TradeDocumentLine::where('document_id', $order->id)->get();
        $received = $lines->sum(fn ($l) => min($l->quantity, $done[$l->id] ?? 0));
        $status = $received <= 0 ? 'none' : ($lines->every(fn ($l) => ($done[$l->id] ?? 0) + 0.0005 >= $l->quantity) ? 'received' : 'partial');
        if ($order->receipt_status !== $status) {
            $order->receipt_status = $status;
            $order->save(); // nouvelle version : les appareils voient l'état de réception
        }
    }

    // ---------- Tarifs fournisseurs ----------

    /** À chaque bon de commande validé, le tarif du fournisseur est mémorisé. */
    public function learnPrices(PurchaseDocument $doc): void
    {
        if ($doc->type !== 'purchase_order' || ! $doc->partner_id) {
            return;
        }
        foreach ($doc->lines as $l) {
            if (! $l->product_id || $l->quantity <= 0) {
                continue;
            }
            $price = round($l->subtotal / $l->quantity, 2);
            $sp = SupplierPrice::where('partner_id', $doc->partner_id)->where('product_id', $l->product_id)->first() ?? new SupplierPrice(['partner_id' => $doc->partner_id, 'product_id' => $l->product_id, 'min_qty' => 1]);
            $sp->price = $price;
            $sp->last_purchase_at = $doc->date;
            if (! $sp->exists && ! SupplierPrice::where('product_id', $l->product_id)->exists()) {
                $sp->preferred = true;
            }
            $sp->save();
        }
    }

    // ---------- Réapprovisionnement ----------

    /** Articles sous le seuil d'alerte, en tenant compte des commandes en cours. */
    public function replenishment(): array
    {
        $products = Product::where('track_stock', true)->where('type', 'product')->where('active', true)->where('min_stock', '>', 0)->get();
        if ($products->isEmpty()) {
            return [];
        }
        $stock = StockMove::whereIn('product_id', $products->pluck('id'))->selectRaw('product_id, SUM(quantity) as q')->groupBy('product_id')->pluck('q', 'product_id');
        // Quantités commandées non encore reçues (bons validés sans facture fournisseur validée)
        $orders = PurchaseDocument::where('type', 'purchase_order')->where('status', 'validated')->where(fn ($q) => $q->whereNull('receipt_status')->orWhere('receipt_status', '!=', 'received'))->get();
        $invoiced = PurchaseDocument::where('type', 'supplier_invoice')->where('status', 'validated')->whereIn('source_id', $orders->pluck('id'))->pluck('source_id')->all();
        $incoming = [];
        foreach ($orders->whereNotIn('id', $invoiced) as $o) {
            $done = PurchaseReceipt::receivedByLine($o->id);
            foreach ($o->lines as $l) {
                if ($l->product_id) {
                    $incoming[$l->product_id] = ($incoming[$l->product_id] ?? 0) + max(0, $l->quantity - ($done[$l->id] ?? 0));
                }
            }
        }
        $out = [];
        foreach ($products as $p) {
            $available = round((float) ($stock[$p->id] ?? 0), 3);
            $coming = round($incoming[$p->id] ?? 0, 3);
            if ($available + $coming >= $p->min_stock) {
                continue;
            }
            $best = SupplierPrice::bestFor($p->id);
            $target = $p->min_stock * 2;
            $qty = max(ceil($target - $available - $coming), (float) ($best?->min_qty ?? 1));
            $out[] = [
                'product_id' => $p->id, 'name' => $p->name, 'unit' => $p->unit, 'stock' => $available, 'incoming' => $coming, 'min_stock' => (float) $p->min_stock,
                'quantity' => $qty, 'partner_id' => $best?->partner_id, 'unit_price' => $best?->price ?? (float) $p->purchase_price, 'lead_days' => $best?->lead_days,
            ];
        }

        return $out;
    }

    /** Crée les bons de commande brouillons, un par fournisseur. Retourne les documents. */
    public function createOrders(array $items, string $notes): array
    {
        $company = app('tenant');
        $products = Product::whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');
        $docs = [];
        foreach (collect($items)->groupBy('partner_id') as $partner => $group) {
            if (! $partner) {
                throw new \DomainException('Choisissez un fournisseur pour chaque article.');
            }
            $lead = SupplierPrice::where('partner_id', $partner)->whereIn('product_id', $group->pluck('product_id')->filter())->max('lead_days');
            $res = $this->writer->upsert(PurchaseDocument::class, (string) Str::uuid(), [
                'type' => 'purchase_order', 'partner_id' => $partner, 'date' => now()->toDateString(), 'due_date' => $lead ? now()->addDays($lead)->toDateString() : null, 'notes' => $notes,
                'lines' => $group->map(fn ($i) => [
                    'id' => (string) Str::uuid(), 'product_id' => $i['product_id'] ?? null, 'description' => $i['description'] ?? $products->get($i['product_id'] ?? '')?->name ?? 'Article',
                    'quantity' => (float) $i['quantity'], 'unit' => $i['unit'] ?? $products->get($i['product_id'] ?? '')?->unit, 'unit_price' => (float) ($i['unit_price'] ?? 0),
                    'tax_rate' => isset($i['product_id']) && $products->get($i['product_id']) ? (float) $products->get($i['product_id'])->tax_rate : (float) ($company->settings['tax_rate'] ?? 18),
                    'discount_percent' => 0,
                ])->values()->all(),
            ]);
            if ($res['status'] !== EntityWriter::APPLIED) {
                throw new \DomainException($res['message'] ?? 'Bon de commande refusé.');
            }
            $docs[] = $res['record'];
        }

        return $docs;
    }

    /** Demande approuvée → bons de commande brouillons (fournisseur de la ligne, préféré, ou celui indiqué). */
    public function orderRequest(PurchaseRequest $r, ?string $defaultSupplier): array
    {
        if ($r->status !== 'approved') {
            throw new \DomainException('Seule une demande approuvée peut être commandée.');
        }
        $items = [];
        foreach ($r->lines as $l) {
            $best = ! empty($l['product_id']) ? SupplierPrice::bestFor($l['product_id']) : null;
            $items[] = [
                'product_id' => $l['product_id'] ?? null, 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'] ?? null,
                'partner_id' => $l['supplier_id'] ?? $best?->partner_id ?? $defaultSupplier,
                'unit_price' => $best && ($l['supplier_id'] ?? $best->partner_id) === $best->partner_id ? $best->price : (float) ($l['estimated_price'] ?? 0),
            ];
        }
        $docs = $this->createOrders($items, 'Demande d\'achat '.$r->number.' — '.$r->title);
        $r->forceFill(['status' => 'ordered', 'order_ids' => collect($docs)->pluck('id')->all()])->save();
        if ($r->requester_id) {
            AppNotification::send($r->company_id, [$r->requester_id], 'purchase', 'Demande '.$r->number.' commandée', $r->title.' — '.collect($docs)->count().' bon(s) de commande préparé(s).', 'achats', $r->id, auth()->id());
        }

        return $docs;
    }

    // ---------- Notifications ----------

    public function approvers(int $companyId, ?float $amount = null)
    {
        $limit = (float) (Company::find($companyId)?->setting('purchase_approval_limit') ?? 0);

        return User::where('company_id', $companyId)->where('is_active', true)->get()
            ->filter(fn ($u) => $u->hasPermission('achats.approve') || ($amount !== null && $u->hasPermission('achats.manage') && ($limit <= 0 || $amount <= $limit)))
            ->pluck('id');
    }

    public function afterRequest(PurchaseRequest $r): void
    {
        if (! $r->wasChanged('status') && ! $r->wasRecentlyCreated) {
            return;
        }
        if ($r->status === 'submitted') {
            AppNotification::send($r->company_id, $this->approvers($r->company_id, $r->estimated_total), 'purchase', 'Demande d\'achat à approuver : '.$r->number,
                $r->title.($r->estimated_total > 0 ? ' — '.number_format($r->estimated_total, 0, ',', ' ').' (estimé)' : ''), 'achats', $r->id, auth()->id());
        } elseif (in_array($r->status, ['approved', 'rejected'], true) && $r->requester_id) {
            AppNotification::send($r->company_id, [$r->requester_id], 'purchase', 'Demande '.$r->number.($r->status === 'approved' ? ' approuvée' : ' refusée'),
                $r->status === 'rejected' ? (string) $r->rejection_reason : $r->title, 'achats', $r->id, auth()->id());
            if ($r->status === 'approved') {
                $buyers = User::where('company_id', $r->company_id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('achats.manage'))->pluck('id');
                AppNotification::send($r->company_id, $buyers, 'purchase', 'Demande '.$r->number.' approuvée : à commander', $r->title, 'achats', $r->id, auth()->id());
            }
        }
    }
}
