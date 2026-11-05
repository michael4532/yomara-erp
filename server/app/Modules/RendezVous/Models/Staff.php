<?php

namespace App\Modules\RendezVous\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Membre de l'équipe (ou ressource : salle, véhicule…) avec ses horaires de la semaine. */
class Staff extends Model
{
    use Syncable;

    protected $table = 'rdv_staff';

    public static string $syncEntity = 'rdv_staff';

    public static string $viewPermission = 'rendez_vous.view|rendez_vous.own|rendez_vous.manage';

    public static string $managePermission = 'rendez_vous.manage';

    public const DEFAULT_SCHEDULE = ['1' => [['08:00', '12:30'], ['14:00', '18:00']], '2' => [['08:00', '12:30'], ['14:00', '18:00']], '3' => [['08:00', '12:30'], ['14:00', '18:00']],
        '4' => [['08:00', '12:30'], ['14:00', '18:00']], '5' => [['08:00', '12:30'], ['14:00', '18:00']], '6' => [['09:00', '13:00']], '7' => []];

    protected $fillable = ['user_id', 'name', 'title', 'schedule', 'color', 'online', 'sort', 'active'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'schedule' => 'array', 'online' => 'boolean', 'sort' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'name' => 'required|string|max:120',
            'title' => 'nullable|string|max:120',
            'schedule' => 'nullable|array',
            'schedule.*' => 'array',
            'schedule.*.*' => 'array|size:2',
            'schedule.*.*.*' => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'color' => 'nullable|string|max:9',
            'online' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0',
            'active' => 'nullable|boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->schedule ??= self::DEFAULT_SCHEDULE);
    }

    /** Plages de travail d'un jour de semaine (1 = lundi … 7 = dimanche). */
    public function rangesFor(int $weekday): array
    {
        return array_values(array_filter((array) (($this->schedule ?? [])[(string) $weekday] ?? []), fn ($r) => is_array($r) && count($r) === 2 && $r[0] < $r[1]));
    }
}
