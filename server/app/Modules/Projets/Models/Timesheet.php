<?php

namespace App\Modules\Projets\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Temps passé (feuille de temps). */
class Timesheet extends Model
{
    use Syncable;

    protected $table = 'prj_timesheets';

    public static string $syncEntity = 'prj_timesheets';

    public static string $viewPermission = 'projets.view|projets.contribute|projets.manage';

    public static string $managePermission = 'projets.manage|projets.contribute';

    protected $fillable = ['project_id', 'task_id', 'user_id', 'date', 'hours', 'description', 'billable'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'hours' => 'float', 'billable' => 'boolean', 'user_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'project_id' => ['required', 'uuid', Rule::exists('prj_projects', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            'task_id' => ['nullable', 'uuid', Rule::exists('prj_tasks', 'id')->where('company_id', $company)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'date' => 'required|date',
            'hours' => 'required|numeric|min:0.05|max:24',
            'description' => 'nullable|string|max:255',
            'billable' => 'nullable|boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating && $this->invoice_id) {
            throw new \DomainException('Ce temps a déjà été facturé et ne peut plus être modifié.');
        }
        $user = auth()->user();
        $data['user_id'] = $data['user_id'] ?? ($creating ? $user?->id : $this->user_id);
        if ($user && ! $user->hasPermission('projets.manage') && (int) $data['user_id'] !== $user->id) {
            throw new \DomainException('Vous ne pouvez saisir que votre propre temps.');
        }
        if (! empty($data['task_id']) && ($t = Task::find($data['task_id'])) && $t->project_id !== ($data['project_id'] ?? $this->project_id)) {
            throw new \DomainException('La tâche n\'appartient pas à ce projet.');
        }
        \App\Modules\Feuilles\FeuillesService::check((int) $data['user_id'], $data['date'] ?? $this->date?->toDateString(), $creating ? null : $this->date?->toDateString());
        if (! $creating && (int) $data['user_id'] !== (int) $this->user_id) {
            \App\Modules\Feuilles\FeuillesService::check((int) $this->user_id, $this->date?->toDateString());
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if (class_exists(\App\Modules\Prestations\PrestationService::class)) {
            app(\App\Modules\Prestations\PrestationService::class)->syncTimesheet($this);
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->invoice_id) {
            throw new \DomainException('Ce temps a déjà été facturé.');
        }
        \App\Modules\Feuilles\FeuillesService::check((int) $this->user_id, $this->date?->toDateString());
    }

    protected static function booted(): void
    {
        static::deleted(function (self $t) {
            if ($t->pack_id && class_exists(\App\Modules\Prestations\PrestationService::class)) {
                app(\App\Modules\Prestations\PrestationService::class)->unlinkTimesheet($t);
            }
        });
    }

    /** Champs calculés envoyés aux appareils. */
    public function toSyncArray(): array
    {
        return $this->toArray() + ['pack_id' => $this->pack_id, 'invoice_id' => $this->invoice_id];
    }
}
