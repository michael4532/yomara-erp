<?php

namespace App\Modules\Recrutement\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Offre d'emploi. */
class Job extends Model
{
    use Syncable;

    protected $table = 'rc_jobs';

    public static string $syncEntity = 'rc_jobs';

    public static string $viewPermission = 'recrutement.view|recrutement.manage|recrutement.interview';

    public static string $managePermission = 'recrutement.manage';

    protected $fillable = ['title', 'department', 'location', 'contract_type', 'salary', 'description', 'requirements', 'positions', 'status', 'published', 'closes_at', 'owner_id'];

    protected function casts(): array
    {
        return ['positions' => 'integer', 'published' => 'boolean', 'closes_at' => 'date:Y-m-d', 'owner_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'title' => 'required|string|max:160',
            'department' => 'nullable|string|max:120',
            'location' => 'nullable|string|max:120',
            'contract_type' => 'nullable|in:CDI,CDD,stage,journalier,consultant',
            'salary' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:20000',
            'requirements' => 'nullable|string|max:20000',
            'positions' => 'nullable|integer|min:1|max:500',
            'status' => 'nullable|in:draft,open,closed',
            'published' => 'nullable|boolean',
            'closes_at' => 'nullable|date',
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', app('tenant')->id)],
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        foreach (['positions' => 1, 'contract_type' => 'CDI', 'status' => 'draft', 'published' => true] as $k => $v) {
            if (($creating && ! array_key_exists($k, $data)) || (array_key_exists($k, $data) && $data[$k] === null)) {
                $data[$k] = $v;
            }
        }
        if (($data['status'] ?? $this->status) === 'open' && blank($data['description'] ?? $this->description)) {
            throw new \DomainException('Décrivez le poste avant de publier l\'offre.');
        }
    }

    public function beforeEntityDelete(): void
    {
        if (Application::where('job_id', $this->id)->exists()) {
            throw new \DomainException('Cette offre a reçu des candidatures : clôturez-la plutôt.');
        }
    }

    public function isPublic(): bool
    {
        return $this->status === 'open' && $this->published && (! $this->closes_at || $this->closes_at->gte(now()->startOfDay()));
    }
}
