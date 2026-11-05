<?php

namespace App\Modules\RendezVous\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Rendez-vous. */
class Appointment extends Model
{
    use Syncable;

    protected $table = 'rdv_appointments';

    public static string $syncEntity = 'rdv_appointments';

    public static string $viewPermission = 'rendez_vous.view|rendez_vous.own|rendez_vous.manage';

    public static string $managePermission = 'rendez_vous.manage|rendez_vous.own';

    public static array $searchable = ['number', 'customer_name', 'customer_phone', 'customer_email'];

    public const STATUSES = ['pending' => 'À confirmer', 'confirmed' => 'Confirmé', 'done' => 'Honoré', 'no_show' => 'Absent', 'cancelled' => 'Annulé'];

    /** Statuts qui occupent un créneau. */
    public const BUSY = ['pending', 'confirmed', 'done'];

    protected $fillable = ['service_id', 'staff_id', 'partner_id', 'customer_name', 'customer_phone', 'customer_email', 'starts_at', 'ends_at', 'status', 'source',
        'notes', 'internal_notes', 'price', 'cancel_reason', 'notify'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'reminded_at' => 'datetime', 'price' => 'float', 'notify' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'service_id' => ['nullable', 'uuid', Rule::exists('rdv_services', 'id')->where('company_id', $company)],
            'staff_id' => ['nullable', 'uuid', Rule::exists('rdv_staff', 'id')->where('company_id', $company)],
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:40',
            'customer_email' => 'nullable|email|max:255',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'status' => 'nullable|in:'.implode(',', array_keys(self::STATUSES)),
            'source' => 'nullable|in:manual,online,portal',
            'notes' => 'nullable|string|max:3000',
            'internal_notes' => 'nullable|string|max:3000',
            'price' => 'nullable|numeric|min:0',
            'cancel_reason' => 'nullable|string|max:255',
            'notify' => 'nullable|boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('rendez_vous.manage')) {
            $mine = Staff::where('user_id', $user->id)->pluck('id')->all();
            $staff = $data['staff_id'] ?? $this->staff_id;
            if (! $staff || ! in_array($staff, $mine, true) || (! $creating && ! in_array($this->staff_id, $mine, true))) {
                throw new \DomainException('Vous ne gérez que vos propres rendez-vous.');
            }
            unset($data['price']);
        }
        if ($this->invoice_id && array_intersect(array_keys($data), ['price', 'service_id', 'partner_id'])) {
            throw new \DomainException('Ce rendez-vous est facturé.');
        }
        // Heures normalisées en UTC (l'appareil peut envoyer un décalage horaire)
        foreach (['starts_at', 'ends_at'] as $k) {
            if (! empty($data[$k])) {
                $data[$k] = Carbon::parse($data[$k])->utc()->toDateTimeString();
            }
        }
        // Durée et prix de la prestation par défaut
        $service = ! empty($data['service_id'] ?? $this->service_id) ? Service::find($data['service_id'] ?? $this->service_id) : null;
        if (isset($data['starts_at']) && empty($data['ends_at'])) {
            $data['ends_at'] = Carbon::parse($data['starts_at'], 'UTC')->addMinutes($service?->duration_minutes ?? 30)->toDateTimeString();
        }
        if ($creating && $service && ! isset($data['price'])) {
            $data['price'] = $service->price;
        }
        $start = isset($data['starts_at']) ? Carbon::parse($data['starts_at'], 'UTC') : $this->starts_at->copy()->utc();
        $end = isset($data['ends_at']) ? Carbon::parse($data['ends_at'], 'UTC') : $this->ends_at->copy()->utc();
        $status = $data['status'] ?? $this->status ?? 'confirmed';
        $staff = $data['staff_id'] ?? $this->staff_id;
        if ($staff && in_array($status, self::BUSY, true) && (isset($data['starts_at']) || isset($data['ends_at']) || isset($data['staff_id']) || isset($data['status']))) {
            $buffer = $service?->buffer_minutes ?? 0;
            $clash = self::where('staff_id', $staff)->whereIn('status', self::BUSY)->when(! $creating, fn ($q) => $q->where('id', '!=', $this->id))
                ->where('starts_at', '<', $end->copy()->addMinutes($buffer)->toDateTimeString())->where('ends_at', '>', $start->toDateTimeString())->first();
            if ($clash) {
                throw new \DomainException('Créneau déjà pris : '.($clash->customer_name ?: 'rendez-vous').' de '.$clash->starts_at->timezone(self::tz())->format('H:i').' à '.$clash->ends_at->timezone(self::tz())->format('H:i').'.');
            }
        }
    }

    public static function tz(): string
    {
        return app()->bound('tenant') ? (app('tenant')->settings['timezone'] ?? config('erp.defaults.timezone', 'UTC')) : 'UTC';
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            $a->token ??= Str::random(40);
            $a->number ??= Sequence::next($a->company_id, 'appointment', 'RDV');
            $a->status ??= 'confirmed';
            $a->notify ??= true;
        });
    }

    public function manageUrl(): string
    {
        return url('/rdv/a/'.$this->token);
    }
}
