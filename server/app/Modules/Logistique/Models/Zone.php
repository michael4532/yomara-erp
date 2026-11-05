<?php

namespace App\Modules\Logistique\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Zone de livraison : frais et délai standard. */
class Zone extends Model
{
    use Syncable;

    protected $table = 'lg_zones';

    public static string $syncEntity = 'lg_zones';

    public static string $viewPermission = 'logistique.view|logistique.deliver|logistique.manage';

    public static string $managePermission = 'logistique.manage';

    protected $fillable = ['name', 'fee', 'delay_days', 'cities', 'color'];

    protected function casts(): array
    {
        return ['fee' => 'float', 'delay_days' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return ['name' => 'required|string|max:120', 'fee' => 'nullable|numeric|min:0', 'delay_days' => 'nullable|integer|min:0|max:60', 'cities' => 'nullable|string|max:255', 'color' => 'nullable|string|max:9'];
    }
}
