<?php

namespace App\Modules\Achats\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Tarif d'un fournisseur pour un article (appris à chaque commande, modifiable). */
class SupplierPrice extends Model
{
    use Syncable;

    public static string $syncEntity = 'supplier_prices';

    public static string $viewPermission = 'achats.view|achats.request';

    public static string $managePermission = 'achats.manage';

    protected $fillable = ['partner_id', 'product_id', 'price', 'min_qty', 'lead_days', 'supplier_ref', 'preferred'];

    protected function casts(): array
    {
        return ['price' => 'float', 'min_qty' => 'float', 'lead_days' => 'integer', 'preferred' => 'boolean', 'last_purchase_at' => 'date:Y-m-d', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $company)],
            'price' => 'required|numeric|min:0',
            'min_qty' => 'nullable|numeric|min:0',
            'lead_days' => 'nullable|integer|min:0|max:365',
            'supplier_ref' => 'nullable|string|max:80',
            'preferred' => 'nullable|boolean',
        ];
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        // Un seul fournisseur préféré par article
        if ($this->preferred) {
            static::where('product_id', $this->product_id)->where('id', '!=', $this->id)->where('preferred', true)->get()->each(fn ($p) => $p->forceFill(['preferred' => false])->save());
        }
    }

    /** Meilleur tarif pour un article : préféré, sinon le moins cher. */
    public static function bestFor(string $productId): ?self
    {
        return static::where('product_id', $productId)->orderByDesc('preferred')->orderBy('price')->first();
    }
}
