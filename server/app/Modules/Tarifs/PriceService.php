<?php

namespace App\Modules\Tarifs;

use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Modules\Tarifs\Models\PriceList;

/**
 * Prix applicable à un client : liste du client, sinon liste par défaut (actives et en vigueur à la date).
 * Ligne la plus précise d'abord (article › catégorie › tout), puis la quantité minimale la plus élevée atteinte.
 * Même logique dans l'application (hors ligne).
 */
class PriceService
{
    public function listFor(?string $partnerId, ?string $date = null): ?PriceList
    {
        $date ??= now()->toDateString();
        $ok = fn (?PriceList $l) => $l && $l->active && (! $l->start_date || $l->start_date->toDateString() <= $date) && (! $l->end_date || $l->end_date->toDateString() >= $date);
        $id = $partnerId ? Partner::where('id', $partnerId)->value('price_list_id') : null;
        if ($id && $ok($l = PriceList::find($id))) {
            return $l;
        }
        $l = PriceList::where('is_default', true)->first();

        return $ok($l) ? $l : null;
    }

    /**
     * @return array{unit_price: float, discount_percent: float, list_id: ?string, label: ?string}
     */
    public function resolve(?string $partnerId, Product $p, float $qty, ?string $packaging = null, ?string $date = null): array
    {
        $factor = 1.0;
        $base = (float) $p->sale_price;
        foreach ($p->packagings ?? [] as $pk) {
            if ($packaging && $pk['name'] === $packaging) {
                $factor = (float) $pk['factor'];
                $base = isset($pk['sale_price']) ? (float) $pk['sale_price'] : round($base * $factor, 2);
            }
        }
        $out = ['unit_price' => $base, 'discount_percent' => 0.0, 'list_id' => null, 'label' => null];
        $list = $this->listFor($partnerId, $date);
        if (! $list) {
            return $out;
        }
        $units = $qty * $factor; // quantité minimale exprimée en unités de base
        $best = null;
        $rank = fn ($it) => ['product' => 3, 'category' => 2, 'all' => 1][$it['scope']] * 1e9 + ($it['min_qty'] ?? 0);
        foreach ($list->items ?? [] as $it) {
            $match = match ($it['scope']) {
                'product' => $it['product_id'] === $p->id && (empty($it['packaging']) || $it['packaging'] === $packaging),
                'category' => $p->category_id && $it['category_id'] === $p->category_id,
                default => true,
            };
            $minUnits = ! empty($it['packaging']) ? (float) $it['min_qty'] * $factor : (float) $it['min_qty'];
            if ($match && $units + 0.00001 >= $minUnits && (! $best || $rank($it) > $rank($best))) {
                $best = $it;
            }
        }
        if (! $best) {
            return $out;
        }
        if ($best['mode'] === 'price') {
            $price = ! empty($best['packaging']) ? (float) $best['price'] : (float) $best['price'] * $factor;
            $label = $list->name.($best['min_qty'] > 0 ? ' (dès '.rtrim(rtrim(number_format($best['min_qty'], 2, ',', ' '), '0'), ',').')' : '');

            return ['unit_price' => round($price, 2), 'discount_percent' => 0.0, 'list_id' => $list->id, 'label' => $label];
        }

        return ['unit_price' => $base, 'discount_percent' => (float) $best['discount'], 'list_id' => $list->id,
            'label' => $list->name.' : −'.rtrim(rtrim(number_format($best['discount'], 2, ',', ' '), '0'), ',').' %'.($best['min_qty'] > 0 ? ' dès '.rtrim(rtrim(number_format($best['min_qty'], 2, ',', ' '), '0'), ',') : '')];
    }
}
