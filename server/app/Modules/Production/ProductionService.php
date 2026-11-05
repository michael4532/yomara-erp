<?php

namespace App\Modules\Production;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\TradeDocument;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    /** Coût horaire de la main-d'œuvre (réglage société). */
    public function labourRate(?Company $company = null): float
    {
        $company ??= app('tenant');

        return (float) ($company->settings['mrp_labour_rate'] ?? 0);
    }

    /** Composants nécessaires pour `qty` unités, pertes comprises. */
    public function componentsFor(Bom $bom, float $qty): array
    {
        $products = Product::whereIn('id', array_column($bom->lines ?? [], 'product_id'))->get()->keyBy('id');

        return collect($bom->lines ?? [])->map(function ($l) use ($bom, $qty, $products) {
            $need = $l['quantity'] * (1 + ($l['loss_percent'] ?? 0) / 100) * $qty / $bom->quantity;

            return ['product_id' => $l['product_id'], 'description' => $products[$l['product_id']]->name ?? '', 'unit' => $products[$l['product_id']]->unit ?? null, 'planned' => round($need, 4), 'consumed' => null];
        })->values()->all();
    }

    /** Un composant (ou ses propres composants) est-il fabriqué à partir de ce produit ? */
    public function createsLoop(string $productId, array $componentIds, ?string $ignoreBom = null, int $depth = 0): bool
    {
        if ($depth > 10 || ! $componentIds) {
            return $depth > 10;
        }
        $boms = Bom::whereIn('product_id', $componentIds)->where('active', true)->when($ignoreBom, fn ($q) => $q->where('id', '!=', $ignoreBom))->get();
        foreach ($boms as $b) {
            $ids = array_column($b->lines ?? [], 'product_id');
            if (in_array($productId, $ids, true) || $this->createsLoop($productId, $ids, $ignoreBom, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /** Coût d'un composant : prix d'achat, ou coût de sa propre nomenclature s'il est fabriqué sans prix connu. */
    public function componentCost(Product $p, int $depth = 0): float
    {
        if ($p->purchase_price > 0 || $depth > 5) {
            return (float) $p->purchase_price;
        }
        $bom = Bom::where('product_id', $p->id)->where('active', true)->first();

        return $bom ? $this->bomCost($bom, $depth + 1)['unit'] : 0.0;
    }

    /** Coût de revient d'une unité : matières, main-d'œuvre, frais ; marge sur le prix de vente HT. */
    public function bomCost(Bom $bom, int $depth = 0): array
    {
        $products = Product::whereIn('id', array_column($bom->lines ?? [], 'product_id'))->get()->keyBy('id');
        $material = 0;
        $lines = [];
        foreach ($bom->lines ?? [] as $l) {
            $p = $products[$l['product_id']] ?? null;
            $unit = $p ? $this->componentCost($p, $depth) : 0;
            $q = $l['quantity'] * (1 + ($l['loss_percent'] ?? 0) / 100);
            $material += $q * $unit;
            $lines[] = ['product_id' => $l['product_id'], 'name' => $p?->name, 'quantity' => $q, 'unit_cost' => $unit, 'cost' => round($q * $unit, 2)];
        }
        $labour = $bom->labour_minutes / 60 * $this->labourRate();
        $unit = ($material + $labour + $bom->extra_cost) / $bom->quantity;
        $product = Product::find($bom->product_id);
        $price = (float) ($product->sale_price ?? 0);

        return [
            'material' => round($material / $bom->quantity, 2),
            'labour' => round($labour / $bom->quantity, 2),
            'extra' => round($bom->extra_cost / $bom->quantity, 2),
            'unit' => round($unit, 2),
            'sale_price' => $price,
            'margin' => round($price - $unit, 2),
            'margin_rate' => $price > 0 ? round(($price - $unit) / $price * 100, 1) : null,
            'cost_rate' => $price > 0 ? round($unit / $price * 100, 1) : null, // « food cost » en restauration
            'lines' => $lines,
        ];
    }

    /** Fin de fabrication : sorties des composants, entrée du produit fini, coûts. */
    public function complete(ProductionOrder $o): void
    {
        DB::transaction(function () use ($o) {
            $wh = $o->warehouse_id ?: Warehouse::defaultFor($o->company_id)->id;
            $ratio = $o->quantity > 0 ? $o->produced_qty / $o->quantity : 1;
            $products = Product::whereIn('id', collect($o->components)->pluck('product_id')->push($o->product_id))->get()->keyBy('id');
            $components = [];
            $material = 0;
            foreach ($o->components ?? [] as $c) {
                $p = $products[$c['product_id']] ?? null;
                $consumed = round((float) ($c['consumed'] ?? $c['planned'] * $ratio), 4);
                $unitCost = $p ? $this->componentCost($p) : 0;
                $material += $consumed * $unitCost;
                $components[] = array_merge($c, ['consumed' => $consumed, 'unit_cost' => $unitCost]);
                if ($p && $p->type === 'product' && $p->track_stock && $consumed > 0) {
                    $this->move($o->company_id, $p->id, $wh, -$consumed, 'consumption', $unitCost, $o->number, $o->id, 'Fabrication '.$o->number);
                }
            }
            $labour = $o->labour_minutes / 60 * $this->labourRate(Company::find($o->company_id));
            $bom = Bom::find($o->bom_id);
            $extra = $bom ? $bom->extra_cost * $o->quantity / $bom->quantity : 0;
            $unit = $o->produced_qty > 0 ? ($material + $labour + $extra) / $o->produced_qty : 0;

            // Coût moyen pondéré du produit fini
            $finished = $products[$o->product_id];
            $stock = (float) StockMove::where('product_id', $finished->id)->sum('quantity');
            $avg = $stock > 0 && $finished->purchase_price > 0 ? ($stock * $finished->purchase_price + $o->produced_qty * $unit) / ($stock + $o->produced_qty) : $unit;
            $this->move($o->company_id, $finished->id, $wh, $o->produced_qty, 'production', $unit, $o->number, $o->id, 'Fabrication '.$o->number);
            $finished->forceFill(['purchase_price' => round($avg, 2)])->save();

            $o->forceFill([
                'components' => $components, 'done_at' => now(),
                'material_cost' => round($material, 2), 'labour_cost' => round($labour + $extra, 2), 'unit_cost' => round($unit, 4),
            ])->save();

            if ($o->user_id && $o->user_id !== auth()->id()) {
                AppNotification::send($o->company_id, [$o->user_id], 'production', 'Fabrication terminée : '.$o->number,
                    rtrim(rtrim(number_format($o->produced_qty, 3, ',', ' '), '0'), ',').' × '.$finished->name, 'production', $o->id);
            }
        });
    }

    protected function move(int $companyId, string $productId, string $wh, float $qty, string $type, float $unitCost, ?string $ref, string $docId, string $notes): void
    {
        // Lots : composants pris par péremption (FEFO), produit fini rangé dans le lot de l'ordre de fabrication.
        $p = \App\Modules\Core\Models\Product::withoutGlobalScopes()->find($productId);
        if ($p && \App\Modules\Lots\LotService::tracked($p)) {
            app(\App\Modules\Lots\LotService::class)->moves($p, $wh, $qty, $qty > 0 && $type === 'production' && $p->tracking === 'lot' ? [['number' => $ref ?: 'OF', 'quantity' => $qty]] : null, [
                'type' => $type, 'unit_cost' => round($unitCost, 2), 'reference' => $ref, 'date' => now()->toDateString(), 'notes' => $notes, 'company_id' => $companyId, 'document_id' => $docId,
            ]);

            return;
        }
        $m = new StockMove(['product_id' => $productId, 'warehouse_id' => $wh, 'quantity' => $qty, 'type' => $type, 'unit_cost' => round($unitCost, 2),
            'reference' => $ref, 'date' => now()->toDateString(), 'notes' => $notes]);
        $m->company_id = $companyId;
        $m->document_id = $docId;
        $m->save();
    }

    // ---------- Recettes consommées à la vente ----------

    /** Ingrédients des articles vendus (plats, kits) sortis du stock à la validation du ticket ou de la facture. */
    public function consumeSale(TradeDocument $doc, bool $reverse = false): void
    {
        if (! in_array($doc->type, ['invoice', 'receipt', 'credit_note'], true) || $doc->direction !== 'sale') {
            return;
        }
        $net = (float) StockMove::withoutGlobalScopes()->where('document_id', $doc->id)->whereIn('type', ['consumption', 'consumption_return'])->sum('quantity');
        $done = StockMove::withoutGlobalScopes()->where('document_id', $doc->id)->whereIn('type', ['consumption', 'consumption_return'])->exists();
        if (! $reverse && $done) {
            return; // déjà fait
        }
        if ($reverse && (! $done || abs($net) < 0.00001)) {
            return; // rien à remettre
        }
        $sign = $doc->type === 'credit_note' ? 1 : -1;
        $wh = $doc->warehouse_id ?: Warehouse::defaultFor($doc->company_id, $doc->branch_id)->id;
        $lines = $doc->relationLoaded('lines') ? $doc->lines : $doc->lines()->get();
        $boms = Bom::withoutGlobalScopes()->where('company_id', $doc->company_id)->where('kind', 'kit')->where('active', true)
            ->whereIn('product_id', $lines->pluck('product_id')->filter())->get()->keyBy('product_id');
        if ($boms->isEmpty()) {
            return;
        }
        $components = Product::withoutGlobalScopes()->whereIn('id', $boms->flatMap(fn ($b) => array_column($b->lines ?? [], 'product_id')))->get()->keyBy('id');
        $totals = [];
        foreach ($lines as $line) {
            $bom = $boms[$line->product_id] ?? null;
            if (! $bom || $line->quantity == 0) {
                continue;
            }
            foreach ($bom->lines ?? [] as $l) {
                $p = $components[$l['product_id']] ?? null;
                if (! $p || $p->type !== 'product' || ! $p->track_stock) {
                    continue;
                }
                $totals[$p->id] = ($totals[$p->id] ?? 0) + $l['quantity'] * (1 + ($l['loss_percent'] ?? 0) / 100) * abs($line->quantity) / $bom->quantity;
            }
        }
        DB::transaction(function () use ($totals, $components, $doc, $wh, $sign, $reverse) {
            foreach ($totals as $pid => $q) {
                $qty = round($q, 4) * ($reverse ? -$sign : $sign);
                $this->move($doc->company_id, $pid, $wh, $qty, $reverse ? 'consumption_return' : 'consumption', (float) $components[$pid]->purchase_price,
                    $doc->number, $doc->id, ($reverse ? 'Annulation recette ' : 'Recette ').($doc->number ?? ''));
            }
        });
    }

    // ---------- Besoins ----------

    /** Composants nécessaires aux ordres confirmés ou en cours, face au stock disponible. */
    public function needs(): array
    {
        $orders = ProductionOrder::whereIn('status', ['confirmed', 'in_progress'])->get();
        $need = [];
        $by = [];
        foreach ($orders as $o) {
            foreach ($o->components ?? [] as $c) {
                $need[$c['product_id']] = ($need[$c['product_id']] ?? 0) + (float) ($c['consumed'] ?? $c['planned']);
                $by[$c['product_id']][] = $o->number;
            }
        }
        if (! $need) {
            return [];
        }
        $stock = StockMove::whereIn('product_id', array_keys($need))->selectRaw('product_id, SUM(quantity) as q')->groupBy('product_id')->pluck('q', 'product_id');
        $products = Product::whereIn('id', array_keys($need))->get()->keyBy('id');

        return collect($need)->map(fn ($q, $pid) => [
            'product_id' => $pid,
            'name' => $products[$pid]->name ?? '',
            'unit' => $products[$pid]->unit ?? null,
            'needed' => round($q, 3),
            'stock' => round((float) ($stock[$pid] ?? 0), 3),
            'missing' => round(max(0, $q - (float) ($stock[$pid] ?? 0)), 3),
            'orders' => array_values(array_unique($by[$pid])),
            'cost' => round(max(0, $q - (float) ($stock[$pid] ?? 0)) * ($products[$pid]->purchase_price ?? 0), 0),
        ])->sortByDesc('missing')->values()->all();
    }
}
