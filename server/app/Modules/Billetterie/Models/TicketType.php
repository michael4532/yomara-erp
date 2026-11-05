<?php

namespace App\Modules\Billetterie\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Evenements\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Tarif de billet ou type de badge (Pass 1 jour, Pass VIP, Badge exposant…). */
class TicketType extends Model
{
    use Syncable;

    protected $table = 'ev_ticket_types';

    public static string $syncEntity = 'ev_ticket_types';

    public static string $viewPermission = 'billetterie.scan|billetterie.sell|billetterie.manage|evenements.manage';

    public static string $managePermission = 'billetterie.manage';

    public const KINDS = ['visitor' => 'Visiteur', 'vip' => 'VIP', 'exhibitor' => 'Exposant', 'staff' => 'Organisation', 'press' => 'Presse'];

    protected $fillable = ['event_id', 'name', 'kind', 'price', 'quantity', 'valid_from', 'valid_to', 'reentry', 'sale_start', 'sale_end', 'online', 'max_per_order', 'description', 'color', 'sort', 'active'];

    protected $attributes = ['kind' => 'visitor', 'price' => 0, 'reentry' => false, 'online' => true, 'max_per_order' => 10, 'sort' => 0, 'active' => true];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['price' => 'float', 'quantity' => 'integer', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d', 'sale_start' => 'date:Y-m-d', 'sale_end' => 'date:Y-m-d',
            'reentry' => 'boolean', 'online' => 'boolean', 'active' => 'boolean', 'max_per_order' => 'integer', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'event_id' => ['required', 'uuid', Rule::exists('ev_events', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'name' => 'required|string|max:120',
            'kind' => 'in:'.implode(',', array_keys(self::KINDS)),
            'price' => 'numeric|min:0',
            'quantity' => 'nullable|integer|min:0|max:1000000',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date',
            'reentry' => 'boolean',
            'sale_start' => 'nullable|date',
            'sale_end' => 'nullable|date',
            'online' => 'boolean',
            'max_per_order' => 'integer|min:1|max:50',
            'description' => 'nullable|string|max:1000',
            'color' => 'nullable|string|max:9',
            'sort' => 'integer|min:0|max:999',
            'active' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            unset($data['event_id']);
        }
        $event = Event::find($data['event_id'] ?? $this->event_id);
        $from = $data['valid_from'] ?? $this->valid_from?->toDateString() ?? $event?->start_date?->toDateString();
        $to = $data['valid_to'] ?? $this->valid_to?->toDateString() ?? $event?->end_date?->toDateString();
        if ($from && $to && $to < $from) {
            throw new \DomainException('La fin de validité précède son début.');
        }
        if ($creating) {
            $data['valid_from'] ??= $from;
            $data['valid_to'] ??= $to;
            if (in_array($data['kind'] ?? 'visitor', ['exhibitor', 'staff', 'press'], true)) {
                $data['reentry'] ??= true;
                $data['online'] ??= false;
            }
        }
        if (! $creating && array_key_exists('quantity', $data) && $data['quantity'] !== null && (int) $data['quantity'] < $this->sold()) {
            throw new \DomainException($this->sold().' billet(s) « '.$this->name.' » déjà émis : la quantité ne peut pas descendre en dessous.');
        }
    }

    /** Billets émis + commandes en ligne en cours de paiement (30 min). */
    public function sold(?string $exceptOrder = null): int
    {
        $n = Ticket::where('type_id', $this->id)->where('status', 'valid')->count();
        foreach (TicketOrder::where('event_id', $this->event_id)->where('status', 'pending')->where('created_at', '>=', now()->subMinutes(30))
            ->when($exceptOrder, fn ($q) => $q->where('id', '<>', $exceptOrder))->get(['items']) as $o) {
            foreach ($o->items ?? [] as $it) {
                if (($it['type_id'] ?? null) === $this->id) {
                    $n += (int) $it['quantity'];
                }
            }
        }

        return $n;
    }

    public function left(?string $exceptOrder = null): ?int
    {
        return $this->quantity === null ? null : max(0, $this->quantity - $this->sold($exceptOrder));
    }

    public function onSale(): bool
    {
        $today = now()->toDateString();

        return $this->active && (! $this->sale_start || $this->sale_start->toDateString() <= $today) && (! $this->sale_end || $this->sale_end->toDateString() >= $today);
    }

    public function beforeEntityDelete(): void
    {
        if (Ticket::where('type_id', $this->id)->exists()) {
            throw new \DomainException('Des billets de ce tarif existent : désactivez-le plutôt.');
        }
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['sold' => Ticket::where('type_id', $this->id)->where('status', 'valid')->count(),
            'checked_in' => Ticket::where('type_id', $this->id)->where('status', 'valid')->whereNotNull('checked_in_at')->count()];
    }
}
