<?php

namespace App\Modules\Rh\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Fiche de poste : intitulé, service, catégorie, rattachement, missions, compétences, effectif prévu. */
class Position extends Model
{
    use Syncable;

    protected $table = 'rh_positions';

    public static string $syncEntity = 'rh_positions';

    /** Les salariés voient les fiches de poste (la leur dans leur espace). */
    public static string $viewPermission = 'rh.view|rh.manage|rh.self|paie.view|paie.manage';

    public static string $managePermission = 'rh.manage';

    protected $fillable = [
        'title', 'code', 'department', 'category_id', 'parent_id', 'contract_type', 'salary_min', 'salary_max', 'headcount',
        'purpose', 'missions', 'skills', 'requirements', 'conditions', 'active',
    ];

    protected function casts(): array
    {
        return ['salary_min' => 'float', 'salary_max' => 'float', 'headcount' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'title' => 'required|string|max:160',
            'code' => 'nullable|string|max:30',
            'department' => 'nullable|string|max:120',
            'category_id' => ['nullable', 'uuid', Rule::exists('rh_categories', 'id')->where('company_id', $company)],
            'parent_id' => ['nullable', 'uuid', Rule::exists('rh_positions', 'id')->where('company_id', $company)],
            'contract_type' => 'nullable|in:CDI,CDD,stage,journalier,consultant',
            'salary_min' => 'nullable|numeric|min:0',
            'salary_max' => 'nullable|numeric|min:0',
            'headcount' => 'nullable|integer|min:0|max:10000',
            'purpose' => 'nullable|string|max:5000',
            'missions' => 'nullable|string|max:20000',
            'skills' => 'nullable|string|max:10000',
            'requirements' => 'nullable|string|max:10000',
            'conditions' => 'nullable|string|max:5000',
            'active' => 'nullable|boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (array_key_exists('active', $data) && $data['active'] === null) {
            $data['active'] = true;
        }
        $min = $data['salary_min'] ?? $this->salary_min;
        $max = $data['salary_max'] ?? $this->salary_max;
        if ($min !== null && $max !== null && $max > 0 && $max < $min) {
            throw new \DomainException('Le salaire maximum est inférieur au minimum.');
        }
        // Rattachement : pas à lui-même, pas de boucle dans l'organigramme
        $parent = $data['parent_id'] ?? null;
        $seen = [$this->id];
        while ($parent) {
            if (in_array($parent, $seen, true)) {
                throw new \DomainException('Ce rattachement crée une boucle dans l\'organigramme.');
            }
            $seen[] = $parent;
            $parent = static::whereKey($parent)->value('parent_id');
        }
    }

    public function beforeEntityDelete(): void
    {
        if (Employee::where('position_id', $this->id)->exists()) {
            throw new \DomainException('Des salariés occupent ce poste : rattachez-les à un autre poste, ou désactivez la fiche.');
        }
        if (static::where('parent_id', $this->id)->exists()) {
            throw new \DomainException('D\'autres postes sont rattachés à celui-ci : changez d\'abord leur rattachement.');
        }
    }
}
