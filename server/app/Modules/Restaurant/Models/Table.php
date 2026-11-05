<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Table du plan de salle (position sur une grille de 24 × 16 cases). */
class Table extends Model
{
    use Syncable;

    protected $table = 'rs_tables';

    public static string $syncEntity = 'rs_tables';

    public static string $viewPermission = 'restaurant.serve|restaurant.kitchen|restaurant.manage|pos.use';

    public static string $managePermission = 'restaurant.manage';

    protected $fillable = ['floor_id', 'name', 'seats', 'shape', 'x', 'y', 'w', 'h', 'active'];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'x' => 'integer', 'y' => 'integer', 'w' => 'integer', 'h' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'floor_id' => ['nullable', 'uuid', Rule::exists('rs_floors', 'id')->where('company_id', $company)],
            'name' => 'required|string|max:40',
            'seats' => 'nullable|integer|min:1|max:99',
            'shape' => 'nullable|in:square,round,rect',
            'x' => 'nullable|integer|min:0|max:23',
            'y' => 'nullable|integer|min:0|max:15',
            'w' => 'nullable|integer|min:1|max:8',
            'h' => 'nullable|integer|min:1|max:8',
            'active' => 'nullable|boolean',
        ];
    }

    public function beforeEntityDelete(): void
    {
        if (Order::where('table_id', $this->id)->where('status', 'open')->exists()) {
            throw new \DomainException('Cette table a une commande en cours.');
        }
    }
}
