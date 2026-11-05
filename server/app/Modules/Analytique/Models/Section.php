<?php

namespace App\Modules\Analytique\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Section analytique (centre de coût / de profit) rattachée à un axe. */
class Section extends Model
{
    use Syncable;

    protected $table = 'an_sections';

    public static string $syncEntity = 'an_sections';

    public static string $viewPermission = 'analytique.view|analytique.manage|ventes.manage|achats.manage|achats.request|notes_frais.manage|rh.self';

    public static string $managePermission = 'analytique.manage';

    protected $fillable = ['axis_id', 'parent_id', 'code', 'name', 'manager_id', 'active', 'notes'];

    protected $attributes = ['active' => true];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'manager_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'axis_id' => ['required', 'uuid', Rule::exists('an_axes', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'parent_id' => ['nullable', 'uuid', Rule::exists('an_sections', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'code' => 'nullable|string|max:30',
            'name' => 'required|string|max:120',
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $c)],
            'active' => 'boolean',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating && isset($data['axis_id']) && $data['axis_id'] !== $this->axis_id && AnLine::where('section_id', $this->id)->exists()) {
            throw new \DomainException('Cette section a déjà des montants : elle ne peut pas changer d\'axe.');
        }
        if (! empty($data['parent_id'])) {
            if ($data['parent_id'] === $this->id) {
                throw new \DomainException('Une section ne peut pas être sa propre section parente.');
            }
            $parent = self::find($data['parent_id']);
            if ($parent && $parent->axis_id !== ($data['axis_id'] ?? $this->axis_id)) {
                throw new \DomainException('La section parente doit appartenir au même axe.');
            }
        }
    }

    public function beforeEntityDelete(): void
    {
        if (AnLine::where('section_id', $this->id)->exists()) {
            throw new \DomainException('Des montants sont ventilés sur « '.$this->name.' » : désactivez la section plutôt.');
        }
        if ($this->project_id) {
            throw new \DomainException('Cette section suit un projet : elle disparaît avec lui.');
        }
    }
}
