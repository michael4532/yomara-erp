<?php

namespace App\Modules\Recrutement\Models;

use App\Models\AppNotification;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Entretien : date, lieu, intervenants, puis avis (note, recommandation, compte rendu). */
class Interview extends Model
{
    use Syncable;

    protected $table = 'rc_interviews';

    public static string $syncEntity = 'rc_interviews';

    public static string $viewPermission = 'recrutement.view|recrutement.manage|recrutement.interview';

    public static string $managePermission = 'recrutement.manage|recrutement.interview';

    protected $fillable = ['application_id', 'starts_at', 'duration', 'location', 'interviewer_ids', 'status', 'score', 'recommendation', 'feedback'];

    public const FEEDBACK = ['status', 'score', 'recommendation', 'feedback'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime:Y-m-d H:i:s', 'duration' => 'integer', 'interviewer_ids' => 'array', 'score' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'application_id' => ['required', 'uuid', Rule::exists('rc_applications', 'id')->where('company_id', $company)],
            'starts_at' => 'required|date',
            'duration' => 'nullable|integer|min:10|max:480',
            'location' => 'nullable|string|max:255',
            'interviewer_ids' => 'nullable|array|max:10',
            'interviewer_ids.*' => ['integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'status' => 'nullable|in:planned,done,cancelled',
            'score' => 'nullable|integer|min:1|max:5',
            'recommendation' => 'nullable|in:yes,maybe,no',
            'feedback' => 'nullable|string|max:20000',
        ];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('recrutement.view|recrutement.manage')) {
            $ids = static::all()->filter(fn ($i) => in_array($user->id, (array) $i->interviewer_ids, true))->pluck('id')->all();
            $q->whereIn('id', $ids ?: ['00000000-0000-0000-0000-000000000000']);
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('recrutement.manage')) {
            if ($creating || ! in_array($user->id, (array) $this->interviewer_ids, true)) {
                throw new \DomainException('Seul le responsable du recrutement planifie les entretiens.');
            }
            $data = array_intersect_key($data, array_flip(self::FEEDBACK));
        }
        if ($creating) {
            $data['status'] ??= 'planned';
            $data['duration'] ??= 45;
        }
        if (array_key_exists('interviewer_ids', $data)) {
            $data['interviewer_ids'] = array_values(array_unique(array_map('intval', (array) $data['interviewer_ids'])));
        }
        if (($data['status'] ?? $this->status) === 'done' && ! ($data['score'] ?? $this->score)) {
            throw new \DomainException('Notez le candidat (1 à 5) pour clôturer l\'entretien.');
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        $app = Application::find($this->application_id);
        if ($creating && $app && in_array($app->stage, ['new', 'screening'], true)) {
            $app->forceFill(['stage' => 'interview', 'stage_at' => now()])->save();
        }
        $user = auth()->user();
        if ($creating || $this->wasChanged(['starts_at', 'interviewer_ids'])) {
            $who = array_values(array_diff((array) $this->interviewer_ids, [$user?->id]));
            AppNotification::send($this->company_id, $who, 'recrutement', 'Entretien de recrutement',
                ($app ? $app->first_name.' '.$app->last_name : 'Candidat').' — '.$this->starts_at->format('d/m/Y à H:i').($this->location ? ' · '.$this->location : ''), 'recrutement', $this->id);
        }
    }

    /** Suppression (y compris par la synchronisation hors ligne) réservée aux responsables. */
    public function beforeEntityDelete(): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('recrutement.manage')) {
            throw new \DomainException('Seuls les responsables du recrutement peuvent supprimer un entretien.');
        }
    }
}
