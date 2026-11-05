<?php

namespace App\Modules\Projets\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Jalon : étape clé, éventuellement facturable (forfait). */
class Milestone extends Model
{
    use Syncable;

    protected $table = 'prj_milestones';

    public static string $syncEntity = 'prj_milestones';

    public static string $viewPermission = 'projets.view|projets.contribute|projets.manage';

    public static string $managePermission = 'projets.manage';

    protected $fillable = ['project_id', 'name', 'due_date', 'amount', 'status'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'amount' => 'float', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'project_id' => ['required', 'uuid', Rule::exists('prj_projects', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            'name' => 'required|string|max:255',
            'due_date' => 'nullable|date',
            'amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:open,reached,invoiced',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating && $this->invoice_id) {
            // Jalon déjà facturé : seul le nom peut encore changer
            $data = array_intersect_key($data, array_flip(['name']));
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->invoice_id) {
            throw new \DomainException('Ce jalon a été facturé.');
        }
        Task::where('milestone_id', $this->id)->update(['milestone_id' => null]);
    }
}
