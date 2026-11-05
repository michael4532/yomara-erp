<?php

namespace App\Modules\Analytique\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Analytique\Distribution;
use Illuminate\Database\Eloquent\Model;

/** Règle de ventilation automatique (produit, catégorie, client / fournisseur, catégorie de frais, service). */
class Rule extends Model
{
    use Syncable;

    protected $table = 'an_rules';

    public static string $syncEntity = 'an_rules';

    public static string $viewPermission = 'analytique.view|analytique.manage';

    public static string $managePermission = 'analytique.manage';

    public const APPLIES = ['any' => 'Tout', 'sale' => 'Ventes', 'purchase' => 'Achats', 'expense' => 'Notes de frais', 'payroll' => 'Paie'];

    protected $fillable = ['applies_to', 'product_id', 'category_id', 'partner_id', 'expense_category_id', 'department', 'distribution', 'sort', 'active'];

    protected $attributes = ['applies_to' => 'any', 'active' => true, 'sort' => 0];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['distribution' => 'array', 'active' => 'boolean', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'applies_to' => 'in:'.implode(',', array_keys(self::APPLIES)),
            'product_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('products')],
            'category_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('product_categories')],
            'partner_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('partners')],
            'expense_category_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('nf_categories')],
            'department' => 'nullable|string|max:120',
            'distribution' => 'required|array|min:1',
            'sort' => 'integer|min:0|max:999',
            'active' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (array_key_exists('distribution', $data)) {
            $data['distribution'] = Distribution::clean($data['distribution']);
            if (! $data['distribution']) {
                throw new \DomainException('Indiquez au moins une section.');
            }
        }
        $merged = array_merge($this->only(['product_id', 'category_id', 'partner_id', 'expense_category_id', 'department']), $data);
        if (! array_filter([$merged['product_id'] ?? null, $merged['category_id'] ?? null, $merged['partner_id'] ?? null, $merged['expense_category_id'] ?? null, $merged['department'] ?? null])) {
            throw new \DomainException('Choisissez au moins un critère : produit, catégorie, tiers, catégorie de frais ou service.');
        }
    }

    /** Spécificité : plus il y a de critères, plus la règle est prioritaire. */
    public function score(): int
    {
        return ($this->product_id ? 16 : 0) + ($this->expense_category_id ? 8 : 0) + ($this->category_id ? 4 : 0) + ($this->partner_id ? 2 : 0) + ($this->department ? 1 : 0);
    }
}
