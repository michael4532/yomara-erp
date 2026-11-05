<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Poste de préparation (cuisine, bar, pizza…) : reçoit les lignes des catégories choisies. */
class Station extends Model
{
    use Syncable;

    protected $table = 'rs_stations';

    public static string $syncEntity = 'rs_stations';

    public static string $viewPermission = 'restaurant.serve|restaurant.kitchen|restaurant.manage|pos.use';

    public static string $managePermission = 'restaurant.manage';

    protected $fillable = ['name', 'kind', 'category_ids', 'sort'];

    protected function casts(): array
    {
        return ['category_ids' => 'array', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:60',
            'kind' => 'nullable|in:kitchen,bar',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'uuid',
            'sort' => 'nullable|integer|min:0',
        ];
    }
}
