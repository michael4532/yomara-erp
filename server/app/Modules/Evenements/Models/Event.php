<?php

namespace App\Modules\Evenements\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Événement (salon, foire, festival, conférence) dont on vend les espaces et les offres. */
class Event extends Model
{
    use Syncable;

    protected $table = 'ev_events';

    public static string $syncEntity = 'ev_events';

    public static string $viewPermission = 'evenements.view|evenements.sell|evenements.manage|billetterie.scan|billetterie.sell|billetterie.manage';

    public static string $managePermission = 'evenements.manage';

    public static array $searchable = ['name', 'city'];

    public const STATUSES = ['draft' => 'Préparation', 'open' => 'Ventes ouvertes', 'closed' => 'Ventes closes', 'done' => 'Terminé', 'cancelled' => 'Annulé'];

    protected $fillable = ['name', 'venue', 'city', 'start_date', 'end_date', 'status', 'description', 'plan_cols', 'plan_rows', 'categories', 'currency',
        'tax_rate', 'deposit_percent', 'balance_days', 'option_days', 'public_enabled', 'terms', 'online_deposit', 'ticketing_enabled', 'capacity', 'registration', 'feedback_enabled'];

    protected $attributes = ['status' => 'draft', 'plan_cols' => 24, 'plan_rows' => 14, 'tax_rate' => 18, 'deposit_percent' => 50, 'balance_days' => 15, 'option_days' => 7, 'public_enabled' => false];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'categories' => 'array', 'public_enabled' => 'boolean', 'plan_cols' => 'integer', 'plan_rows' => 'integer',
            'tax_rate' => 'float', 'deposit_percent' => 'float', 'balance_days' => 'integer', 'option_days' => 'integer', 'version' => 'integer',
            'online_deposit' => 'boolean', 'ticketing_enabled' => 'boolean', 'capacity' => 'integer', 'registration' => 'array', 'feedback_enabled' => 'boolean', 'feedback_sent_at' => 'datetime'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'venue' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'status' => 'in:'.implode(',', array_keys(self::STATUSES)),
            'description' => 'nullable|string|max:5000',
            'plan_cols' => 'integer|min:4|max:80',
            'plan_rows' => 'integer|min:4|max:80',
            'categories' => 'nullable|array|max:20',
            'categories.*.name' => 'required|string|max:80',
            'categories.*.color' => 'nullable|string|max:9',
            'categories.*.price' => 'nullable|numeric|min:0',
            'categories.*.area' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'tax_rate' => 'numeric|min:0|max:100',
            'deposit_percent' => 'numeric|min:0|max:100',
            'balance_days' => 'integer|min:0|max:365',
            'option_days' => 'integer|min:1|max:90',
            'public_enabled' => 'boolean',
            'terms' => 'nullable|string|max:5000',
            'online_deposit' => 'boolean',
            'ticketing_enabled' => 'boolean',
            'capacity' => 'nullable|integer|min:1|max:10000000',
            'registration' => 'nullable|array',
            'registration.questions' => 'nullable|array|max:20',
            'registration.questions.*.label' => 'nullable|string|max:160',
            'registration.questions.*.type' => 'nullable|string|max:20',
            'registration.questions.*.options' => 'nullable|array|max:30',
            'registration.consent_text' => 'nullable|string|max:500',
            'registration.confirmation_message' => 'nullable|string|max:1000',
            'feedback_enabled' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $start = $data['start_date'] ?? $this->start_date?->toDateString();
        $end = $data['end_date'] ?? $this->end_date?->toDateString();
        if ($start && $end && $end < $start) {
            throw new \DomainException('La date de fin précède le début de l\'événement.');
        }
        if (isset($data['categories'])) {
            $data['categories'] = array_values(array_map(fn ($c) => array_filter([
                'name' => trim($c['name']), 'color' => $c['color'] ?? null,
                'price' => isset($c['price']) ? round((float) $c['price'], 2) : null, 'area' => isset($c['area']) ? round((float) $c['area'], 2) : null,
            ], fn ($v) => $v !== null && $v !== ''), $data['categories']));
        }
        if (! $this->public_token) {
            $this->public_token = Str::random(40);
        }
        if (array_key_exists('registration', $data)) {
            $data['registration'] = $data['registration'] ? \App\Modules\Billetterie\RegistrationService::cleanForm((array) $data['registration']) : null;
        }
        foreach (['plan_cols', 'plan_rows'] as $k) {
            if (! $creating && isset($data[$k]) && (int) $data[$k] < (int) $this->{$k}) {
                $max = Space::where('event_id', $this->id)->max($k === 'plan_cols' ? \DB::raw('x + w') : \DB::raw('y + h'));
                if ($max && (int) $data[$k] < (int) $max) {
                    throw new \DomainException('Des emplacements dépassent du plan : agrandissez-le ou déplacez-les d\'abord.');
                }
            }
        }
    }

    public function beforeEntityDelete(): void
    {
        if (Booking::where('event_id', $this->id)->exists()) {
            throw new \DomainException('Cet événement a des réservations : annulez-le plutôt.');
        }
        Space::where('event_id', $this->id)->get()->each->delete();
        Package::where('event_id', $this->id)->get()->each->delete();
    }

    public function publicUrl(): string
    {
        return url('/salon/'.$this->public_token);
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['public_token' => $this->public_token, 'public_url' => $this->public_token ? $this->publicUrl() : null,
            'ticketing_url' => $this->public_token ? url('/billetterie/'.$this->public_token) : null];
    }
}
