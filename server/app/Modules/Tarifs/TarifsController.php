<?php

namespace App\Modules\Tarifs;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Product;
use Illuminate\Http\Request;

class TarifsController extends Controller
{
    /** Prix applicables à des lignes (autres logiciels, vérification). */
    public function resolve(Request $r, PriceService $svc)
    {
        $v = $r->validate(['partner_id' => 'nullable|uuid', 'date' => 'nullable|date', 'lines' => 'required|array|max:500', 'lines.*.product_id' => 'required|uuid',
            'lines.*.quantity' => 'required|numeric|min:0', 'lines.*.packaging' => 'nullable|string|max:40']);
        $products = Product::whereIn('id', array_column($v['lines'], 'product_id'))->get()->keyBy('id');
        $out = [];
        foreach ($v['lines'] as $l) {
            $p = $products->get($l['product_id']);
            $out[] = $p ? ['product_id' => $p->id] + $svc->resolve($v['partner_id'] ?? null, $p, (float) $l['quantity'], $l['packaging'] ?? null, $v['date'] ?? null) : ['product_id' => $l['product_id'], 'error' => 'Article inconnu'];
        }

        return ['data' => $out];
    }
}
