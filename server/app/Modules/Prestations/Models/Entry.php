<?php

namespace App\Modules\Prestations\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Prestations\PrestationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Temps passé, décompté d'un forfait. */
class Entry extends Model
{
    use Syncable;

    protected $table = 'pr_entries';

    public static string $syncEntity = 'pr_entries';

    public static string $viewPermission = 'prestations.view|prestations.log|prestations.manage';

    public static string $managePermission = 'prestations.log|prestations.manage';

    protected $fillable = ['pack_id', 'date', 'hours', 'description'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'hours' => 'float', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'pack_id' => ['required', 'uuid', Rule::exists('pr_packs', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'date' => 'required|date',
            'hours' => 'required|numeric|gt:0|max:24',
            'description' => 'required|string|max:255',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating && $this->ticket_time_id) {
            throw new \DomainException('Temps saisi sur un ticket : modifiez-le dans le ticket.');
        }
        if (! $creating && $this->timesheet_id) {
            throw new \DomainException('Temps saisi dans le projet « '.$this->project_name.' » : modifiez-le dans le projet.');
        }
        $pack = Pack::find($data['pack_id'] ?? $this->pack_id);
        if (! $creating && isset($data['pack_id']) && $data['pack_id'] !== $this->pack_id) {
            throw new \DomainException('Le temps saisi ne change pas de forfait.');
        }
        if (in_array($pack->status, ['cancelled', 'expired'], true)) {
            throw new \DomainException('Forfait '.($pack->status === 'expired' ? 'expiré' : 'annulé').' : renouvelez-le pour saisir du temps.');
        }
        $u = auth()->user();
        if ($creating) {
            $this->user_id = $u?->id;
            $this->user_name = $u?->name;
        } elseif ($u && $this->user_id && $this->user_id !== $u->id && ! $u->hasPermission('prestations.manage')) {
            throw new \DomainException('Seul l\'auteur ou un responsable modifie ce temps.');
        }
        if (isset($data['date']) && $data['date'] > now()->addDay()->toDateString()) {
            throw new \DomainException('Date future refusée.');
        }
        \App\Modules\Feuilles\FeuillesService::check($this->user_id, $data['date'] ?? $this->date?->toDateString(), $creating ? null : $this->date?->toDateString());
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        app(PrestationService::class)->refreshPack(Pack::find($this->pack_id));
    }

    public function beforeEntityDelete(): void
    {
        if ($this->timesheet_id) {
            throw new \DomainException('Temps saisi dans le projet « '.$this->project_name.' » : supprimez-le dans le projet.');
        }
        if ($this->ticket_time_id) {
            throw new \DomainException('Temps saisi sur un ticket : supprimez-le dans le ticket.');
        }
        \App\Modules\Feuilles\FeuillesService::check($this->user_id, $this->date?->toDateString());
        $u = auth()->user();
        if ($u && $this->user_id && $this->user_id !== $u->id && ! $u->hasPermission('prestations.manage')) {
            throw new \DomainException('Seul l\'auteur ou un responsable supprime ce temps.');
        }
    }

    protected static function booted(): void
    {
        static::deleted(fn (Entry $e) => ($p = Pack::find($e->pack_id)) ? app(PrestationService::class)->refreshPack($p) : null);
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['user_id' => $this->user_id, 'user_name' => $this->user_name, 'timesheet_id' => $this->timesheet_id, 'project_name' => $this->project_name, 'ticket_id' => $this->ticket_id, 'ticket_time_id' => $this->ticket_time_id];
    }
}
