<?php

namespace App\Modules\Pointage\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    use Syncable;

    protected $table = 'pt_schedules';

    public static string $syncEntity = 'pt_schedules';

    public static string $viewPermission = 'pointage.view|pointage.manage|pointage.kiosk|pointage.self';

    public static string $managePermission = 'pointage.manage';

    protected $fillable = ['name', 'days', 'tolerance', 'is_default'];

    protected function casts(): array
    {
        return ['days' => 'array', 'tolerance' => 'integer', 'is_default' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'days' => 'required|array',
            'days.*' => 'nullable|array',
            'days.*.start' => ['required_with:days.*', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'days.*.end' => ['required_with:days.*', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'days.*.break' => 'nullable|integer|min:0|max:300',
            'tolerance' => 'nullable|integer|min:0|max:120',
            'is_default' => 'nullable|boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        foreach ((array) ($data['days'] ?? []) as $d => $day) {
            if (! in_array((string) $d, ['1', '2', '3', '4', '5', '6', '7'], true)) {
                throw new \DomainException('Jour inconnu dans l\'horaire.');
            }
            if ($day && $day['end'] === $day['start']) {
                throw new \DomainException('L\'heure de fin doit différer de l\'heure de début.');
            }
        }
        if (array_key_exists('tolerance', $data) && $data['tolerance'] === null) {
            $data['tolerance'] = 10;
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($this->is_default) {
            static::where('id', '!=', $this->id)->where('is_default', true)->get()->each(fn ($s) => $s->forceFill(['is_default' => false])->save());
        }
    }

    public function beforeEntityDelete(): void
    {
        Badge::where('schedule_id', $this->id)->get()->each(fn ($b) => $b->forceFill(['schedule_id' => null])->save());
    }

    /** Horaire du jour : ['start' => '08:00', 'end' => '17:00', 'break' => 60] ou null (jour de repos). */
    public function forDay(int $isoWeekday): ?array
    {
        $d = ($this->days ?? [])[(string) $isoWeekday] ?? null;

        return $d ? ['start' => $d['start'], 'end' => $d['end'], 'break' => (int) ($d['break'] ?? 0)] : null;
    }
}
