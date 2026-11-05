<?php

namespace App\Modules\Approbations\Models;

use App\Models\Concerns\Syncable;
use App\Models\Role;
use App\Models\User;
use App\Modules\Approbations\ApprovalService;
use Illuminate\Database\Eloquent\Model;

/** Circuit d'approbation : cibles, conditions (montant, remise, tiers, catégorie) et étapes successives. */
class Rule extends Model
{
    use Syncable;

    protected $table = 'wf_rules';

    public static string $syncEntity = 'wf_rules';

    public static string $viewPermission = ApprovalService::READERS;

    public static string $managePermission = 'approbations.manage';

    protected $fillable = ['name', 'targets', 'min_amount', 'max_amount', 'discount_over', 'partner_ids', 'category_ids', 'steps', 'auto_validate', 'reminder_hours', 'sort', 'active'];

    protected $attributes = ['auto_validate' => true, 'reminder_hours' => 24, 'sort' => 0, 'active' => true];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['targets' => 'array', 'partner_ids' => 'array', 'category_ids' => 'array', 'steps' => 'array', 'min_amount' => 'float', 'max_amount' => 'float',
            'discount_over' => 'float', 'auto_validate' => 'boolean', 'reminder_hours' => 'integer', 'sort' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'targets' => 'required|array|min:1',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'discount_over' => 'nullable|numeric|min:0|max:100',
            'partner_ids' => 'nullable|array',
            'category_ids' => 'nullable|array',
            'steps' => 'required|array|min:1|max:5',
            'auto_validate' => 'boolean',
            'reminder_hours' => 'integer|min:0|max:720',
            'sort' => 'integer|min:0|max:999',
            'active' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (array_key_exists('targets', $data)) {
            $data['targets'] = array_values(array_intersect(array_keys(ApprovalService::TARGETS), (array) $data['targets']));
            if (! $data['targets']) {
                throw new \DomainException('Choisissez au moins un type de document.');
            }
        }
        $min = $data['min_amount'] ?? $this->min_amount;
        $max = $data['max_amount'] ?? $this->max_amount;
        if ($min !== null && $max !== null && (float) $max < (float) $min) {
            throw new \DomainException('Le montant maximum doit être supérieur au minimum.');
        }
        foreach (['partner_ids', 'category_ids'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = array_values(array_unique(array_filter(array_map('strval', (array) $data[$k])))) ?: null;
            }
        }
        if (array_key_exists('steps', $data)) {
            $roles = Role::pluck('id')->map(fn ($i) => (int) $i)->all();
            $users = User::where('company_id', app('tenant')->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $steps = [];
            foreach (array_slice((array) $data['steps'], 0, 5) as $i => $s) {
                $r = array_values(array_intersect($roles, array_map('intval', (array) ($s['roles'] ?? []))));
                $u = array_values(array_intersect($users, array_map('intval', (array) ($s['users'] ?? []))));
                $mgr = ! empty($s['manager']);
                if (! $r && ! $u && ! $mgr) {
                    throw new \DomainException('Étape '.($i + 1).' : choisissez au moins un rôle, une personne ou le responsable hiérarchique.');
                }
                $esc = isset($s['escalate_after_hours']) && (int) $s['escalate_after_hours'] > 0 ? min(720, (int) $s['escalate_after_hours']) : null;
                $escRoles = $esc ? array_values(array_intersect($roles, array_map('intval', (array) ($s['escalate_roles'] ?? [])))) : [];
                $steps[] = [
                    'name' => trim((string) ($s['name'] ?? '')) ?: 'Étape '.($i + 1),
                    'mode' => ($s['mode'] ?? 'any') === 'all' ? 'all' : 'any',
                    'roles' => $r, 'users' => $u, 'manager' => $mgr,
                    'escalate_after_hours' => $escRoles ? $esc : null, 'escalate_roles' => $escRoles,
                ];
            }
            if (! $steps) {
                throw new \DomainException('Ajoutez au moins une étape.');
            }
            $data['steps'] = $steps;
        }
    }
}
