<?php

namespace App\Modules\Pointage\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Badge de pointage : copie légère de la fiche salarié (nom, service, horaire, code PIN haché),
 * pour que la borne fonctionne hors ligne sans recevoir les salaires.
 */
class Badge extends Model
{
    use Syncable;

    protected $table = 'pt_badges';

    public static string $syncEntity = 'pt_badges';

    public static string $viewPermission = 'pointage.view|pointage.manage|pointage.kiosk|pointage.self';

    public static string $managePermission = 'pointage.manage';

    /** Nom, service, compte et statut suivent la fiche salarié : seuls l'horaire et le pointage mobile se règlent ici. */
    protected $fillable = ['schedule_id', 'self_allowed'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['self_allowed' => 'boolean', 'active' => 'boolean', 'user_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'schedule_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('pt_schedules', 'id')->where('company_id', app('tenant')->id)->whereNull('deleted_at')],
            'self_allowed' => 'nullable|boolean',
        ];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('pointage.view|pointage.manage|pointage.kiosk')) {
            $q->where('user_id', $user->id);
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if ($creating) {
            throw new \DomainException('Le badge se crée avec la fiche salarié.');
        }
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Le badge suit la fiche salarié : sortez le salarié des effectifs.');
    }

    public static function hashPin(string $employeeId, string $pin): string
    {
        return hash('sha256', $employeeId.':'.$pin);
    }
}
