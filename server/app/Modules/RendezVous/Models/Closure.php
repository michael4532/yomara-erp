<?php

namespace App\Modules\RendezVous\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Fermeture ou absence (jour férié, congé, formation…). */
class Closure extends Model
{
    use Syncable;

    protected $table = 'rdv_closures';

    public static string $syncEntity = 'rdv_closures';

    public static string $viewPermission = 'rendez_vous.view|rendez_vous.own|rendez_vous.manage';

    public static string $managePermission = 'rendez_vous.manage';

    protected $fillable = ['staff_id', 'starts_at', 'ends_at', 'reason'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'staff_id' => ['nullable', 'uuid', Rule::exists('rdv_staff', 'id')->where('company_id', $company)],
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'reason' => 'nullable|string|max:255',
        ];
    }
}
