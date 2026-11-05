<?php

namespace App\Modules\Lots\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Core\Models\Product;
use Illuminate\Database\Eloquent\Model;

/** Lot ou numéro de série d'un article (la quantité se lit dans les mouvements de stock). */
class Lot extends Model
{
    use Syncable;

    protected $table = 'stock_lots';

    public static string $syncEntity = 'stock_lots';

    public static array $searchable = ['number', 'supplier_lot'];

    public static string $viewPermission = 'stock.view|stock.manage|pos.use|ventes.manage|achats.manage|achats.receive';

    public static string $managePermission = 'stock.manage';

    protected $fillable = ['product_id', 'number', 'expiry_date', 'supplier_lot', 'notes'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date:Y-m-d', 'alerted_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'product_id' => ['required', 'uuid', \App\Support\TenantRef::of('products')],
            'number' => 'required|string|max:80',
            'expiry_date' => 'nullable|date',
            'supplier_lot' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $productId = $data['product_id'] ?? $this->product_id;
        $p = Product::find($productId) ?? throw new \DomainException('Article introuvable.');
        if (! $creating && array_key_exists('product_id', $data) && $data['product_id'] !== $this->product_id) {
            throw new \DomainException('Un lot ne change pas d\'article.');
        }
        if (isset($data['number'])) {
            $data['number'] = trim($data['number']);
            if (static::where('product_id', $productId)->where('number', $data['number'])->when($this->exists, fn ($q) => $q->where('id', '<>', $this->id))->exists()) {
                throw new \DomainException(($p->tracking === 'serial' ? 'Ce numéro de série' : 'Ce numéro de lot').' existe déjà pour « '.$p->name.' ».');
            }
        }
    }

    public function beforeEntityDelete(): void
    {
        if (\App\Modules\Stock\Models\StockMove::where('lot_id', $this->id)->exists()) {
            throw new \DomainException('Ce lot a des mouvements de stock : il ne se supprime pas.');
        }
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
