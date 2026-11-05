<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/** Opportunité (prospect ou affaire en cours). */
class Lead extends Model
{
    use \App\Models\Concerns\HasCustomFields, Syncable;

    protected $table = 'crm_leads';

    public static string $syncEntity = 'crm_leads';

    public static string $viewPermission = 'crm.view|crm.manage';

    public static string $managePermission = 'crm.manage';

    public static array $searchable = ['title', 'contact_name', 'company_name', 'email', 'phone'];

    public const SOURCES = [
        'website' => 'Site web', 'referral' => 'Recommandation', 'social' => 'Réseaux sociaux', 'whatsapp' => 'WhatsApp',
        'phone' => 'Appel entrant', 'event' => 'Salon / événement', 'prospecting' => 'Prospection', 'campaign' => 'Campagne marketing', 'ads' => 'Publicité en ligne', 'landing' => 'Page de capture', 'other' => 'Autre',
    ];

    protected $fillable = [
        'title', 'partner_id', 'contact_name', 'company_name', 'email', 'phone', 'city', 'source', 'stage_id',
        'expected_revenue', 'probability', 'expected_close_date', 'owner_id', 'priority', 'tags', 'notes', 'lost_reason', 'quote_id',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
    ];

    protected function casts(): array
    {
        return [
            'expected_revenue' => 'float', 'probability' => 'integer', 'priority' => 'integer', 'owner_id' => 'integer',
            'expected_close_date' => 'date:Y-m-d', 'score' => 'integer', 'won_at' => 'datetime', 'lost_at' => 'datetime', 'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'title' => 'required|string|max:255',
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'contact_name' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'source' => 'nullable|in:'.implode(',', array_keys(self::SOURCES)),
            'stage_id' => ['nullable', 'uuid', Rule::exists('crm_stages', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            'expected_revenue' => 'nullable|numeric|min:0',
            'probability' => 'nullable|integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'priority' => 'nullable|integer|min:0|max:3',
            'tags' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:10000',
            'lost_reason' => 'nullable|string|max:255',
            'utm_source' => 'nullable|string|max:100',
            'utm_medium' => 'nullable|string|max:100',
            'utm_campaign' => 'nullable|string|max:150',
            'utm_content' => 'nullable|string|max:150',
            'quote_id' => ['nullable', 'uuid', Rule::exists('trade_documents', 'id')->where('company_id', $company)->where('direction', 'sale')],
        ];
    }

    /** Le client peut forcer une probabilité ; sinon elle suit l'étape. */
    protected bool $probabilityGiven = false;

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $this->probabilityGiven = array_key_exists('probability', $data) && $data['probability'] !== null
            && (! array_key_exists('stage_id', $data) || $data['stage_id'] === $this->stage_id || $creating);
    }

    protected static function booted(): void
    {
        static::saving(function (self $l) {
            if (! $l->exists) {
                $l->owner_id ??= auth()->id();
                $l->stage_id ??= Stage::where('kind', 'open')->orderBy('sequence')->value('id');
            }
            if ($l->isDirty('stage_id') || ! $l->exists) {
                $stage = $l->stage_id ? Stage::find($l->stage_id) : null;
                $kind = $stage?->kind ?? 'open';
                if ($stage && ! $l->probabilityGiven) {
                    $l->probability = $stage->probability;
                }
                if ($kind !== $l->status || ! $l->exists) {
                    $l->status = $kind;
                    $l->won_at = $kind === 'won' ? ($l->won_at ?? now()) : null;
                    $l->lost_at = $kind === 'lost' ? ($l->lost_at ?? now()) : null;
                    if ($kind !== 'lost') {
                        $l->lost_reason = null;
                    }
                }
            }
            $l->score = \App\Modules\Crm\LeadScoring::score($l);
        });
        // Une affaire gagnée ou perdue arrête ses relances automatiques.
        static::saved(function (self $l) {
            if ($l->wasChanged('status') && $l->status !== 'open') {
                \App\Modules\Crm\Sequences::stopFor($l->company_id, (string) $l->id, $l->status === 'won' ? 'affaire gagnée' : 'affaire perdue');
            }
        });
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'stage_id');
    }

    public function weighted(): float
    {
        return round($this->expected_revenue * $this->probability / 100, 2);
    }
}
