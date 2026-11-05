<?php

namespace App\Modules\Rh\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Département / service : ses chefs voient leur équipe (collaborateurs et stagiaires) dans « Mon équipe »,
 * avec les informations que la RH leur ouvre (contrat, salaire, documents, coordonnées, présence).
 */
class Department extends Model
{
    use Syncable;

    protected $table = 'rh_departments';

    public static string $syncEntity = 'rh_departments';

    public static string $viewPermission = 'rh.view|rh.manage|rh.self|paie.view|paie.manage';

    public static string $managePermission = 'rh.manage';

    /** Ce que la RH peut ouvrir aux chefs de département. */
    public const VISIBILITY = [
        'presence' => 'Présence, congés et absences',
        'requests' => 'Demandes de l\'équipe',
        'personal' => 'Coordonnées et pièces d\'identité',
        'contract' => 'Type de contrat et dates (CDI, CDD, stage…)',
        'salary' => 'Salaires et bulletins',
        'documents' => 'Documents RH et justificatifs',
        'work' => 'Travaux : tâches, temps passés, rapports',
    ];

    protected $fillable = ['name', 'code', 'parent_id', 'head_ids', 'visibility', 'color', 'description', 'active'];

    protected function casts(): array
    {
        return ['head_ids' => 'array', 'visibility' => 'array', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'name' => 'required|string|max:120',
            'code' => 'nullable|string|max:30',
            'parent_id' => ['nullable', 'uuid', Rule::exists('rh_departments', 'id')->where('company_id', $company)],
            'head_ids' => 'nullable|array|max:5',
            'head_ids.*' => ['uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'visibility' => 'nullable|array',
            'visibility.*' => 'boolean',
            'color' => 'nullable|string|max:9',
            'description' => 'nullable|string|max:2000',
            'active' => 'nullable|boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if (static::whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->where('id', '!=', $this->id ?? '')->exists()) {
                throw new \DomainException('Le département « '.$data['name'].' » existe déjà.');
            }
        }
        if (isset($data['visibility'])) {
            $data['visibility'] = array_intersect_key(array_map('boolval', $data['visibility']), self::VISIBILITY);
        }
        $parent = $data['parent_id'] ?? null;
        $seen = [$this->id];
        while ($parent) {
            if (in_array($parent, $seen, true)) {
                throw new \DomainException('Ce rattachement crée une boucle entre départements.');
            }
            $seen[] = $parent;
            $parent = static::whereKey($parent)->value('parent_id');
        }
        if ($creating) {
            $data['visibility'] ??= ['presence' => true, 'requests' => true, 'work' => true];
            $data['head_ids'] ??= [];
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        // Renommage : les fiches gardent le nom du service à jour (documents, bulletins)
        if (! $creating && $this->wasChanged('name')) {
            Employee::where('department_id', $this->id)->get()->each(fn ($e) => $e->forceFill(['department' => $this->name])->save());
        }
    }

    public function beforeEntityDelete(): void
    {
        if (Employee::where('department_id', $this->id)->where('active', true)->exists()) {
            throw new \DomainException('Des salariés sont rattachés à ce département : changez-les d\'abord, ou désactivez-le.');
        }
        if (static::where('parent_id', $this->id)->exists()) {
            throw new \DomainException('D\'autres départements y sont rattachés.');
        }
    }

    /** Départements dirigés par ce salarié, avec leurs sous-départements. */
    public static function ledBy(string $employeeId): \Illuminate\Support\Collection
    {
        $all = static::where('active', true)->get();
        $roots = $all->filter(fn ($d) => in_array($employeeId, (array) $d->head_ids, true));
        $out = collect();
        $walk = function ($d) use (&$walk, $all, $out) {
            if ($out->has($d->id)) {
                return;
            }
            $out->put($d->id, $d);
            $all->where('parent_id', $d->id)->each($walk);
        };
        $roots->each($walk);

        return $out->values();
    }
}
