<?php

namespace App\Modules\Stock;

use App\Models\Company;
use App\Modules\Core\Models\Product;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $levels = app(StockService::class)->levels()->groupBy('product_id')->map(fn ($rows) => $rows->sum('quantity'));
        $products = Product::where('type', 'product')->where('track_stock', true)->where('active', true)->get(['id', 'min_stock', 'purchase_price']);

        $low = $products->filter(fn ($p) => ($levels[$p->id] ?? 0) <= $p->min_stock)->count();
        $value = $products->sum(fn ($p) => max(0, $levels[$p->id] ?? 0) * $p->purchase_price);

        return [
            ['key' => 'low_stock', 'label' => 'Produits en alerte de stock', 'value' => $low, 'format' => 'number', 'icon' => 'production_quantity_limits', 'alert' => $low > 0],
            ['key' => 'stock_value', 'label' => 'Valeur du stock (achat)', 'value' => round($value, 2), 'format' => 'money', 'icon' => 'inventory'],
        ];
    }
}
