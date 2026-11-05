<?php

namespace App\Modules\Pointage\Models;

use App\Models\Concerns\Syncable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Punch extends Model
{
    use Syncable;

    protected $table = 'pt_punches';

    public static string $syncEntity = 'pt_punches';

    public static string $viewPermission = 'pointage.view|pointage.manage|pointage.kiosk|pointage.self';

    /** Borne et salarié créent des pointages ; seul le responsable les corrige. */
    public static string $managePermission = 'pointage.manage|pointage.kiosk|pointage.self';

    protected $fillable = ['employee_id', 'at', 'kind', 'source', 'device', 'note'];

    public const SOURCES = ['kiosk' => 'Borne', 'self' => 'Téléphone', 'manual' => 'Saisie du responsable'];

    protected function casts(): array
    {
        return ['at' => 'datetime:Y-m-d H:i:s', 'date' => 'date:Y-m-d', 'late_sync' => 'boolean', 'user_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', \Illuminate\Validation\Rule::exists('pt_badges', 'id')->where('company_id', app('tenant')->id)],
            'at' => 'required|date',
            'kind' => 'required|in:in,out',
            'source' => 'nullable|in:kiosk,self,manual',
            'device' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:255',
        ];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('pointage.view|pointage.manage|pointage.kiosk')) {
            $q->whereIn('employee_id', Badge::where('user_id', $user->id)->select('id'));
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $manager = ! $user || $user->hasPermission('pointage.manage');
        $tz = app('tenant')->settings['timezone'] ?? config('app.timezone');
        $now = Carbon::now($tz);

        if (! $manager) {
            if (! $creating) {
                throw new \DomainException('Un pointage enregistré ne se modifie plus : demandez une correction à votre responsable.');
            }
            $badge = Badge::find($data['employee_id'] ?? null);
            if (! $badge || ! $badge->active) {
                throw new \DomainException('Badge inconnu ou salarié sorti des effectifs.');
            }
            if ($user->hasPermission('pointage.kiosk')) {
                $data['source'] = 'kiosk';
            } else {
                if ($badge->user_id !== $user->id) {
                    throw new \DomainException('Vous ne pouvez pointer que pour vous-même.');
                }
                if (! $badge->self_allowed || ! $user->hasPermission('pointage.self')) {
                    throw new \DomainException('Le pointage depuis le téléphone n\'est pas autorisé pour vous : utilisez la borne.');
                }
                $data['source'] = 'self';
            }
            unset($data['note']);
        } else {
            $data['source'] ??= $creating ? 'manual' : $this->source;
            if ($data['source'] === 'manual' && $creating && blank($data['note'] ?? null)) {
                throw new \DomainException('Indiquez le motif de la saisie manuelle (oubli de badge, panne de la borne…).');
            }
        }

        $at = Carbon::parse($data['at'] ?? $this->at, $tz);
        if ($at->gt($now->copy()->addMinutes(10))) {
            throw new \DomainException('L\'heure du pointage est dans le futur : vérifiez l\'heure de l\'appareil.');
        }
        $data['at'] = $at->format('Y-m-d H:i:s');
        $this->date = $at->toDateString();
        if ($creating) {
            $this->user_id = $user?->id;
            // Envoyé plus de 15 min après l'heure indiquée : l'appareil était hors ligne (ou son heure est fausse) — à vérifier
            $this->late_sync = ($data['source'] ?? '') !== 'manual' && $now->diffInMinutes($at, true) > 15;
            $dup = static::where('employee_id', $data['employee_id'])->where('kind', $data['kind'])
                ->whereBetween('at', [$at->copy()->subMinutes(2)->format('Y-m-d H:i:s'), $at->copy()->addMinutes(2)->format('Y-m-d H:i:s')])->exists();
            if ($dup) {
                throw new \DomainException('Pointage déjà enregistré il y a moins de 2 minutes.');
            }
        }
    }

    public function beforeEntityDelete(): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('pointage.manage')) {
            throw new \DomainException('Seul le responsable supprime un pointage.');
        }
    }
}
