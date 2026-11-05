<?php

namespace App\Modules\Projets\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/** Projet (client ou interne). */
class Project extends Model
{
    use \App\Models\Concerns\HasCustomFields, Syncable;

    protected $table = 'prj_projects';

    public static string $syncEntity = 'prj_projects';

    public static string $viewPermission = 'projets.view|projets.contribute|projets.manage';

    public static string $managePermission = 'projets.manage';

    public static array $searchable = ['name', 'code'];

    public const STATUSES = ['planned' => 'Planifié', 'active' => 'En cours', 'on_hold' => 'En pause', 'done' => 'Terminé', 'cancelled' => 'Annulé'];

    public const BILLING = ['time' => 'Au temps passé', 'fixed' => 'Forfait (jalons)', 'none' => 'Non facturé (interne)'];

    protected $fillable = ['name', 'code', 'partner_id', 'lead_id', 'manager_id', 'status', 'start_date', 'end_date', 'billing_mode', 'hourly_rate',
        'budget_amount', 'budget_hours', 'color', 'description', 'member_ids', 'tags', 'pack_id'];

    /** Forfait d'heures avant l'écriture (liaison Forfaits & livrables). */
    public ?string $previousPack = null;

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'hourly_rate' => 'float', 'budget_amount' => 'float', 'budget_hours' => 'float',
            'member_ids' => 'array', 'manager_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:30',
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'lead_id' => ['nullable', 'uuid', Rule::exists('crm_leads', 'id')->where('company_id', $company)],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'status' => 'nullable|in:'.implode(',', array_keys(self::STATUSES)),
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'billing_mode' => 'nullable|in:'.implode(',', array_keys(self::BILLING)),
            'hourly_rate' => 'nullable|numeric|min:0',
            'budget_amount' => 'nullable|numeric|min:0',
            'budget_hours' => 'nullable|numeric|min:0',
            'color' => 'nullable|string|max:9',
            'description' => 'nullable|string|max:20000',
            'member_ids' => 'nullable|array',
            'member_ids.*' => ['integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'tags' => 'nullable|string|max:255',
            'pack_id' => ['nullable', 'uuid', Rule::exists('pr_packs', 'id')->where('company_id', $company)->whereNull('deleted_at')],
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            if (! $p->exists) {
                $p->manager_id ??= auth()->id();
                $p->status ??= 'active';
            }
        });
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $this->previousPack = $creating ? null : $this->pack_id;
        $pack = array_key_exists('pack_id', $data) ? $data['pack_id'] : $this->pack_id;
        if ($pack && ($pack !== $this->previousPack || array_key_exists('partner_id', $data))) {
            if (! app('tenant')->hasModule('prestations')) {
                throw new \DomainException('Le module Forfaits & livrables n\'est pas activé.');
            }
            $p = \App\Modules\Prestations\Models\Pack::find($pack);
            $partner = array_key_exists('partner_id', $data) ? $data['partner_id'] : $this->partner_id;
            if (! $partner || $p->partner_id !== $partner) {
                throw new \DomainException('Le forfait d\'heures doit appartenir au client du projet.');
            }
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($this->pack_id !== $this->previousPack && class_exists(\App\Modules\Prestations\PrestationService::class)) {
            app(\App\Modules\Prestations\PrestationService::class)->syncProject($this, $this->previousPack);
        }
    }

    public function beforeEntityDelete(): void
    {
        if (Timesheet::where('project_id', $this->id)->whereNotNull('invoice_id')->exists()) {
            throw new \DomainException('Ce projet a déjà été facturé : passez-le en « Terminé » ou « Annulé » plutôt que de le supprimer.');
        }
        Task::where('project_id', $this->id)->get()->each->delete();
        Milestone::where('project_id', $this->id)->get()->each->delete();
        Timesheet::where('project_id', $this->id)->get()->each->delete();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class, 'project_id');
    }
}
