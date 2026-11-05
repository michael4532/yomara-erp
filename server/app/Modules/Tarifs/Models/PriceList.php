<?php

namespace App\Modules\Tarifs\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Liste de prix : prix fixes ou remises par article, par catégorie ou pour tout, à partir d'une quantité. */
class PriceList extends Model
{
    use Syncable;

    protected $table = 'pl_lists';

    public static string $syncEntity = 'pl_lists';

    public static string $viewPermission = 'ventes.view|ventes.manage|pos.use|core.products.view|core.contacts.view|tarifs.manage';

    public static string $managePermission = 'tarifs.manage';

    protected $fillable = ['name', 'is_default', 'start_date', 'end_date', 'items', 'notes', 'sort', 'active'];

    protected $attributes = ['is_default' => false, 'sort' => 0, 'active' => true];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['items' => 'array', 'is_default' => 'boolean', 'active' => 'boolean', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'is_default' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'items' => 'nullable|array|max:3000',
            'notes' => 'nullable|string|max:2000',
            'sort' => 'integer|min:0|max:999',
            'active' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $start = $data['start_date'] ?? $this->start_date?->toDateString();
        $end = $data['end_date'] ?? $this->end_date?->toDateString();
        if ($start && $end && $end < $start) {
            throw new \DomainException('La date de fin doit suivre la date de début.');
        }
        if (array_key_exists('items', $data)) {
            $out = [];
            foreach ((array) $data['items'] as $i => $it) {
                if (! is_array($it)) {
                    continue;
                }
                $scope = in_array($it['scope'] ?? '', ['product', 'category', 'all'], true) ? $it['scope'] : (! empty($it['product_id']) ? 'product' : (! empty($it['category_id']) ? 'category' : 'all'));
                $mode = ($it['mode'] ?? 'price') === 'discount' ? 'discount' : 'price';
                $row = ['scope' => $scope, 'min_qty' => max(0, round((float) ($it['min_qty'] ?? 0), 4)), 'mode' => $mode];
                if ($scope === 'product') {
                    if (empty($it['product_id'])) {
                        throw new \DomainException('Ligne '.($i + 1).' : choisissez l\'article.');
                    }
                    $row['product_id'] = (string) $it['product_id'];
                    if (! empty($it['packaging'])) {
                        $row['packaging'] = mb_substr((string) $it['packaging'], 0, 40);
                    }
                } elseif ($scope === 'category') {
                    if (empty($it['category_id'])) {
                        throw new \DomainException('Ligne '.($i + 1).' : choisissez la catégorie.');
                    }
                    $row['category_id'] = (string) $it['category_id'];
                }
                if ($mode === 'price') {
                    if ($scope !== 'product') {
                        throw new \DomainException('Ligne '.($i + 1).' : un prix fixe s\'applique à un article ; pour une catégorie, indiquez une remise.');
                    }
                    if (! isset($it['price']) || ! is_numeric($it['price']) || $it['price'] < 0) {
                        throw new \DomainException('Ligne '.($i + 1).' : indiquez le prix.');
                    }
                    $row['price'] = round((float) $it['price'], 2);
                } else {
                    $d = (float) ($it['discount'] ?? 0);
                    if ($d <= 0 || $d > 100) {
                        throw new \DomainException('Ligne '.($i + 1).' : indiquez une remise entre 0 et 100 %.');
                    }
                    $row['discount'] = round($d, 2);
                }
                $out[] = $row;
            }
            $data['items'] = $out;
        }
        if (! empty($data['is_default'])) {
            static::where('is_default', true)->when($this->exists, fn ($q) => $q->where('id', '<>', $this->id))->get()->each(function ($l) {
                $l->is_default = false;
                $l->save();
            });
        }
    }

    public function beforeEntityDelete(): void
    {
        \App\Modules\Core\Models\Partner::where('price_list_id', $this->id)->get()->each(function ($p) {
            $p->price_list_id = null;
            $p->save();
        });
    }
}
