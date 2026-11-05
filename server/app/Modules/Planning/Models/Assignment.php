<?php

namespace App\Modules\Planning\Models;

use App\Models\Concerns\Syncable;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Affectation d'une personne à un projet, un client ou une activité sur une période. */
class Assignment extends Model
{
    use Syncable;

    protected $table = 'pl_assignments';

    public static string $syncEntity = 'pl_assignments';

    public static string $viewPermission = 'planning.view|planning.manage';

    public static string $managePermission = 'planning.manage';

    public static array $searchable = ['title', 'user_name', 'partner_name'];

    protected $fillable = ['user_id', 'project_id', 'partner_id', 'title', 'start_date', 'end_date', 'hours_per_day', 'status', 'color', 'notes'];

    protected $attributes = ['status' => 'confirmed', 'hours_per_day' => 8];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'hours_per_day' => 'float', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('company_id', $c)],
            'project_id' => ['nullable', 'uuid', Rule::exists('prj_projects', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $c)],
            'title' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'hours_per_day' => 'required|numeric|gt:0|max:24',
            'status' => 'in:confirmed,tentative',
            'color' => 'nullable|string|max:9',
            'notes' => 'nullable|string|max:3000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $start = $data['start_date'] ?? $this->start_date?->toDateString();
        $end = $data['end_date'] ?? $this->end_date?->toDateString();
        if ($end < $start) {
            throw new \DomainException('La fin de l\'affectation précède son début.');
        }
        if (Carbon::parse($start)->diffInDays(Carbon::parse($end)) > 366) {
            throw new \DomainException('Une affectation dure au plus un an : découpez-la.');
        }
        $uid = $data['user_id'] ?? $this->user_id;
        $user = User::find($uid);
        if (! $user?->is_active) {
            throw new \DomainException('Cette personne n\'est plus active.');
        }
        $this->user_name = $user->name;
        $project = ! empty($data['project_id'] ?? $this->project_id) && app('tenant')->hasModule('projets')
            ? \App\Modules\Projets\Models\Project::find($data['project_id'] ?? $this->project_id) : null;
        if ($project && empty($data['partner_id']) && $project->partner_id) {
            $data['partner_id'] = $project->partner_id;
        }
        $pid = array_key_exists('partner_id', $data) ? $data['partner_id'] : $this->partner_id;
        $this->partner_name = $pid ? Partner::find($pid)?->name : null;
        if (trim((string) ($data['title'] ?? $this->title)) === '') {
            $data['title'] = $project?->name ?? $this->partner_name ?? throw new \DomainException('Indiquez un intitulé, un projet ou un client.');
        }
        if ($project && empty($data['color'] ?? $this->color) && $project->color) {
            $data['color'] = $project->color;
        }
        if ($creating) {
            $this->created_by = auth()->user()?->name;
        }
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['user_name' => $this->user_name, 'partner_name' => $this->partner_name];
    }
}
