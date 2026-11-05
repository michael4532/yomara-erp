<?php

namespace App\Modules\Billetterie\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Billetterie\TicketService;
use App\Modules\Evenements\Models\Booking;
use App\Modules\Evenements\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Billet ou badge nominatif avec QR code. */
class Ticket extends Model
{
    use Syncable;

    protected $table = 'ev_tickets';

    public static string $syncEntity = 'ev_tickets';

    public static string $viewPermission = 'billetterie.scan|billetterie.sell|billetterie.manage|evenements.manage';

    public static string $managePermission = 'billetterie.manage';

    public static array $searchable = ['code', 'holder_name', 'company_name'];

    protected $fillable = ['event_id', 'type_id', 'kind', 'holder_name', 'company_name', 'holder_phone', 'holder_email', 'booking_id', 'status', 'notes'];

    protected $attributes = ['kind' => 'visitor', 'status' => 'valid', 'price' => 0, 'checkins_count' => 0, 'reentry' => false];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['price' => 'float', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d', 'reentry' => 'boolean', 'checked_in_at' => 'datetime',
            'last_checkin_at' => 'datetime', 'checkins_count' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'event_id' => ['required', 'uuid', Rule::exists('ev_events', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'type_id' => ['nullable', 'uuid', Rule::exists('ev_ticket_types', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'kind' => 'in:'.implode(',', array_keys(TicketType::KINDS)),
            'holder_name' => 'nullable|string|max:160',
            'company_name' => 'nullable|string|max:160',
            'holder_phone' => 'nullable|string|max:40',
            'holder_email' => 'nullable|email|max:255',
            'booking_id' => ['nullable', 'uuid', Rule::exists('ev_bookings', 'id')->where('company_id', $c)],
            'status' => 'in:valid,cancelled',
            'notes' => 'nullable|string|max:255',
            'code' => 'nullable|string|min:6|max:20',
            'token' => 'nullable|string|min:24|max:64',
        ];
    }

    public static function newCode(): string
    {
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $s = '';
            for ($i = 0; $i < 8; $i++) {
                $s .= $alpha[random_int(0, 31)];
            }
            $code = substr($s, 0, 4).'-'.substr($s, 4);
        } while (static::withoutGlobalScopes()->where('company_id', app('tenant')->id)->where('code', $code)->exists());

        return $code;
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            unset($data['event_id'], $data['type_id'], $data['booking_id']);
            unset($data['code'], $data['token']);
        } else {
            $code = strtoupper(trim((string) ($data['code'] ?? '')));
            if ($code !== '' && static::withoutGlobalScopes()->where('company_id', app('tenant')->id)->where('code', $code)->exists()) {
                throw new \DomainException('Code de billet déjà utilisé.');
            }
            $this->code = $code ?: static::newCode();
            $token = (string) ($data['token'] ?? '');
            if ($token !== '' && static::withoutGlobalScopes()->where('token', $token)->exists()) {
                throw new \DomainException('Jeton de billet déjà utilisé.');
            }
            $this->token = $token ?: Str::random(32);
            $event = Event::find($data['event_id']);
            $type = ! empty($data['type_id']) ? TicketType::find($data['type_id']) : null;
            if ($type && $type->event_id !== $event->id) {
                throw new \DomainException('Ce tarif appartient à un autre événement.');
            }
            if ($type && ! TicketService::$issuing && $type->quantity !== null && $type->left() < 1) {
                throw new \DomainException('« '.$type->name.' » : complet.');
            }
            $data['kind'] = $type?->kind ?? ($data['kind'] ?? 'visitor');
            $this->valid_from = $type?->valid_from ?? $event->start_date;
            $this->valid_to = $type?->valid_to ?? $event->end_date;
            $this->reentry = $type ? $type->reentry : in_array($data['kind'], ['exhibitor', 'staff', 'press'], true);
            if (! empty($data['booking_id'])) {
                $b = Booking::find($data['booking_id']);
                if (! $b || $b->event_id !== $event->id) {
                    throw new \DomainException('Réservation d\'un autre événement.');
                }
                $data['company_name'] = ($data['company_name'] ?? null) ?: ($b->exhibitor_name ?: $b->partner_name);
            }
        }
    }

    public function publicUrl(): string
    {
        return url('/billet/'.$this->token);
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['code' => $this->code, 'token' => $this->token, 'price' => $this->price, 'order_id' => $this->order_id,
            'valid_from' => $this->valid_from?->toDateString(), 'valid_to' => $this->valid_to?->toDateString(), 'reentry' => $this->reentry,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(), 'last_checkin_at' => $this->last_checkin_at?->toIso8601String(), 'checkins_count' => $this->checkins_count];
    }
}
