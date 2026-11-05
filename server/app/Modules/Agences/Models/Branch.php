<?php

namespace App\Modules\Agences\Models;

use App\Models\Concerns\Syncable;
use App\Support\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Agence, succursale ou point de vente d'une société. */
class Branch extends Model
{
    use Syncable;

    public static string $syncEntity = 'branches';

    public static array $searchable = ['name', 'code', 'city'];

    public static string $viewPermission = 'agences.view|agences.manage|ventes.view|ventes.manage|pos.use|achats.view|stock.view|tresorerie.view|core.contacts.view|core.products.view';

    public static string $managePermission = 'agences.manage';

    protected $fillable = ['name', 'code', 'city', 'address', 'phone', 'manager_id', 'warehouse_id', 'cash_account_id', 'is_head_office', 'active'];

    protected function casts(): array
    {
        return ['is_head_office' => 'boolean', 'active' => 'boolean', 'manager_id' => 'integer', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        // Un utilisateur limité ne voit que ses agences
        static::addGlobalScope('branch', function (Builder $q) {
            $ids = BranchScope::allowed();
            if ($ids !== null) {
                $q->whereIn('branches.id', $ids ?: ['__none__']);
            }
        });
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:40',
            'manager_id' => ['nullable', 'integer', \App\Support\TenantRef::of('users')],
            'warehouse_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('warehouses')],
            'cash_account_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('tr_accounts')],
            'is_head_office' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ];
    }

    public function beforeEntityDelete(): void
    {
        if (\Illuminate\Support\Facades\DB::table('trade_documents')->where('branch_id', $this->id)->whereNull('deleted_at')->exists()) {
            throw new \DomainException('Cette agence a des documents : désactivez-la plutôt que de la supprimer.');
        }
    }
}
