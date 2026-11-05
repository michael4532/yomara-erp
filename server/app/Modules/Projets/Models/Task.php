<?php

namespace App\Modules\Projets\Models;

use App\Models\AppNotification;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

class Task extends Model
{
    use Syncable;

    protected $table = 'prj_tasks';

    public static string $syncEntity = 'prj_tasks';

    public static string $viewPermission = 'projets.view|projets.contribute|projets.manage';

    public static string $managePermission = 'projets.manage|projets.contribute';

    public static array $searchable = ['title'];

    public const STATUSES = ['todo' => 'À faire', 'doing' => 'En cours', 'review' => 'À valider', 'done' => 'Terminé'];

    protected $fillable = ['project_id', 'milestone_id', 'title', 'description', 'status', 'priority', 'assignee_id', 'start_date', 'due_date',
        'estimate_hours', 'sort', 'checklist', 'tags', 'blocked_reason'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'estimate_hours' => 'float', 'sort' => 'float', 'priority' => 'integer',
            'assignee_id' => 'integer', 'checklist' => 'array', 'done_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'project_id' => ['required', 'uuid', Rule::exists('prj_projects', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            'milestone_id' => ['nullable', 'uuid', Rule::exists('prj_milestones', 'id')->where('company_id', $company)],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:20000',
            'status' => 'nullable|in:'.implode(',', array_keys(self::STATUSES)),
            'priority' => 'nullable|integer|min:0|max:3',
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'estimate_hours' => 'nullable|numeric|min:0|max:10000',
            'sort' => 'nullable|numeric',
            'checklist' => 'nullable|array|max:100',
            'checklist.*.text' => 'required|string|max:255',
            'checklist.*.done' => 'nullable|boolean',
            'tags' => 'nullable|string|max:255',
            'blocked_reason' => 'nullable|string|max:255',
        ];
    }

    /** Un contributeur ne crée et ne déplace que ses propres tâches (ou des tâches sans responsable). */
    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('projets.manage')) {
            $assignee = array_key_exists('assignee_id', $data) ? $data['assignee_id'] : $this->assignee_id;
            if (! $creating && $this->assignee_id && $this->assignee_id !== $user->id) {
                throw new \DomainException('Cette tâche est attribuée à quelqu\'un d\'autre.');
            }
            if ($assignee && (int) $assignee !== $user->id) {
                throw new \DomainException('Vous ne pouvez attribuer une tâche qu\'à vous-même.');
            }
        }
    }

    protected static function booted(): void
    {
        static::saving(function (self $t) {
            if ($t->isDirty('status')) {
                $t->done_at = $t->status === 'done' ? ($t->done_at ?? now()) : null;
            }
            $t->status ??= 'todo';
        });
        static::saved(function (self $t) {
            if ($t->assignee_id && $t->wasChanged('assignee_id') || ($t->wasRecentlyCreated && $t->assignee_id)) {
                if ($t->assignee_id !== auth()->id()) {
                    $project = Project::find($t->project_id);
                    AppNotification::send($t->company_id, [$t->assignee_id], 'task', 'Nouvelle tâche : '.$t->title,
                        ($project ? 'Projet « '.$project->name.' »' : '').($t->due_date ? ' · échéance le '.$t->due_date->format('d/m/Y') : ''), 'projets', $t->id);
                }
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
