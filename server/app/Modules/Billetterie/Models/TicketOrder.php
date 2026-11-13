<?php

namespace App\Modules\Billetterie\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Billetterie\TicketService;
use App\Modules\Evenements\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Commande de billets : au guichet (payée, billets imprimés tout de suite) ou en ligne (billets émis au paiement). */
class TicketOrder extends Model
{
    use Syncable;

    protected $table = 'ev_ticket_orders';

    public static string $syncEntity = 'ev_ticket_orders';

    public static string $viewPermission = 'billetterie.sell|billetterie.manage';

    public static string $managePermission = 'billetterie.sell|billetterie.manage';

    public static array $searchable = ['number', 'buyer_name', 'buyer_phone'];

    public static bool $systemWrite = false;

    protected $fillable = ['event_id', 'partner_id', 'buyer_name', 'buyer_phone', 'buyer_email', 'items', 'status', 'payment_method', 'buyer_company', 'buyer_job', 'answers', 'consent', 'source', 'kiosk', 'buyer_city', 'buyer_country'];

    protected $attributes = ['status' => 'pending', 'channel' => 'box_office', 'total' => 0];

    protected $hidden = ['company_id', 'deleted_at', 'token'];

    /** Billets fournis par l'appareil (guichet hors ligne) : identifiants, codes et jetons déjà imprimés. */
    public array $clientTickets = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'total' => 'float', 'paid_at' => 'datetime', 'version' => 'integer', 'answers' => 'array', 'consent' => 'boolean', 'registered_at' => 'datetime'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'event_id' => ['required', 'uuid', Rule::exists('ev_events', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $c)],
            'buyer_name' => 'required|string|max:160',
            'buyer_phone' => 'nullable|string|max:40',
            'buyer_email' => 'nullable|email|max:255',
            'items' => 'required|array|min:1|max:20',
            'items.*.type_id' => 'required|uuid',
            'items.*.quantity' => 'required|integer|min:1|max:200',
            'status' => 'in:pending,paid,cancelled',
            'payment_method' => 'nullable|in:cash,mobile_money,card,bank_transfer,cheque,other,free',
            'buyer_company' => 'nullable|string|max:160',
            'buyer_job' => 'nullable|string|max:120',
            'answers' => 'nullable|array|max:20',
            'consent' => 'nullable|boolean',
            'source' => 'nullable|string|max:60',
            'kiosk' => 'nullable|string|max:60',
            'registered_at' => 'nullable|date',
            'buyer_city' => 'nullable|string|max:120',
            'buyer_country' => 'nullable|string|max:80',
            'tickets' => 'nullable|array|max:500',
            'tickets.*.id' => 'required|uuid',
            'tickets.*.type_id' => 'required|uuid',
            'tickets.*.code' => 'nullable|string|min:6|max:20',
            'tickets.*.token' => 'nullable|string|min:24|max:64',
            'tickets.*.holder_name' => 'nullable|string|max:160',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $this->clientTickets = $data['tickets'] ?? [];
        // Heure réelle de l'inscription (une borne hors ligne synchronise plus tard), jamais dans le futur
        $at = $creating && ! empty($data['registered_at']) ? \Illuminate\Support\Carbon::parse($data['registered_at']) : null;
        unset($data['tickets'], $data['registered_at']);
        if (! $creating) {
            foreach (['event_id', 'items', 'partner_id', 'kiosk'] as $k) {
                unset($data[$k]);
            }
            $status = $data['status'] ?? $this->status;
            if ($this->status === 'cancelled' && $status !== 'cancelled') {
                throw new \DomainException('Commande annulée.');
            }
            if ($this->status === 'paid' && $status === 'pending') {
                throw new \DomainException('Commande déjà payée.');
            }

            return;
        }
        $event = Event::find($data['event_id']);
        if (in_array($event->status, ['done', 'cancelled'], true)) {
            throw new \DomainException('Billetterie fermée pour « '.$event->name.' ».');
        }
        $this->number = Sequence::next(app('tenant')->id, 'ev_ticket_order', 'BIL');
        $this->token = Str::random(40);
        $this->channel = static::$systemWrite ? 'online' : (! empty($data['kiosk']) ? 'kiosk' : 'box_office');
        $this->registered_at = $at && $at->lt(now()->addMinutes(5)) && $at->gt(now()->subDays(60)) ? $at : now();
        $items = [];
        $total = 0.0;
        // un même tarif répété sur plusieurs lignes est cumulé (sinon la limite par commande et le stock sont contournés)
        $merged = [];
        foreach ($data['items'] as $it) {
            $k = strtolower((string) $it['type_id']);
            $merged[$k] = ['type_id' => $it['type_id'], 'quantity' => ($merged[$k]['quantity'] ?? 0) + (int) $it['quantity']];
        }
        foreach ($merged as $it) {
            $t = TicketType::where('event_id', $event->id)->find($it['type_id']);
            if (! $t || ! $t->active) {
                throw new \DomainException('Tarif indisponible.');
            }
            if (static::$systemWrite && (! $t->online || ! $t->onSale())) {
                throw new \DomainException('« '.$t->name.' » n\'est pas en vente en ligne.');
            }
            $q = (int) $it['quantity'];
            if (static::$systemWrite && $q > $t->max_per_order) {
                throw new \DomainException('« '.$t->name.' » : '.$t->max_per_order.' billet(s) au plus par commande.');
            }
            $left = $t->left($this->id);
            if ($left !== null && $q > $left) {
                throw new \DomainException($left > 0 ? 'Plus que '.$left.' billet(s) « '.$t->name.' ».' : '« '.$t->name.' » : complet.');
            }
            $items[] = ['type_id' => $t->id, 'name' => $t->name, 'quantity' => $q, 'unit_price' => (float) $t->price];
            $total += $q * (float) $t->price;
        }
        if ($event->capacity) {
            $issued = Ticket::where('event_id', $event->id)->where('status', 'valid')->whereIn('kind', ['visitor', 'vip'])->count();
            $want = array_sum(array_column($items, 'quantity'));
            if ($issued + $want > $event->capacity) {
                throw new \DomainException('Jauge atteinte : '.max(0, $event->capacity - $issued).' place(s) restante(s).');
            }
        }
        $data['items'] = $items;
        $this->total = round($total, 2);
        if (! static::$systemWrite) {
            $data['status'] ??= 'paid';
            if ($this->total == 0.0) {
                $data['payment_method'] = 'free';
            }
            if ($data['status'] === 'paid' && empty($data['payment_method'])) {
                throw new \DomainException('Indiquez le moyen de paiement.');
            }
        } elseif ($this->total == 0.0) {
            $data['status'] = 'paid';
            $data['payment_method'] = 'free';
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        app(TicketService::class)->afterOrderWrite($this->fresh(), $this->clientTickets, $creating);
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une commande de billets s\'annule, elle ne se supprime pas.');
    }

    public function publicUrl(): string
    {
        return url('/billetterie/c/'.$this->token);
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['number' => $this->number, 'total' => $this->total, 'channel' => $this->channel, 'invoice_id' => $this->invoice_id,
            'paid_at' => $this->paid_at?->toIso8601String(), 'registered_at' => ($this->registered_at ?? $this->created_at)?->toIso8601String(), 'public_url' => $this->token ? $this->publicUrl() : null, 'last_error' => $this->last_error];
    }
}
