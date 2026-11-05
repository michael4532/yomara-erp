<?php

namespace App\Modules\Fidelite\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Récompense du catalogue : bon de réduction ou article offert, contre des points. */
class Reward extends Model
{
    use Syncable;

    protected $table = 'lc_rewards';

    public static string $syncEntity = 'lc_rewards';

    public static string $viewPermission = 'fidelite.view|fidelite.manage|pos.use|ventes.view';

    public static string $managePermission = 'fidelite.manage';

    protected $fillable = ['name', 'points_cost', 'kind', 'amount', 'product_id', 'active', 'sort'];

    protected function casts(): array
    {
        return ['points_cost' => 'integer', 'amount' => 'float', 'active' => 'boolean', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'name' => 'required|string|max:255',
            'points_cost' => 'required|integer|min:1',
            'kind' => 'nullable|in:amount,product',
            'amount' => 'nullable|numeric|min:0',
            'product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $company)],
            'active' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0',
        ];
    }
}
