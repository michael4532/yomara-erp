<?php

namespace App\Modules\Recrutement\Models;

use App\Models\ActivityLog;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Candidature (sur une offre ou spontanée). */
class Application extends Model
{
    use Syncable;

    protected $table = 'rc_applications';

    public static string $syncEntity = 'rc_applications';

    public static string $viewPermission = 'recrutement.view|recrutement.manage|recrutement.interview';

    public static string $managePermission = 'recrutement.manage';

    protected $fillable = ['job_id', 'first_name', 'last_name', 'email', 'phone', 'city', 'source', 'cover_letter', 'stage', 'rating', 'notes', 'reject_reason', 'expected_salary', 'available_from'];

    protected $hidden = ['cv_path'];

    protected $appends = ['has_cv'];

    public const STAGES = ['new' => 'Nouvelle', 'screening' => 'Présélection', 'interview' => 'Entretien', 'offer' => 'Proposition', 'hired' => 'Embauché', 'rejected' => 'Non retenue'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'available_from' => 'date:Y-m-d', 'consent_at' => 'datetime', 'stage_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'job_id' => ['nullable', 'uuid', Rule::exists('rc_jobs', 'id')->where('company_id', app('tenant')->id)],
            'first_name' => 'required|string|max:120',
            'last_name' => 'required|string|max:120',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:40',
            'city' => 'nullable|string|max:120',
            'source' => 'nullable|in:website,manual,referral,whatsapp,facebook,linkedin,other',
            'cover_letter' => 'nullable|string|max:20000',
            'stage' => 'nullable|in:new,screening,interview,offer,hired,rejected',
            'rating' => 'nullable|integer|min:1|max:5',
            'notes' => 'nullable|string|max:20000',
            'reject_reason' => 'nullable|string|max:255',
            'expected_salary' => 'nullable|string|max:80',
            'available_from' => 'nullable|date',
        ];
    }

    /** Intervenant (entretiens seulement) : uniquement les candidats qu'il rencontre. */
    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('recrutement.view|recrutement.manage')) {
            $ids = Interview::all()->filter(fn ($i) => in_array($user->id, (array) $i->interviewer_ids, true))->pluck('application_id')->unique()->all();
            $q->whereIn('id', $ids ?: ['00000000-0000-0000-0000-000000000000']);
        }
    }

    public function getHasCvAttribute(): bool
    {
        return (bool) $this->cv_path;
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $data['source'] ??= $creating ? 'manual' : $this->source;
        $stage = $data['stage'] ?? ($creating ? 'new' : $this->stage);
        if ($creating) {
            $data['stage'] = $stage;
        }
        if (! $creating && $this->stage === 'hired' && $stage !== 'hired') {
            throw new \DomainException('Candidat déjà embauché : sa fiche salarié existe.');
        }
        if ($stage === 'hired' && $this->stage !== 'hired') {
            throw new \DomainException('Utilisez « Embaucher » pour créer la fiche salarié.');
        }
        if ($stage === 'rejected' && blank($data['reject_reason'] ?? $this->reject_reason)) {
            throw new \DomainException('Indiquez le motif (pour vos statistiques ; il n\'est pas envoyé au candidat).');
        }
        if ($creating || $stage !== $this->stage) {
            $this->stage_at = now();
        }
        $data['stage'] = $stage;
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($creating || $this->wasChanged('stage')) {
            ActivityLog::record('recrutement.'.($creating ? 'created' : $this->stage), $this, ['name' => $this->first_name.' '.$this->last_name]);
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->stage === 'hired') {
            throw new \DomainException('Candidat embauché : la candidature est conservée.');
        }
        app(\App\Modules\Recrutement\RecrutementService::class)->deleteCv($this);
        Interview::where('application_id', $this->id)->get()->each->delete();
    }
}
