<?php

namespace App\Modules\Production\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Core\Models\Product;
use App\Modules\Production\ProductionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Nomenclature : ce qu'il faut pour obtenir `quantity` unités du produit.
 * - manufacture : fabriqué à l'avance par un ordre de fabrication (pain, jus en bouteille, meuble…)
 * - kit : recette consommée au moment de la vente (plat du restaurant, panier garni…)
 */
class Bom extends Model
{
    use Syncable;

    protected $table = 'mrp_boms';

    public static string $syncEntity = 'mrp_boms';

    public static array $searchable = ['name', 'notes'];

    public static string $viewPermission = 'production.view|production.operate|production.manage';

    public static string $managePermission = 'production.manage';

    protected $fillable = ['product_id', 'name', 'kind', 'quantity', 'lines', 'labour_minutes', 'extra_cost', 'notes', 'active'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'lines' => 'array', 'labour_minutes' => 'integer', 'extra_cost' => 'float', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;
        $product = Rule::exists('products', 'id')->where('company_id', $company);

        return [
            'product_id' => ['required', 'uuid', $product],
            'name' => 'nullable|string|max:255',
            'kind' => 'nullable|in:manufacture,kit',
            'quantity' => 'required|numeric|gt:0',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.product_id' => ['required', 'uuid', $product],
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.loss_percent' => 'nullable|numeric|min:0|max:90',
            'lines.*.note' => 'nullable|string|max:255',
            'labour_minutes' => 'nullable|integer|min:0|max:100000',
            'extra_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
            'active' => 'nullable|boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $productId = $data['product_id'] ?? $this->product_id;
        $lines = $data['lines'] ?? $this->lines ?? [];
        $ids = array_column($lines, 'product_id');
        if (in_array($productId, $ids, true)) {
            throw new \DomainException('Un produit ne peut pas entrer dans sa propre nomenclature.');
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new \DomainException('Chaque composant ne doit apparaître qu\'une fois.');
        }
        if (app(ProductionService::class)->createsLoop($productId, $ids, $this->id)) {
            throw new \DomainException('Boucle de nomenclature : un composant est lui-même fabriqué à partir de ce produit.');
        }
        if (($data['kind'] ?? $this->kind) === 'kit') {
            $p = Product::find($productId);
            if ($p && $p->type === 'product' && $p->track_stock) {
                throw new \DomainException('Une recette vendue s\'applique à un article sans suivi de stock (plat, service, kit) : ce sont ses ingrédients qui sortent du stock.');
            }
        }
        $data['name'] = ($data['name'] ?? null) ?: ($this->name ?: Product::find($productId)?->name);
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        // Une seule nomenclature active par produit
        if ($this->active) {
            self::where('product_id', $this->product_id)->where('id', '!=', $this->id)->where('active', true)->get()
                ->each(fn (self $b) => $b->forceFill(['active' => false])->save());
        }
    }

    public function beforeEntityDelete(): void
    {
        if (ProductionOrder::where('bom_id', $this->id)->whereIn('status', ['confirmed', 'in_progress'])->exists()) {
            throw new \DomainException('Des ordres de fabrication en cours utilisent cette nomenclature.');
        }
    }
}
