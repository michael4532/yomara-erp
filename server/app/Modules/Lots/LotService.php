<?php

namespace App\Modules\Lots;

use App\Models\AppNotification;
use App\Models\Company;
use App\Modules\Core\Models\Product;
use App\Modules\Lots\Models\Lot;
use App\Modules\Stock\Models\StockMove;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lots, numéros de série et péremption.
 *
 * Chaque mouvement de stock d'un article suivi porte son lot. En sortie, les lots indiqués sur la
 * ligne sont pris en premier, puis le reste est pris automatiquement en commençant par le lot qui
 * périme le plus tôt (FEFO), puis le plus ancien ; les lots périmés sont écartés. En entrée, les lots
 * sont créés à la volée (date de péremption calculée avec la durée de conservation de l'article).
 */
class LotService
{
    public static function enabled(): bool
    {
        return app()->bound('tenant') && app('tenant')->hasModule('lots');
    }

    public static function tracked(?Product $p): bool
    {
        return $p && self::enabled() && $p->type === 'product' && $p->track_stock && in_array($p->tracking, ['lot', 'serial'], true);
    }

    /** Allocation saisie sur une ligne : [{lot_id?, number?, expiry_date?, quantity}]. */
    public static function cleanSpec(mixed $spec): ?array
    {
        if (! is_array($spec) || ! $spec) {
            return null;
        }
        $out = [];
        foreach (array_slice($spec, 0, 500) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $q = (float) ($s['quantity'] ?? 1);
            $number = isset($s['number']) ? mb_substr(trim((string) $s['number']), 0, 80) : null;
            $lotId = isset($s['lot_id']) && preg_match('/^[0-9a-f-]{36}$/i', (string) $s['lot_id']) ? (string) $s['lot_id'] : null;
            if ($q <= 0 || (! $number && ! $lotId)) {
                continue;
            }
            $expiry = null;
            if (! empty($s['expiry_date'])) {
                try {
                    $expiry = Carbon::parse((string) $s['expiry_date'])->toDateString();
                } catch (\Throwable) {
                    throw new \DomainException('Date de péremption invalide : '.$s['expiry_date'].'.');
                }
            }
            $out[] = array_filter(['lot_id' => $lotId, 'number' => $number, 'expiry_date' => $expiry, 'quantity' => round($q, 4)], fn ($v) => $v !== null);
        }

        return $out ?: null;
    }

    public function blockExpired(): bool
    {
        return (bool) (app('tenant')->setting('lots_block_expired') ?? true);
    }

    /** Quantité d'un lot dans un entrepôt (ou partout). */
    public function available(string $lotId, ?string $warehouseId = null): float
    {
        return round((float) StockMove::where('lot_id', $lotId)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->sum('quantity'), 4);
    }

    public function findOrCreate(Product $p, string $number, ?string $expiry, ?string $date = null): Lot
    {
        $lot = Lot::where('product_id', $p->id)->where('number', $number)->first();
        if ($lot) {
            if ($expiry && ! $lot->expiry_date) {
                $lot->expiry_date = $expiry;
                $lot->save();
            }

            return $lot;
        }
        if (! $expiry && $p->use_expiry && $p->shelf_life_days) {
            $expiry = Carbon::parse($date ?? now())->addDays($p->shelf_life_days)->toDateString();
        }
        $lot = new Lot(['product_id' => $p->id, 'number' => $number, 'expiry_date' => $expiry]);
        $lot->save();

        return $lot;
    }

    private function lotFromSpec(Product $p, array $s, ?string $date): Lot
    {
        if (! empty($s['lot_id'])) {
            $lot = Lot::find($s['lot_id']);
            if (! $lot || $lot->product_id !== $p->id) {
                throw new \DomainException('Lot inconnu pour « '.$p->name.' ».');
            }

            return $lot;
        }

        return $this->findOrCreate($p, $s['number'], $s['expiry_date'] ?? null, $date);
    }

    /**
     * Mouvements (un par lot) pour une quantité signée d'un article.
     *
     * @param  array  $base  attributs communs : type, unit_cost, reference, date, notes, document_id, company_id
     * @param  bool  $strict  entrée d'un article sous numéro de série : numéros obligatoires
     * @return StockMove[]
     */
    public function moves(Product $p, string $warehouseId, float $qty, ?array $spec, array $base, bool $strict = false): array
    {
        $make = function (float $q, ?string $lotId) use ($p, $warehouseId, $base) {
            $m = new StockMove(array_intersect_key($base, array_flip(['type', 'unit_cost', 'reference', 'date', 'notes'])) + [
                'product_id' => $p->id, 'warehouse_id' => $warehouseId, 'quantity' => round($q, 4), 'lot_id' => $lotId,
            ]);
            $m->company_id = $base['company_id'] ?? app('tenant')->id;
            if (! empty($base['document_id'])) {
                $m->document_id = $base['document_id'];
            }
            $m->save();

            return $m;
        };
        if (! self::tracked($p) || abs($qty) < 0.00001) {
            return [$make($qty, null)];
        }
        $date = $base['date'] ?? now()->toDateString();
        $serial = $p->tracking === 'serial';
        $spec = self::cleanSpec($spec) ?? [];
        $out = [];
        $remaining = abs($qty);

        if ($qty > 0) {
            foreach ($spec as $s) {
                if ($remaining <= 0.00001) {
                    break;
                }
                $q = min($serial ? 1 : $s['quantity'], $remaining);
                $lot = $this->lotFromSpec($p, $s, $date);
                if ($serial && $this->available($lot->id) > 0) {
                    throw new \DomainException('Le numéro de série '.$lot->number.' de « '.$p->name.' » est déjà en stock.');
                }
                $out[] = $make($q, $lot->id);
                $remaining -= $q;
            }
            if ($remaining > 0.00001) {
                if ($serial && $strict) {
                    throw new \DomainException('Indiquez les numéros de série de « '.$p->name.' » ('.(int) round(abs($qty) - $remaining).' sur '.(int) round(abs($qty)).').');
                }
                if (! $serial && $strict) {
                    $lot = $this->findOrCreate($p, 'L'.Carbon::parse($date)->format('ymd').'-'.strtoupper(substr(md5(($base['reference'] ?? '').$p->id), 0, 4)), null, $date);
                    $out[] = $make($remaining, $lot->id);
                } else {
                    $out[] = $make($remaining, null); // retour sans lot connu
                }
            }

            return $out;
        }

        // Sortie : lots indiqués, puis FEFO
        $today = now()->toDateString();
        foreach ($spec as $s) {
            if ($remaining <= 0.00001) {
                break;
            }
            $lot = $this->lotFromSpec($p, $s, $date);
            if ($lot->expiry_date && $lot->expiry_date->toDateString() < $today && $this->blockExpired() && ($base['type'] ?? '') !== 'loss') {
                throw new \DomainException('Le lot '.$lot->number.' de « '.$p->name.' » est périmé depuis le '.$lot->expiry_date->format('d/m/Y').'.');
            }
            $q = min($serial ? 1 : $s['quantity'], $remaining);
            $out[] = $make(-$q, $lot->id);
            $remaining -= $q;
        }
        if ($remaining > 0.00001) {
            $taken = collect($out)->groupBy('lot_id')->map(fn ($g) => -$g->sum('quantity'));
            foreach ($this->fefo($p, $warehouseId) as $row) {
                if ($remaining <= 0.00001) {
                    break;
                }
                $avail = $row['quantity'] - ($taken[$row['lot_id']] ?? 0);
                if ($avail <= 0.00001 || ($row['expired'] && $this->blockExpired())) {
                    continue;
                }
                $q = min($avail, $remaining);
                $out[] = $make(-$q, $row['lot_id']);
                $remaining -= $q;
            }
        }
        if ($remaining > 0.00001) {
            $out[] = $make(-$remaining, null); // stock sans lot (ou insuffisant) : signalé dans les écarts
        }

        return $out;
    }

    /** Lots disponibles d'un article dans un entrepôt, du plus urgent au moins urgent. */
    public function fefo(Product $p, string $warehouseId): array
    {
        $today = now()->toDateString();

        return DB::table('stock_moves as m')->join('stock_lots as l', 'l.id', '=', 'm.lot_id')
            ->where('m.company_id', $p->company_id)->where('m.product_id', $p->id)->where('m.warehouse_id', $warehouseId)->whereNull('m.deleted_at')->whereNull('l.deleted_at')
            ->groupBy('l.id', 'l.expiry_date', 'l.created_at', 'l.number')->havingRaw('SUM(m.quantity) > 0.00001')
            ->orderByRaw('CASE WHEN l.expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('l.expiry_date')->orderBy('l.created_at')->orderBy('l.number')
            ->get(['l.id as lot_id', 'l.expiry_date', DB::raw('SUM(m.quantity) as quantity')])
            ->map(fn ($r) => ['lot_id' => $r->lot_id, 'quantity' => (float) $r->quantity, 'expired' => $r->expiry_date && substr($r->expiry_date, 0, 10) < $today])->all();
    }

    /** Mouvement saisi à la main : lot indiqué par son id ou son numéro (créé si besoin). */
    public function manualMove(array &$data): void
    {
        $number = $data['lot_number'] ?? null;
        $expiry = $data['expiry_date'] ?? null;
        unset($data['lot_number'], $data['expiry_date']);
        if (! self::enabled()) {
            unset($data['lot_id']);

            return;
        }
        $p = Product::find($data['product_id'] ?? null);
        if (! self::tracked($p)) {
            unset($data['lot_id']);

            return;
        }
        if ($p->tracking === 'serial' && abs((float) ($data['quantity'] ?? 0)) != 1 && ($data['lot_id'] ?? $number)) {
            throw new \DomainException('Un numéro de série correspond à une seule unité.');
        }
        if (! empty($data['lot_id'])) {
            $lot = Lot::find($data['lot_id']);
            if (! $lot || $lot->product_id !== $p->id) {
                throw new \DomainException('Lot inconnu pour « '.$p->name.' ».');
            }
        } elseif ($number) {
            $data['lot_id'] = $this->findOrCreate($p, mb_substr(trim($number), 0, 80), $expiry ? Carbon::parse($expiry)->toDateString() : null, $data['date'] ?? null)->id;
        }
    }

    // ---------------------------------------------------------------- consultation

    /** Stock par lot (quantité > 0), avec statut de péremption. */
    public function levels(array $f = []): array
    {
        $company = app('tenant');
        $today = now()->startOfDay();
        $defaultAlert = (int) ($company->setting('lots_alert_days') ?? 30);
        $rows = DB::table('stock_moves as m')->join('stock_lots as l', 'l.id', '=', 'm.lot_id')->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->where('m.company_id', $company->id)->whereNull('m.deleted_at')->whereNull('l.deleted_at')
            ->when($f['product_id'] ?? null, fn ($q, $v) => $q->where('m.product_id', $v))
            ->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('m.warehouse_id', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('l.number', 'like', '%'.$v.'%')->orWhere('p.name', 'like', '%'.$v.'%')))
            ->groupBy('l.id', 'l.number', 'l.expiry_date', 'l.supplier_lot', 'm.product_id', 'p.name', 'p.tracking', 'p.expiry_alert_days', 'p.purchase_price', 'm.warehouse_id', 'w.name')
            ->havingRaw('SUM(m.quantity) > 0.00001')
            ->orderByRaw('CASE WHEN l.expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('l.expiry_date')->orderBy('p.name')->orderBy('l.number')
            ->limit(2000)
            ->get(['l.id as lot_id', 'l.number', 'l.expiry_date', 'l.supplier_lot', 'm.product_id', 'p.name as product_name', 'p.tracking', 'p.expiry_alert_days', 'p.purchase_price',
                'm.warehouse_id', 'w.name as warehouse_name', DB::raw('SUM(m.quantity) as quantity')]);
        $out = [];
        foreach ($rows as $r) {
            $days = $r->expiry_date ? (int) $today->diffInDays(Carbon::parse($r->expiry_date)->startOfDay(), false) : null;
            $alert = $r->expiry_alert_days ?? $defaultAlert;
            $status = $days === null ? 'ok' : ($days < 0 ? 'expired' : ($days <= $alert ? 'expiring' : 'ok'));
            if (! empty($f['status']) && $f['status'] !== 'all' && $f['status'] !== $status) {
                continue;
            }
            $out[] = ['lot_id' => $r->lot_id, 'number' => $r->number, 'supplier_lot' => $r->supplier_lot, 'expiry_date' => $r->expiry_date ? substr($r->expiry_date, 0, 10) : null,
                'days_left' => $days, 'status' => $status, 'product_id' => $r->product_id, 'product_name' => $r->product_name, 'tracking' => $r->tracking,
                'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse_name, 'quantity' => round((float) $r->quantity, 4),
                'value' => round((float) $r->quantity * (float) $r->purchase_price, 2)];
        }

        return $out;
    }

    /** Traçabilité d'un lot : entrées, sorties, clients et fournisseurs concernés. */
    public function trace(Lot $lot): array
    {
        $moves = StockMove::where('lot_id', $lot->id)->orderBy('date')->orderBy('created_at')->get();
        $docIds = $moves->pluck('document_id')->filter()->unique();
        $docs = DB::table('trade_documents')->whereIn('id', $docIds)->get(['id', 'direction', 'type', 'number', 'partner_name', 'date'])->keyBy('id');
        $receipts = class_exists(\App\Modules\Achats\Models\PurchaseReceipt::class) ? DB::table('purchase_receipts')->whereIn('id', $docIds)->get(['id', 'number', 'order_id'])->keyBy('id') : collect();
        $wh = DB::table('warehouses')->whereIn('id', $moves->pluck('warehouse_id')->unique())->pluck('name', 'id');
        $partners = [];
        $rows = [];
        foreach ($moves as $m) {
            $d = $docs[$m->document_id] ?? null;
            $label = $d ? (['invoice' => 'Facture', 'receipt' => 'Ticket', 'credit_note' => 'Avoir', 'supplier_invoice' => 'Facture fournisseur'][$d->type] ?? $d->type).' '.$d->number
                : (isset($receipts[$m->document_id]) ? 'Réception '.$receipts[$m->document_id]->number : ($m->reference ?: null));
            if ($d?->partner_name) {
                $key = $d->direction.'|'.$d->partner_name;
                $partners[$key] ??= ['direction' => $d->direction, 'name' => $d->partner_name, 'quantity' => 0.0];
                $partners[$key]['quantity'] += abs((float) $m->quantity);
            }
            $rows[] = ['date' => $d?->date ? substr((string) $d->date, 0, 10) : $m->date?->toDateString(), 'type' => $m->type, 'quantity' => (float) $m->quantity, 'warehouse' => $wh[$m->warehouse_id] ?? null, 'document' => $label, 'notes' => $m->notes];
        }

        return ['lot' => $lot->toSyncArray() + ['product_name' => $lot->product?->name, 'tracking' => $lot->product?->tracking], 'moves' => $rows,
            'stock' => round($moves->sum('quantity'), 4), 'partners' => array_values($partners)];
    }

    /** Mise au rebut des lots périmés (ou choisis) : mouvements de perte. */
    public function scrap(array $lotIds = [], ?string $warehouseId = null): int
    {
        $rows = collect($this->levels(['warehouse_id' => $warehouseId]))->filter(fn ($r) => $lotIds ? in_array($r['lot_id'], $lotIds, true) : $r['status'] === 'expired');
        $n = 0;
        DB::transaction(function () use ($rows, &$n) {
            foreach ($rows as $r) {
                $m = new StockMove(['product_id' => $r['product_id'], 'warehouse_id' => $r['warehouse_id'], 'quantity' => -$r['quantity'], 'type' => 'loss', 'lot_id' => $r['lot_id'],
                    'reference' => 'REBUT-'.now()->format('Ymd'), 'date' => now()->toDateString(), 'notes' => 'Mise au rebut du lot '.$r['number'].($r['status'] === 'expired' ? ' (périmé)' : '')]);
                $m->save();
                $n++;
            }
        });

        return $n;
    }

    /** Alerte quotidienne : lots périmés ou proches de la péremption (une fois par lot et par seuil). */
    public function alert(Company $company): int
    {
        $rows = collect($this->levels())->whereIn('status', ['expired', 'expiring'])->groupBy('lot_id');
        $lots = Lot::whereIn('id', $rows->keys())->get()->keyBy('id');
        $fresh = [];
        foreach ($rows as $id => $g) {
            $lot = $lots[$id] ?? null;
            $status = $g->first()['status'];
            if (! $lot || ($lot->alerted_at && ($status === 'expiring' || $lot->alerted_at->gte($lot->expiry_date)))) {
                continue;
            }
            $fresh[] = $g->first();
            $lot->forceFill(['alerted_at' => now()])->saveQuietly();
        }
        if (! $fresh) {
            return 0;
        }
        $users = \App\Models\User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => ! $u->is_super_admin && $u->hasPermission('stock.manage'))->pluck('id');
        $lines = array_map(fn ($r) => $r['product_name'].' — lot '.$r['number'].' : '.($r['status'] === 'expired' ? 'périmé depuis '.abs($r['days_left']).' j' : 'périme dans '.$r['days_left'].' j'), array_slice($fresh, 0, 15));
        AppNotification::send($company->id, $users, 'stock', count($fresh).' lot(s) périmé(s) ou bientôt périmé(s)', implode("\n", $lines).(count($fresh) > 15 ? "\n…" : ''), 'lots');

        return count($fresh);
    }
}
