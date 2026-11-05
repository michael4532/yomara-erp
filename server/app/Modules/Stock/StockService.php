<?php

namespace App\Modules\Stock;

use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\TradeDocument;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class StockService
{
    /** Sens du mouvement selon le type de document (null = pas d'effet sur le stock). */
    public const DOCUMENT_EFFECT = [
        'sale.invoice' => ['sale', -1],
        'sale.receipt' => ['sale', -1],
        'sale.credit_note' => ['return', +1],
        'purchase.supplier_invoice' => ['purchase', +1],
    ];

    public function postDocument(TradeDocument $doc, bool $reverse = false): void
    {
        $effect = self::DOCUMENT_EFFECT[$doc->direction.'.'.$doc->type] ?? null;
        if (! $effect || $doc->stock_posted === ! $reverse) {
            return;
        }
        // Une facture issue d'un document déjà sorti du stock ne ressort pas.
        if (! $reverse && $doc->source_id && $doc->newQuery()->withoutGlobalScopes()->where('id', $doc->source_id)->value('stock_posted')) {
            return;
        }

        // Annulation d'un document dont le stock a été sorti ailleurs (ex. pièces d'une intervention) : rien à remettre
        if ($reverse && ! StockMove::withoutGlobalScopes()->where('document_id', $doc->id)->exists()) {
            $doc->stock_posted = false;
            $doc->saveQuietly();

            return;
        }

        [$type, $sign] = $effect;
        $warehouseId = $doc->warehouse_id ?: Warehouse::defaultFor($doc->company_id, $doc->branch_id)->id;

        DB::transaction(function () use ($doc, $type, $sign, $warehouseId, $reverse) {
            if ($reverse) {
                // Annulation : mouvements inverses de ceux du document (par article, entrepôt et lot)
                foreach (StockMove::withoutGlobalScopes()->where('company_id', $doc->company_id)->where('document_id', $doc->id)->where('type', $type)->whereNull('deleted_at')->get()->groupBy(fn ($m) => $m->product_id.'|'.$m->warehouse_id.'|'.$m->lot_id) as $g) {
                    $qty = round($g->sum('quantity'), 4);
                    if (abs($qty) < 0.00001) {
                        continue;
                    }
                    $f = $g->first();
                    $move = new StockMove(['product_id' => $f->product_id, 'warehouse_id' => $f->warehouse_id, 'quantity' => -$qty, 'type' => 'adjustment', 'lot_id' => $f->lot_id,
                        'unit_cost' => $f->unit_cost, 'reference' => $doc->number, 'date' => now()->toDateString(), 'notes' => 'Annulation '.$doc->number]);
                    $move->company_id = $doc->company_id;
                    $move->document_id = $doc->id;
                    $move->save();
                }
                $doc->stock_posted = false;
                $doc->saveQuietly();

                return;
            }
            $products = Product::withoutGlobalScopes()->whereIn('id', $doc->lines->pluck('product_id')->filter())->get()->keyBy('id');
            $lots = app(\App\Modules\Lots\LotService::class);

            foreach ($doc->lines as $line) {
                $p = $line->product_id ? $products->get($line->product_id) : null;
                if (! $p || $p->type !== 'product' || ! $p->track_stock || $line->quantity == 0) {
                    continue;
                }
                $qty = $sign * abs($line->stockQuantity());
                $factor = (float) ($line->unit_factor ?: 1);
                $lots->moves($p, $warehouseId, $qty, $line->lots, [
                    'type' => $type,
                    'unit_cost' => $doc->direction === 'purchase' ? round($line->unit_price / $factor, 4) : $p->purchase_price,
                    'reference' => $doc->number,
                    'date' => now()->toDateString(),
                    'company_id' => $doc->company_id,
                    'document_id' => $doc->id,
                ], strict: $doc->direction === 'purchase');
            }

            $doc->stock_posted = true;
            $doc->saveQuietly(); // pas de nouvelle version pour ce simple drapeau
        });
    }

    /** Niveaux de stock : [ {product_id, warehouse_id, quantity} ] */
    public function levels(?string $warehouseId = null)
    {
        return StockMove::query()
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('product_id, warehouse_id, SUM(quantity) as quantity')
            ->groupBy('product_id', 'warehouse_id')
            ->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'warehouse_id' => $r->warehouse_id, 'quantity' => round((float) $r->quantity, 3)]);
    }
}
