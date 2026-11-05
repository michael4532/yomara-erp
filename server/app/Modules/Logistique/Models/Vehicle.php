<?php

namespace App\Modules\Logistique\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use Syncable;

    protected $table = 'lg_vehicles';

    public static string $syncEntity = 'lg_vehicles';

    public static string $viewPermission = 'logistique.view|logistique.deliver|logistique.manage';

    public static string $managePermission = 'logistique.manage';

    public const TYPES = ['moto' => 'Moto', 'tricycle' => 'Tricycle', 'voiture' => 'Voiture', 'camionnette' => 'Camionnette', 'camion' => 'Camion'];

    protected $fillable = ['name', 'plate', 'type', 'capacity_kg', 'active'];

    protected function casts(): array
    {
        return ['capacity_kg' => 'float', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return ['name' => 'required|string|max:120', 'plate' => 'nullable|string|max:30', 'type' => 'nullable|in:'.implode(',', array_keys(self::TYPES)),
            'capacity_kg' => 'nullable|numeric|min:0', 'active' => 'nullable|boolean'];
    }
}
