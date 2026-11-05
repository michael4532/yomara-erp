<?php

namespace App\Modules\Evenements\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Core\Models\Partner;
use App\Modules\Evenements\EventService;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Réservation d'un exposant : emplacements + offres. Option (avec date limite) → confirmée
 * (acompte facturé) → solde facturé avant l'événement. Annulée ou expirée : les espaces sont libérés.
 */
class Booking extends Model
{
    use Syncable;

    protected $table = 'ev_bookings';

    public static string $syncEntity = 'ev_bookings';

    public static string $viewPermission = 'evenements.view|evenements.sell|evenements.manage';

    public static string $managePermission = 'evenements.sell|evenements.manage';

    public static array $searchable = ['number', 'partner_name', 'exhibitor_name'];

    public const ACTIVE = ['option', 'confirmed'];

    public const STATUSES = ['option' => 'Option', 'confirmed' => 'Confirmée', 'cancelled' => 'Annulée', 'expired' => 'Option expirée'];

    protected $fillable = ['event_id', 'partner_id', 'exhibitor_name', 'contact_name', 'contact_phone', 'contact_email', 'status', 'option_until',
        'space_ids', 'items', 'discount_percent', 'notes', 'cancel_reason', 'checked_in_at'];

    protected $attributes = ['status' => 'option', 'discount_percent' => 0, 'source' => 'manual'];

    protected $hidden = ['company_id', 'deleted_at', 'public_token'];

    /** Écriture interne (page publique, tâches) : prix des offres imposés. */
    public static bool $systemWrite = false;

    protected function casts(): array
    {
        return ['option_until' => 'date:Y-m-d', 'space_ids' => 'array', 'items' => 'array', 'discount_percent' => 'float',
            'confirmed_at' => 'datetime', 'checked_in_at' => 'datetime', 'version' => 'integer', 'profile' => 'array', 'profile_published' => 'boolean'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'event_id' => ['required', 'uuid', Rule::exists('ev_events', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')->where('company_id', $c)],
            'exhibitor_name' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:40',
            'contact_email' => 'nullable|email|max:255',
            'status' => 'in:option,confirmed,cancelled,expired',
            'option_until' => 'nullable|date',
            'space_ids' => 'nullable|array|max:200',
            'space_ids.*' => 'uuid',
            'items' => 'nullable|array|max:100',
            'items.*.package_id' => 'required|uuid',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'discount_percent' => 'numeric|min:0|max:100',
            'notes' => 'nullable|string|max:3000',
            'cancel_reason' => 'nullable|string|max:255',
            'checked_in_at' => 'nullable|date',
        ];
    }

    public static function activeWithSpace(string $eventId, string $spaceId, ?string $except = null): ?self
    {
        return static::where('event_id', $eventId)->whereIn('status', self::ACTIVE)->when($except, fn ($q) => $q->where('id', '<>', $except))
            ->get()->first(fn ($b) => in_array($spaceId, $b->space_ids ?? [], true));
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            unset($data['event_id']);
        }
        $event = Event::find($data['event_id'] ?? $this->event_id);
        $old = $this->status;
        $status = $data['status'] ?? ($creating ? 'option' : $this->status);
        $commercial = array_intersect(array_keys($data), ['space_ids', 'items', 'discount_percent', 'partner_id']);

        // Transitions
        if (! $creating && $old !== $status) {
            $allowed = ['option' => ['confirmed', 'cancelled', 'expired'], 'confirmed' => ['cancelled'], 'expired' => ['option', 'confirmed', 'cancelled'], 'cancelled' => []][$old] ?? [];
            if (! in_array($status, $allowed, true)) {
                throw new \DomainException('Une réservation '.mb_strtolower(self::STATUSES[$old]).' ne peut pas passer à « '.self::STATUSES[$status].' ».');
            }
        }
        $reactivating = in_array($status, self::ACTIVE, true) && ($creating || ! in_array($old, self::ACTIVE, true));
        if (($commercial || $reactivating) && in_array($event?->status, ['closed', 'done', 'cancelled'], true) && in_array($status, self::ACTIVE, true)) {
            throw new \DomainException('Les ventes de « '.$event->name.' » sont closes.');
        }
        if ($commercial && ! $creating && $this->balance_invoice_id && in_array($status, self::ACTIVE, true)) {
            $changed = (isset($data['space_ids']) && array_values($data['space_ids']) !== array_values($this->space_ids ?? []))
                || (isset($data['items']) && $this->normItems($data['items'], $event, false) !== ($this->items ?? []))
                || (isset($data['discount_percent']) && (float) $data['discount_percent'] !== (float) $this->discount_percent)
                || (isset($data['partner_id']) && $data['partner_id'] !== $this->partner_id);
            if ($changed) {
                throw new \DomainException('Réservation entièrement facturée : les emplacements, offres et remises ne changent plus (faites un avoir et une nouvelle réservation).');
            }
        }
        if ($commercial && ! $creating && $this->deposit_invoice_id && isset($data['partner_id']) && $data['partner_id'] !== $this->partner_id) {
            throw new \DomainException('Un acompte a été facturé à ce client : il ne peut plus changer.');
        }

        // Emplacements : appartenance, blocage, double réservation
        if (isset($data['space_ids']) || $reactivating) {
            $ids = array_values(array_unique($data['space_ids'] ?? $this->space_ids ?? []));
            $spaces = Space::where('event_id', $event->id)->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ids as $sid) {
                $s = $spaces->get($sid);
                if (! $s) {
                    throw new \DomainException('Emplacement inconnu pour cet événement.');
                }
                if (in_array($status, self::ACTIVE, true)) {
                    if ($s->blocked && (isset($data['space_ids']) && ! in_array($sid, $this->space_ids ?? [], true) || $reactivating)) {
                        throw new \DomainException('L\'emplacement '.$s->code.' n\'est pas à la vente.');
                    }
                    if ($other = self::activeWithSpace($event->id, $sid, $this->id)) {
                        throw new \DomainException('L\'emplacement '.$s->code.' est déjà réservé ('.$other->number.' — '.($other->exhibitor_name ?: $other->partner_name).').');
                    }
                }
            }
            $data['space_ids'] = $ids;
        }

        // Offres : appartenance, prix, quantités disponibles
        if (isset($data['items']) || $reactivating) {
            $data['items'] = $this->normItems($data['items'] ?? $this->items ?? [], $event, $status !== 'cancelled');
        }
        if (in_array($status, self::ACTIVE, true) && empty($data['space_ids'] ?? $this->space_ids) && empty($data['items'] ?? $this->items)) {
            throw new \DomainException('Choisissez au moins un emplacement ou une offre.');
        }

        if (isset($data['partner_id']) || $creating) {
            $p = Partner::find($data['partner_id'] ?? $this->partner_id);
            $this->partner_name = $p?->name;
            $data['exhibitor_name'] = ($data['exhibitor_name'] ?? null) ?: ($this->exhibitor_name ?: $p?->name);
            $data['contact_phone'] = ($data['contact_phone'] ?? null) ?: ($this->contact_phone ?: $p?->phone);
            $data['contact_email'] = ($data['contact_email'] ?? null) ?: ($this->contact_email ?: $p?->email);
        }
        if ($creating) {
            $this->number = Sequence::next(app('tenant')->id, 'ev_booking', 'RES', (int) now()->format('Y'));
            $this->public_token = \Illuminate\Support\Str::random(40);
            if (! static::$systemWrite) {
                $this->source = 'manual';
            }
        }
        if ($status === 'option' && (empty($data['option_until']) && (! $this->option_until || $reactivating))) {
            $data['option_until'] = now()->addDays($event->option_days ?: 7)->toDateString();
        }
        if ($status === 'confirmed' && $old !== 'confirmed') {
            $this->confirmed_at = now();
        }
        if ($status === 'cancelled' && $old !== 'cancelled' && ! $creating) {
            app(EventService::class)->assertCancellable($this);
        }
    }

    /** Lignes d'offres nettoyées (prix des offres sauf saisie d'un vendeur), quantités disponibles contrôlées. */
    public function normItems(array $items, ?Event $event, bool $checkStock): array
    {
        $out = [];
        $byPkg = [];
        foreach ($items as $it) {
            $byPkg[$it['package_id']] = ($byPkg[$it['package_id']] ?? 0) + (int) $it['quantity'];
        }
        $pkgs = Package::where('event_id', $event?->id)->whereIn('id', array_keys($byPkg))->get()->keyBy('id');
        foreach ($items as $it) {
            $p = $pkgs->get($it['package_id']);
            if (! $p) {
                throw new \DomainException('Offre inconnue pour cet événement.');
            }
            $old = collect($this->items ?? [])->firstWhere('package_id', $p->id);
            $price = (static::$systemWrite || ! isset($it['unit_price'])) ? ($old['unit_price'] ?? $p->price) : round((float) $it['unit_price'], 2);
            $out[] = ['package_id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'quantity' => (int) $it['quantity'], 'unit_price' => (float) $price];
        }
        if ($checkStock) {
            foreach ($byPkg as $pid => $q) {
                $p = $pkgs->get($pid);
                $mine = (int) collect($this->items ?? [])->where('package_id', $pid)->sum('quantity');
                if ($q > $mine && ! $p->active) {
                    throw new \DomainException('L\'offre « '.$p->name.' » n\'est plus proposée.');
                }
                if ($p->quantity !== null && $q > $mine) {
                    $left = $p->quantity - $p->sold($this->id ?: null);
                    if ($q > $left) {
                        throw new \DomainException($left > 0 ? 'Plus que '.$left.' « '.$p->name.' » disponible(s).' : '« '.$p->name.' » : complet.');
                    }
                }
            }
        }

        return $out;
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        app(EventService::class)->afterBookingWrite($this, $creating);
    }

    public function beforeEntityDelete(): void
    {
        if ($this->deposit_invoice_id || $this->balance_invoice_id) {
            throw new \DomainException('Cette réservation a été facturée : annulez-la plutôt.');
        }
        if ($this->status === 'confirmed') {
            throw new \DomainException('Réservation confirmée : annulez-la plutôt.');
        }
    }

    /** Montants HT / TTC (devise de l'événement). */
    public function amounts(?Event $event = null): array
    {
        $event ??= $this->event;
        $spaces = Space::whereIn('id', $this->space_ids ?? [])->get();
        $sub = (float) $spaces->sum('price') + collect($this->items ?? [])->sum(fn ($i) => $i['quantity'] * $i['unit_price']);
        $ht = round($sub * (1 - (float) $this->discount_percent / 100), 2);
        $tax = round($ht * (float) ($event?->tax_rate ?? 0) / 100, 2);

        return ['subtotal' => round($sub, 2), 'total_ht' => $ht, 'tax' => $tax, 'total' => round($ht + $tax, 2), 'area' => round((float) $spaces->sum('area'), 2)];
    }

    public function invoices(): array
    {
        $ids = array_filter([$this->deposit_invoice_id, $this->balance_invoice_id]);
        if (! $ids) {
            return [];
        }
        $docs = SalesDocument::whereIn('id', $ids)->get()->keyBy('id');
        $out = [];
        foreach (['deposit' => $this->deposit_invoice_id, 'balance' => $this->balance_invoice_id] as $kind => $id) {
            if ($id && ($d = $docs->get($id))) {
                $out[] = ['kind' => $kind, 'id' => $d->id, 'number' => $d->number, 'status' => $d->status, 'total' => (float) $d->total,
                    'amount_paid' => (float) $d->amount_paid, 'payment_status' => $d->payment_status, 'date' => $d->date?->toDateString()];
            }
        }

        return $out;
    }

    public function toSyncArray(): array
    {
        $inv = $this->invoices();
        $live = array_filter($inv, fn ($i) => $i['status'] !== 'cancelled');
        $a = $this->amounts();
        $paid = array_sum(array_column($live, 'amount_paid'));

        return $this->toArray() + $a + [
            'number' => $this->number, 'partner_name' => $this->partner_name, 'source' => $this->source,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(), 'last_error' => $this->last_error,
            'deposit_invoice_id' => $this->deposit_invoice_id, 'balance_invoice_id' => $this->balance_invoice_id,
            'invoices' => array_values($inv), 'invoiced' => round(array_sum(array_column($live, 'total')), 2), 'paid' => round($paid, 2),
            'payment_status' => $this->balance_invoice_id && $paid + 0.009 >= $a['total'] ? 'paid' : ($paid > 0 ? 'partial' : ($live ? 'unpaid' : 'none')),
            // Espace exposant / sponsor : lien à transmettre pour présenter son entreprise
            'space_url' => $this->public_token && ($ev = Event::find($this->event_id)) && $ev->public_token ? url('/salon/'.$ev->public_token.'/r/'.$this->public_token.'/espace') : null,
            'logo_url' => app(\App\Modules\Evenements\EventService::class)->logoUrl($this),
        ];
    }
}
