<?php

namespace App\Modules\Stock\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use BelongsToBranch, Syncable;

    public static string $syncEntity = 'warehouses';

    public static array $searchable = ['name', 'code'];

    public static string $viewPermission = 'stock.view';

    public static string $managePermission = 'stock.manage';

    /** Un entrepôt sans agence (central) reste visible de tous. */
    public static bool $branchShared = true;

    protected $fillable = ['name', 'code', 'address', 'is_default', 'active', 'branch_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'branch_id' => 'nullable|uuid',
            'is_default' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ];
    }

    /** Entrepôt par défaut : celui de l'agence s'il y en a un, sinon l'entrepôt principal de la société. */
    public static function defaultFor(int $companyId, ?string $branchId = null): self
    {
        $branchId ??= \App\Support\BranchScope::current();
        if ($branchId) {
            $wid = \Illuminate\Support\Facades\DB::table('branches')->where('id', $branchId)->value('warehouse_id');
            $w = static::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('id', $wid ?: '__')->orWhere('branch_id', $branchId))->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$wid ?: '__'])->orderByDesc('is_default')->first();
            if ($w) {
                return $w;
            }
        }

        return static::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderByDesc('is_default')->orderBy('created_at')->first()
            ?? tap(new static(['name' => 'Entrepôt principal', 'code' => 'PRINC', 'is_default' => true]), function ($w) use ($companyId) {
                $w->company_id = $companyId;
                $w->save();
            });
    }

    public function beforeEntityDelete(): void
    {
        if (StockMove::where('warehouse_id', $this->id)->exists()) {
            throw new \DomainException('Cet entrepôt a des mouvements de stock : désactivez-le plutôt.');
        }
    }
}
