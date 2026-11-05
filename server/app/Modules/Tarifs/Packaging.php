<?php

namespace App\Modules\Tarifs;

use App\Modules\Core\Models\Product;

/** Conditionnements d'un article (carton de 24, pack de 6…) avec leur code-barres et leurs prix. */
class Packaging
{
    public static function clean(mixed $list, ?string $productId, ?string $ownBarcode): ?array
    {
        $out = [];
        $seen = [];
        foreach (array_slice((array) $list, 0, 10) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 40);
            $factor = round((float) str_replace(',', '.', (string) ($p['factor'] ?? 0)), 4);
            if ($name === '') {
                continue;
            }
            if ($factor <= 0 || $factor == 1.0) {
                throw new \DomainException('Conditionnement « '.$name.' » : indiquez le nombre d\'unités qu\'il contient (plus de 1).');
            }
            if (isset($seen[mb_strtolower($name)])) {
                throw new \DomainException('Deux conditionnements portent le nom « '.$name.' ».');
            }
            $seen[mb_strtolower($name)] = true;
            $barcode = trim((string) ($p['barcode'] ?? '')) ?: null;
            if ($barcode) {
                if ($barcode === $ownBarcode || in_array($barcode, array_column($out, 'barcode'), true)
                    || Product::where('barcode', $barcode)->when($productId, fn ($q) => $q->where('id', '<>', $productId))->exists() || self::barcodeOwner($barcode, $productId)) {
                    throw new \DomainException('Le code-barres '.$barcode.' est déjà utilisé.');
                }
            }
            $price = fn ($k) => isset($p[$k]) && $p[$k] !== '' && $p[$k] !== null ? max(0, round((float) $p[$k], 2)) : null;
            $out[] = array_filter(['name' => $name, 'factor' => $factor, 'barcode' => $barcode, 'sale_price' => $price('sale_price'), 'purchase_price' => $price('purchase_price')], fn ($v) => $v !== null);
        }

        return $out ?: null;
    }

    /** Article dont un conditionnement porte ce code-barres (autre que $exceptId). */
    public static function barcodeOwner(string $barcode, ?string $exceptId = null): ?string
    {
        foreach (Product::whereNotNull('packagings')->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->get(['id', 'packagings']) as $p) {
            foreach ($p->packagings ?? [] as $pk) {
                if (($pk['barcode'] ?? null) === $barcode) {
                    return $p->id;
                }
            }
        }

        return null;
    }
}
