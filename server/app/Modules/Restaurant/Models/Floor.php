<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Salle (terrasse, salle principale, étage…). */
class Floor extends Model
{
    use Syncable;

    protected $table = 'rs_floors';

    public static string $syncEntity = 'rs_floors';

    public static string $viewPermission = 'restaurant.serve|restaurant.kitchen|restaurant.manage|pos.use';

    public static string $managePermission = 'restaurant.manage';

    protected $fillable = ['name', 'sort'];

    protected function casts(): array
    {
        return ['sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return ['name' => 'required|string|max:100', 'sort' => 'nullable|integer|min:0'];
    }

    public function beforeEntityDelete(): void
    {
        if (Table::where('floor_id', $this->id)->exists()) {
            throw new \DomainException('Déplacez ou supprimez d\'abord les tables de cette salle.');
        }
    }
}
