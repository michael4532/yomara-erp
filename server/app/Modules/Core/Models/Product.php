<?php

namespace App\Modules\Core\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use \App\Models\Concerns\HasCustomFields, Syncable;

    public static string $syncEntity = 'products';

    public static array $searchable = ['name', 'sku', 'barcode'];

    public static string $viewPermission = 'core.products.view';

    public static string $managePermission = 'core.products.manage';

    /** Droit de suppression (distinct de la modification). */
    public static string $deletePermission = 'core.products.delete';

    protected $fillable = [
        'category_id', 'type', 'sku', 'barcode', 'name', 'description', 'unit', 'sale_price',
        'purchase_price', 'tax_rate', 'track_stock', 'min_stock', 'image', 'active', 'plu',
        // Lots, séries et péremption (module « lots »)
        'tracking', 'use_expiry', 'shelf_life_days', 'expiry_alert_days',
        // Conditionnements (module « tarifs »)
        'packagings',
        // Variantes (module « variantes »)
        'parent_id', 'is_template', 'variant_attributes', 'price_extras', 'variant_values',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'float',
            'purchase_price' => 'float',
            'tax_rate' => 'float',
            'min_stock' => 'float',
            'track_stock' => 'boolean',
            'active' => 'boolean',
            'version' => 'integer',
            'use_expiry' => 'boolean',
            'shelf_life_days' => 'integer',
            'expiry_alert_days' => 'integer',
            'packagings' => 'array',
            'is_template' => 'boolean',
            'variant_attributes' => 'array',
            'price_extras' => 'array',
            'variant_values' => 'array',
        ];
    }

    public static function syncRules(): array
    {
        return [
            'category_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('product_categories')],
            'type' => 'sometimes|in:product,service',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'unit' => 'sometimes|string|max:30',
            'sale_price' => 'sometimes|numeric|min:0',
            'purchase_price' => 'sometimes|numeric|min:0',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'track_stock' => 'sometimes|boolean',
            'min_stock' => 'sometimes|numeric|min:0',
            'image' => 'nullable|string|max:255',
            'active' => 'sometimes|boolean',
            'plu' => 'nullable|digits:5',
            'tracking' => 'sometimes|in:none,lot,serial',
            'use_expiry' => 'sometimes|boolean',
            'shelf_life_days' => 'nullable|integer|min:1|max:36500',
            'expiry_alert_days' => 'nullable|integer|min:0|max:3650',
            'packagings' => 'nullable|array|max:10',
            'is_template' => 'sometimes|boolean',
            'variant_attributes' => 'nullable|array|max:3',
            'price_extras' => 'nullable|array',
            'parent_id' => 'nullable|uuid',
            'variant_values' => 'nullable|array',
        ];
    }

    /** Code-barres et code balance uniques dans la société (la caisse les lit au scanner). */
    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        foreach (['barcode' => 'Ce code-barres', 'plu' => 'Ce code balance'] as $field => $label) {
            if (filled($data[$field] ?? null) && static::where($field, $data[$field])->when($this->exists, fn ($q) => $q->where('id', '!=', $this->id))->exists()) {
                throw new \DomainException($label.' est déjà utilisé par un autre article.');
            }
        }
        $company = app()->bound('tenant') ? app('tenant') : null;
        if (filled($data['barcode'] ?? null) && $company?->hasModule('tarifs') && \App\Modules\Tarifs\Packaging::barcodeOwner($data['barcode'], $this->id)) {
            throw new \DomainException('Ce code-barres est déjà celui d\'un conditionnement d\'un autre article.');
        }
        if (($data['tracking'] ?? $this->tracking) === 'serial') {
            $data['track_stock'] = true;
            if (($data['type'] ?? $this->type) === 'service') {
                throw new \DomainException('Un service ne se suit pas par numéro de série.');
            }
        }
        if (array_key_exists('packagings', $data)) {
            $data['packagings'] = $company?->hasModule('tarifs') ? \App\Modules\Tarifs\Packaging::clean($data['packagings'], $this->id, $data['barcode'] ?? $this->barcode) : ($this->packagings ?? null);
        }
        // Variantes : le modèle n'est ni vendu ni stocké ; les variantes se créent depuis le modèle.
        unset($data['parent_id'], $data['variant_values']);
        if (array_key_exists('variant_attributes', $data) || array_key_exists('price_extras', $data) || array_key_exists('is_template', $data)) {
            if ($company?->hasModule('variantes')) {
                \App\Modules\Variantes\VariantService::cleanTemplate($data, $this);
            } else {
                unset($data['variant_attributes'], $data['price_extras'], $data['is_template']);
            }
        }
        if ($data['is_template'] ?? $this->is_template) {
            $data['track_stock'] = false;
            $data['tracking'] = 'none';
        }
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }
}
