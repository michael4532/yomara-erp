<?php

namespace App\Modules\Stock;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Product;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockController extends Controller
{
    public function levels(Request $request, StockService $stock)
    {
        return ['data' => $stock->levels($request->query('warehouse_id'))];
    }

    /** Transfert entre entrepôts (deux mouvements liés). */
    public function transfer(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|uuid',
            'from_warehouse_id' => 'required|uuid|different:to_warehouse_id',
            'to_warehouse_id' => 'required|uuid',
            'quantity' => 'required|numeric|gt:0',
            'notes' => 'nullable|string',
            'lot_id' => 'nullable|uuid',
        ]);
        Product::findOrFail($data['product_id']);
        if (! empty($data['lot_id']) && \App\Modules\Lots\Models\Lot::where('id', $data['lot_id'])->where('product_id', $data['product_id'])->doesntExist()) {
            return response()->json(['message' => 'Lot inconnu pour cet article.'], 422);
        }
        $lot = $data['lot_id'] ?? null;
        Warehouse::findOrFail($data['from_warehouse_id']);
        // vers une autre agence : l'entrepôt de destination peut être hors des agences de l'utilisateur
        Warehouse::withoutGlobalScope('branch')->findOrFail($data['to_warehouse_id']);

        $ref = 'TR-'.strtoupper(Str::random(6));
        $moves = DB::transaction(fn () => [
            StockMove::create(['product_id' => $data['product_id'], 'warehouse_id' => $data['from_warehouse_id'], 'quantity' => -$data['quantity'], 'type' => 'transfer_out', 'reference' => $ref, 'date' => now()->toDateString(), 'notes' => $data['notes'] ?? null, 'lot_id' => $lot]),
            StockMove::create(['product_id' => $data['product_id'], 'warehouse_id' => $data['to_warehouse_id'], 'quantity' => $data['quantity'], 'type' => 'transfer_in', 'reference' => $ref, 'date' => now()->toDateString(), 'notes' => $data['notes'] ?? null, 'lot_id' => $lot]),
        ]);

        return response()->json(['data' => $moves], 201);
    }

    /** Inventaire : on saisit les quantités comptées, l'écart devient un ajustement. */
    public function inventory(Request $request, StockService $stock)
    {
        $data = $request->validate([
            'warehouse_id' => 'required|uuid',
            'counts' => 'required|array|min:1',
            'counts.*.product_id' => 'required|uuid',
            'counts.*.quantity' => 'required|numeric|min:0',
            'counts.*.lot_id' => 'nullable|uuid',
        ]);
        Warehouse::findOrFail($data['warehouse_id']);
        $current = $stock->levels($data['warehouse_id'])->keyBy('product_id');
        // Comptage par lot : écart calculé sur le lot
        $byLot = StockMove::where('warehouse_id', $data['warehouse_id'])->whereNotNull('lot_id')->selectRaw('lot_id, SUM(quantity) as q')->groupBy('lot_id')->get()->pluck('q', 'lot_id');
        $ref = 'INV-'.now()->format('Ymd-His');

        $moves = DB::transaction(function () use ($data, $current, $ref, $byLot) {
            $out = [];
            foreach ($data['counts'] as $c) {
                $lot = $c['lot_id'] ?? null;
                $diff = round($c['quantity'] - ($lot ? (float) ($byLot[$lot] ?? 0) : ($current[$c['product_id']]['quantity'] ?? 0)), 3);
                if ($diff != 0) {
                    $out[] = StockMove::create([
                        'product_id' => $c['product_id'], 'warehouse_id' => $data['warehouse_id'],
                        'quantity' => $diff, 'type' => 'adjustment', 'reference' => $ref,
                        'date' => now()->toDateString(), 'notes' => 'Inventaire', 'lot_id' => $lot,
                    ]);
                }
            }

            return $out;
        });

        return ['data' => $moves, 'reference' => $ref];
    }
}
