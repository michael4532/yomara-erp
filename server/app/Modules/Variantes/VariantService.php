<?php

namespace App\Modules\Variantes;

use App\Modules\Core\Models\Product;
use App\Services\EntityWriter;
use Illuminate\Support\Str;

/**
 * Variantes : un article « modèle » (T-shirt) porte des attributs (Taille : S, M, L ; Couleur : Rouge, Bleu).
 * Chaque combinaison devient un article ordinaire (stock, code-barres, prix propres) relié à son modèle.
 */
class VariantService
{
    public const MAX_COMBINATIONS = 300;

    public static function cleanTemplate(array &$data, Product $p): void
    {
        if (array_key_exists('variant_attributes', $data)) {
            $attrs = [];
            $names = [];
            foreach (array_slice((array) $data['variant_attributes'], 0, 3) as $a) {
                $name = mb_substr(trim((string) ($a['name'] ?? '')), 0, 40);
                $values = array_values(array_unique(array_filter(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 40), (array) ($a['values'] ?? [])), fn ($v) => $v !== '')));
                if ($name === '' && ! $values) {
                    continue;
                }
                if ($name === '' || ! $values) {
                    throw new \DomainException('Chaque attribut a un nom (ex. Taille) et au moins une valeur.');
                }
                if (isset($names[mb_strtolower($name)])) {
                    throw new \DomainException('Deux attributs portent le nom « '.$name.' ».');
                }
                $names[mb_strtolower($name)] = true;
                $attrs[] = ['name' => $name, 'values' => array_slice($values, 0, 30)];
            }
            $n = array_product(array_map(fn ($a) => count($a['values']), $attrs ?: [['values' => [1]]]));
            if ($n > self::MAX_COMBINATIONS) {
                throw new \DomainException($n.' combinaisons : '.self::MAX_COMBINATIONS.' au maximum.');
            }
            $data['variant_attributes'] = $attrs ?: null;
            if ($p->exists && $p->is_template && Product::where('parent_id', $p->id)->exists()) {
                // Une valeur utilisée par une variante existante ne peut pas disparaître.
                foreach (Product::where('parent_id', $p->id)->get(['variant_values', 'name']) as $v) {
                    foreach ($v->variant_values ?? [] as $an => $av) {
                        $attr = collect($attrs)->firstWhere('name', $an);
                        if (! $attr || ! in_array($av, $attr['values'], true)) {
                            throw new \DomainException('« '.$an.' : '.$av.' » est utilisé par la variante « '.$v->name.' ».');
                        }
                    }
                }
            }
        }
        if (array_key_exists('price_extras', $data)) {
            $extras = [];
            foreach ((array) $data['price_extras'] as $attr => $vals) {
                foreach ((array) $vals as $val => $amount) {
                    if (is_numeric($amount) && (float) $amount != 0.0) {
                        $extras[mb_substr((string) $attr, 0, 40)][mb_substr((string) $val, 0, 40)] = round((float) $amount, 2);
                    }
                }
            }
            $data['price_extras'] = $extras ?: null;
        }
        $isTemplate = (bool) ($data['is_template'] ?? $p->is_template);
        if ($isTemplate && $p->parent_id) {
            throw new \DomainException('Une variante ne peut pas être elle-même un modèle.');
        }
        if (! $isTemplate && $p->exists && $p->is_template && Product::where('parent_id', $p->id)->exists()) {
            throw new \DomainException('Ce modèle a des variantes : il reste un modèle.');
        }
    }

    public static function combinations(array $attrs): array
    {
        $out = [[]];
        foreach ($attrs as $a) {
            $next = [];
            foreach ($out as $combo) {
                foreach ($a['values'] as $v) {
                    $next[] = $combo + [$a['name'] => $v];
                }
            }
            $out = $next;
        }

        return $attrs ? $out : [];
    }

    public static function key(array $values): string
    {
        ksort($values);

        return json_encode($values, JSON_UNESCAPED_UNICODE);
    }

    /** Crée les combinaisons manquantes (ou seulement celles demandées). */
    public function generate(Product $tpl, ?array $only = null): array
    {
        if (! $tpl->is_template || ! $tpl->variant_attributes) {
            throw new \DomainException('Définissez d\'abord les attributs du modèle (taille, couleur…).');
        }
        $existing = Product::withTrashed()->where('parent_id', $tpl->id)->get()->keyBy(fn ($v) => self::key($v->variant_values ?? []));
        $created = [];
        foreach (self::combinations($tpl->variant_attributes) as $combo) {
            $k = self::key($combo);
            if ($only !== null && ! in_array($k, array_map(fn ($c) => self::key((array) $c), $only), true)) {
                continue;
            }
            if ($v = $existing->get($k)) {
                if ($v->trashed()) {
                    $v->restore();
                    $created[] = $v->fresh();
                }

                continue;
            }
            $extra = 0.0;
            foreach ($combo as $an => $av) {
                $extra += (float) ($tpl->price_extras[$an][$av] ?? 0);
            }
            $suffix = implode('-', array_map(fn ($v) => strtoupper(Str::slug($v, '')), $combo));
            $v = new Product([
                'category_id' => $tpl->category_id, 'type' => 'product', 'name' => $tpl->name.' — '.implode(' / ', $combo),
                'sku' => $tpl->sku ? mb_substr($tpl->sku.'-'.$suffix, 0, 100) : null, 'description' => $tpl->description, 'unit' => $tpl->unit,
                'sale_price' => round((float) $tpl->sale_price + $extra, 2), 'purchase_price' => (float) $tpl->purchase_price, 'tax_rate' => (float) $tpl->tax_rate,
                'track_stock' => true, 'min_stock' => (float) $tpl->min_stock, 'active' => true, 'tracking' => 'none',
            ]);
            $v->forceFill(['parent_id' => $tpl->id, 'variant_values' => $combo, 'custom' => $tpl->custom])->save();
            $created[] = $v;
        }

        return $created;
    }

    public const PROPAGATE = ['category_id', 'description', 'unit', 'purchase_price', 'tax_rate', 'min_stock', 'sale_price', 'active'];

    /** Recopie des champs du modèle vers ses variantes (prix de vente : prix du modèle + supplément de la variante). */
    public function propagate(Product $tpl, array $fields): int
    {
        $fields = array_values(array_intersect(self::PROPAGATE, $fields));
        $n = 0;
        foreach (Product::where('parent_id', $tpl->id)->get() as $v) {
            $data = [];
            foreach ($fields as $f) {
                if ($f === 'sale_price') {
                    $extra = 0.0;
                    foreach ($v->variant_values ?? [] as $an => $av) {
                        $extra += (float) ($tpl->price_extras[$an][$av] ?? 0);
                    }
                    $data['sale_price'] = round((float) $tpl->sale_price + $extra, 2);
                } else {
                    $data[$f] = $tpl->{$f};
                }
            }
            if ($data) {
                $r = app(EntityWriter::class)->upsert(Product::class, $v->id, $data);
                $n += $r['status'] === EntityWriter::APPLIED ? 1 : 0;
            }
        }

        return $n;
    }
}
