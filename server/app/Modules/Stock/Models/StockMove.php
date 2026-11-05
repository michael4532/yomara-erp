<?php

namespace App\Modules\Stock\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mouvement de stock. Journal en ajout seulement : jamais de conflit de
 * synchronisation, et deux caisses hors ligne peuvent vendre en même temps.
 */
class StockMove extends Model
{
    use Syncable;

    public static string $syncEntity = 'stock_moves';

    public static array $searchable = ['reference', 'notes'];

    public static string $viewPermission = 'stock.view';

    public static string $managePermission = 'stock.manage';

    /** Types saisissables manuellement (les autres viennent des documents). */
    public const MANUAL_TYPES = ['initial', 'adjustment', 'transfer_in', 'transfer_out', 'loss'];

    protected $fillable = ['product_id', 'warehouse_id', 'quantity', 'type', 'unit_cost', 'reference', 'date', 'notes', 'lot_id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_cost' => 'float', 'date' => 'date:Y-m-d', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->user_id ??= auth()->id());
    }

    public static function syncRules(): array
    {
        return [
            'product_id' => ['required', 'uuid', \App\Support\TenantRef::of('products')],
            'warehouse_id' => ['required', 'uuid', \App\Support\TenantRef::of('warehouses')],
            'quantity' => 'required|numeric|not_in:0',
            'type' => 'required|in:'.implode(',', self::MANUAL_TYPES),
            'unit_cost' => 'nullable|numeric|min:0',
            'reference' => 'nullable|string|max:100',
            'date' => 'required|date',
            'notes' => 'nullable|string',
            'lot_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('stock_lots')],
            'lot_number' => 'nullable|string|max:80',
            'expiry_date' => 'nullable|date',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            throw new \DomainException('Un mouvement de stock ne se modifie pas : créez un mouvement d\'ajustement.');
        }
        // Saisie manuelle (stock initial, ajustement, perte) d'un article suivi par lot ou numéro de série
        app(\App\Modules\Lots\LotService::class)->manualMove($data);
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Un mouvement de stock ne se supprime pas : créez un mouvement d\'ajustement.');
    }
}
