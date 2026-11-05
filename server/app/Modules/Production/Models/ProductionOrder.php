<?php

namespace App\Modules\Production\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Core\Models\Product;
use App\Modules\Production\ProductionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Ordre de fabrication : brouillon → confirmé → en cours → terminé (ou annulé).
 * À la fin, les composants réellement consommés sortent du stock et le produit fini y entre,
 * à son coût de revient (matières + main-d'œuvre + frais).
 */
class ProductionOrder extends Model
{
    use Syncable;

    protected $table = 'mrp_orders';

    public static string $syncEntity = 'mrp_orders';

    public static array $searchable = ['number', 'notes'];

    public static string $viewPermission = 'production.view|production.operate|production.manage';

    public static string $managePermission = 'production.operate|production.manage';

    public const FLOW = ['draft' => ['confirmed', 'cancelled'], 'confirmed' => ['draft', 'in_progress', 'done', 'cancelled'], 'in_progress' => ['done', 'cancelled'], 'done' => [], 'cancelled' => []];

    protected $fillable = ['bom_id', 'product_id', 'quantity', 'produced_qty', 'warehouse_id', 'planned_date', 'components', 'labour_minutes', 'notes'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'produced_qty' => 'float',
            'planned_date' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'done_at' => 'datetime',
            'components' => 'array',
            'labour_minutes' => 'integer',
            'material_cost' => 'float',
            'labour_cost' => 'float',
            'unit_cost' => 'float',
            'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'bom_id' => ['nullable', 'uuid', Rule::exists('mrp_boms', 'id')->where('company_id', $company)],
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $company)],
            'quantity' => 'required|numeric|gt:0',
            'produced_qty' => 'nullable|numeric|min:0',
            'warehouse_id' => ['nullable', 'uuid', Rule::exists('warehouses', 'id')->where('company_id', $company)],
            'planned_date' => 'nullable|date',
            'status' => 'nullable|in:draft,confirmed,in_progress,done,cancelled',
            'components' => 'nullable|array',
            'components.*.product_id' => 'required|uuid',
            'components.*.planned' => 'nullable|numeric|min:0',
            'components.*.consumed' => 'nullable|numeric|min:0',
            'labour_minutes' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $manager = $user?->hasPermission('production.manage') ?? true;
        $svc = app(ProductionService::class);
        $to = $data['status'] ?? null;
        unset($data['status']);

        if ($creating) {
            if (! $manager) {
                throw new \DomainException('Seul un responsable de production planifie les ordres de fabrication.');
            }
            $this->number = Sequence::next(app('tenant')->id, 'mrp_order', 'OF');
            $this->status = 'draft';
            $this->user_id = $user?->id;
            $this->user_name = $user?->name;
            $bom = ! empty($data['bom_id']) ? Bom::find($data['bom_id']) : Bom::where('product_id', $data['product_id'])->where('active', true)->first();
            if (! $bom || $bom->product_id !== $data['product_id']) {
                throw new \DomainException('Ce produit n\'a pas de nomenclature : créez-la d\'abord.');
            }
            if ($bom->kind === 'kit') {
                throw new \DomainException('Cette recette est consommée à la vente : pas d\'ordre de fabrication.');
            }
            $data['bom_id'] = $bom->id;
            $p = Product::find($data['product_id']);
            if ($p && ($p->type !== 'product' || ! $p->track_stock)) {
                throw new \DomainException('Le produit fabriqué doit être un article suivi en stock.');
            }
            if (empty($data['components'])) {
                $data['components'] = $svc->componentsFor($bom, (float) $data['quantity']);
            }
            $data['labour_minutes'] ??= (int) round($bom->labour_minutes * $data['quantity'] / $bom->quantity);
        } else {
            if (in_array($this->status, ['done', 'cancelled'], true)) {
                if (array_diff(array_keys($data), ['notes'])) {
                    throw new \DomainException('Cet ordre de fabrication est '.($this->status === 'done' ? 'terminé' : 'annulé').'.');
                }
            } elseif (in_array($this->status, ['draft', 'confirmed'], true)) {
                if (! $manager && array_intersect(array_keys($data), ['product_id', 'bom_id', 'quantity', 'planned_date', 'warehouse_id'])) {
                    throw new \DomainException('Seul un responsable modifie la planification.');
                }
                unset($data['product_id'], $data['bom_id']);
                // Quantité changée : composants recalculés (sauf saisie explicite)
                if (isset($data['quantity']) && abs((float) $data['quantity'] - $this->quantity) > 0.0001 && empty($data['components'])) {
                    $bom = Bom::find($this->bom_id);
                    if ($bom) {
                        $data['components'] = $svc->componentsFor($bom, (float) $data['quantity']);
                        $data['labour_minutes'] = (int) round($bom->labour_minutes * $data['quantity'] / $bom->quantity);
                    }
                }
            } else {
                unset($data['product_id'], $data['bom_id'], $data['quantity'], $data['warehouse_id']);
            }
            if (isset($data['components'])) {
                // Les composants restent ceux de l'ordre : seules les quantités changent
                $merged = collect($this->components ?? [])->keyBy('product_id')->all();
                foreach ($data['components'] as $c) {
                    if (isset($merged[$c['product_id']])) {
                        $merged[$c['product_id']] = array_merge($merged[$c['product_id']], array_intersect_key($c, array_flip(['planned', 'consumed'])));
                    } elseif (in_array($this->status, ['draft', 'confirmed'], true)) {
                        $merged[$c['product_id']] = ['product_id' => $c['product_id'], 'description' => Product::find($c['product_id'])?->name, 'planned' => (float) ($c['planned'] ?? 0), 'consumed' => $c['consumed'] ?? null];
                    }
                }
                $data['components'] = array_values($merged);
            }
        }

        if ($to && $to !== ($this->status ?? 'draft')) {
            $from = $this->status ?? 'draft';
            if (! in_array($to, self::FLOW[$from], true)) {
                throw new \DomainException('Passage impossible de « '.$from.' » à « '.$to.' ».');
            }
            if (in_array($to, ['confirmed', 'draft', 'cancelled'], true) && ! $manager) {
                throw new \DomainException('Seul un responsable confirme ou annule un ordre de fabrication.');
            }
            if ($to === 'in_progress') {
                $this->started_at = now();
            }
            if ($to === 'done') {
                $qty = (float) ($data['produced_qty'] ?? $this->produced_qty ?? $data['quantity'] ?? $this->quantity);
                if ($qty <= 0) {
                    throw new \DomainException('Indiquez la quantité produite.');
                }
                $data['produced_qty'] = $qty;
                $this->started_at ??= now();
            }
            $this->status = $to;
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($this->status === 'done' && ! $this->done_at) {
            app(ProductionService::class)->complete($this);
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->status !== 'draft') {
            throw new \DomainException('Seul un brouillon se supprime : annulez l\'ordre de fabrication.');
        }
    }
}
