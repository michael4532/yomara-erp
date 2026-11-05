<?php

namespace App\Modules\Variantes;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Product;
use Illuminate\Http\Request;

class VariantesController extends Controller
{
    public function __construct(private VariantService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(string $id)
    {
        $tpl = Product::findOrFail($id);
        $variants = Product::where('parent_id', $tpl->id)->orderBy('name')->get();
        $stock = \App\Modules\Stock\Models\StockMove::whereIn('product_id', $variants->pluck('id'))->selectRaw('product_id, SUM(quantity) as q')->groupBy('product_id')->pluck('q', 'product_id');

        return ['data' => ['template' => $tpl->toSyncArray(), 'combinations' => count(VariantService::combinations($tpl->variant_attributes ?? [])),
            'variants' => $variants->map(fn ($v) => $v->toSyncArray() + ['stock' => round((float) ($stock[$v->id] ?? 0), 3)])]];
    }

    public function generate(Request $r, string $id)
    {
        $v = $r->validate(['only' => 'nullable|array']);

        return $this->guard(fn () => response()->json(['data' => array_map(fn ($p) => $p->toSyncArray(), $this->svc->generate(Product::findOrFail($id), $v['only'] ?? null))], 201));
    }

    public function propagate(Request $r, string $id)
    {
        $v = $r->validate(['fields' => 'required|array|min:1', 'fields.*' => 'in:'.implode(',', VariantService::PROPAGATE)]);

        return $this->guard(fn () => ['data' => ['updated' => $this->svc->propagate(Product::findOrFail($id), $v['fields'])]]);
    }
}
