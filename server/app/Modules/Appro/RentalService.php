<?php

namespace App\Modules\Appro;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Sequence;
use App\Models\User;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Appro\Models\Rental;
use App\Modules\Appro\Models\RentalEvent;
use App\Modules\Core\Models\Partner;
use App\Services\EntityWriter;
use Carbon\Carbon;
use Illuminate\Support\Str;

/** Locations de matériel et services externes : durée, coût, prolongations, retour, rappels, facture fournisseur. */
class RentalService
{
    public const KINDS = ['equipment' => 'Location de matériel', 'vehicle' => 'Location de véhicule / engin', 'service' => 'Prestation de service externe', 'subcontract' => 'Sous-traitance'];

    public const UNITS = ['hour' => 'heure', 'day' => 'jour', 'week' => 'semaine', 'month' => 'mois', 'fixed' => 'forfait'];

    public const STATUSES = ['planned' => 'Prévue', 'active' => 'En cours', 'returned' => 'Terminée', 'closed' => 'Facturée', 'cancelled' => 'Annulée'];

    public function __construct(private EntityWriter $writer) {}

    protected function assert(bool $ok, string $msg): void
    {
        if (! $ok) {
            throw new \DomainException($msg);
        }
    }

    /** Nombre d'unités facturées entre deux dates incluses. */
    public static function units(string $unit, Carbon $from, Carbon $to, float $qty): float
    {
        $days = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);

        return match ($unit) {
            'day' => $days,
            'week' => (float) ceil($days / 7),
            'month' => max(1, (float) ceil($from->copy()->startOfDay()->floatDiffInMonths($to->copy()->addDay()->startOfDay()) - 0.0001)),
            default => 1.0, // heure (la quantité porte les heures) et forfait
        };
    }

    public function cost(Rental $r, ?Carbon $until = null): array
    {
        $end = $until ?? ($r->returned_at ?? $r->end_date);
        $units = self::units($r->rate_unit, $r->start_date, $end, $r->quantity);
        $planned = self::units($r->rate_unit, $r->start_date, $r->end_date, $r->quantity);

        return ['units' => $units, 'amount' => round($r->rate * $units * $r->quantity, 2), 'planned_units' => $planned, 'planned_amount' => round($r->rate * $planned * $r->quantity, 2),
            'days' => max(1, $r->start_date->diffInDays($end) + 1)];
    }

    public function save(Company $c, ?Rental $r, array $d, User $u): Rental
    {
        $r ??= new Rental(['company_id' => $c->id, 'status' => 'planned', 'responsible_id' => $u->id]);
        $this->assert(! in_array($r->status, ['closed', 'cancelled'], true), 'Location clôturée.');
        foreach (['kind', 'title', 'partner_id', 'start_date', 'end_date', 'rate', 'rate_unit', 'quantity', 'deposit', 'site', 'project_id', 'reference', 'notes', 'responsible_id'] as $k) {
            if (array_key_exists($k, $d)) {
                $r->{$k} = is_string($d[$k]) ? (trim($d[$k]) ?: null) : $d[$k];
            }
        }
        $this->assert((bool) $r->title && $r->start_date && $r->end_date, 'Indiquez l\'objet, la date de début et la date de fin.');
        $this->assert(Carbon::parse($r->end_date)->gte(Carbon::parse($r->start_date)), 'La date de fin précède la date de début.');
        $r->kind = array_key_exists($r->kind, self::KINDS) ? $r->kind : 'equipment';
        $r->rate_unit = array_key_exists($r->rate_unit, self::UNITS) ? $r->rate_unit : 'day';
        $r->quantity = $r->quantity === null || $r->quantity === '' ? 1.0 : max(0.001, (float) $r->quantity);
        $r->rate = max(0, (float) $r->rate);
        $r->deposit = max(0, (float) $r->deposit);
        if ($r->status === 'planned' && Carbon::parse($r->start_date)->lte(now()->startOfDay())) {
            $r->status = 'active';
        }
        $r->number ??= Sequence::next($c->id, 'appro.rental', 'LOC');
        $creating = ! $r->exists;
        $r->save();
        if ($creating) {
            $this->event($r, $u->id, 'created', null);
        }

        return $r->fresh();
    }

    protected function event(Rental $r, ?int $userId, string $type, ?string $body, ?array $data = null): void
    {
        RentalEvent::create(['company_id' => $r->company_id, 'rental_id' => $r->id, 'user_id' => $userId, 'type' => $type, 'body' => $body, 'data' => $data]);
    }

    public function extend(Company $c, Rental $r, string $newEnd, ?string $note, User $u): Rental
    {
        $this->assert(in_array($r->status, ['planned', 'active'], true), 'Seule une location en cours se prolonge.');
        $new = Carbon::parse($newEnd);
        $this->assert($new->gt($r->end_date), 'La nouvelle date doit être après la fin prévue ('.$r->end_date->format('d/m/Y').').');
        $old = $r->end_date->toDateString();
        $r->forceFill(['end_date' => $new->toDateString(), 'reminded_on' => null])->save();
        $this->event($r, $u->id, 'extend', $note, ['from' => $old, 'to' => $new->toDateString(), 'amount' => $this->cost($r)['planned_amount']]);

        return $r->fresh();
    }

    public function returnBack(Company $c, Rental $r, ?string $date, bool $depositBack, ?string $note, User $u): Rental
    {
        $this->assert(in_array($r->status, ['planned', 'active'], true), 'Location déjà terminée.');
        $d = $date ? Carbon::parse($date) : now();
        $this->assert($d->gte($r->start_date), 'Date de retour avant le début de la location.');
        $r->forceFill(['returned_at' => $d->toDateString(), 'status' => 'returned', 'deposit_returned' => $depositBack])->save();
        $cost = $this->cost($r);
        $this->event($r, $u->id, 'return', $note, ['date' => $d->toDateString(), 'amount' => $cost['amount'], 'units' => $cost['units'], 'deposit_returned' => $depositBack]);

        return $r->fresh();
    }

    /** Facture fournisseur (brouillon) du coût réel. */
    public function invoice(Company $c, Rental $r, User $u): Rental
    {
        $this->assert($r->status === 'returned', 'Terminez d\'abord la location (retour du matériel ou fin de la prestation).');
        $this->assert((bool) $r->partner_id, 'Choisissez le fournisseur.');
        $cost = $this->cost($r);
        $label = trim($r->title.' — du '.$r->start_date->format('d/m/Y').' au '.($r->returned_at ?? $r->end_date)->format('d/m/Y'));
        $res = $this->writer->upsert(PurchaseDocument::class, $id = (string) Str::uuid(), [
            'type' => 'supplier_invoice', 'partner_id' => $r->partner_id, 'date' => now()->toDateString(), 'reference' => $r->reference ?: $r->number, 'project_id' => $r->project_id,
            'notes' => self::KINDS[$r->kind].' '.$r->number.($r->site ? ' — '.$r->site : ''),
            'lines' => [['id' => (string) Str::uuid(), 'description' => Str::limit($label, 250, ''), 'quantity' => round($cost['units'] * $r->quantity, 3), 'unit' => self::UNITS[$r->rate_unit],
                'unit_price' => $r->rate, 'tax_rate' => (float) ($c->settings['tax_rate'] ?? 18), 'discount_percent' => 0,
                'account_code' => \App\Modules\Compta\Chart::code($c, in_array($r->kind, ['equipment', 'vehicle'], true) ? '622' : ($r->kind === 'subcontract' ? '637' : '632'))]],
        ]);
        $this->assert($res['status'] === EntityWriter::APPLIED, 'Facture refusée : '.($res['message'] ?? json_encode($res['errors'] ?? $res)));
        $r->forceFill(['invoice_id' => $id, 'status' => 'closed'])->save();
        $this->event($r, $u->id, 'invoice', null, ['amount' => $cost['amount']]);

        return $r->fresh();
    }

    public function cancel(Company $c, Rental $r, ?string $note, User $u): Rental
    {
        $this->assert($r->status === 'planned', 'Seule une location prévue s\'annule (sinon, enregistrez le retour).');
        $r->forceFill(['status' => 'cancelled'])->save();
        $this->event($r, $u->id, 'cancel', $note);

        return $r->fresh();
    }

    /** Démarrage des locations du jour, rappel 2 jours avant la fin, alerte de dépassement. */
    public function daily(Company $c): array
    {
        $out = ['started' => 0, 'reminders' => 0, 'overdue' => 0];
        $today = now()->startOfDay();
        foreach (Rental::where('status', 'planned')->where('start_date', '<=', $today->toDateString())->get() as $r) {
            $r->forceFill(['status' => 'active'])->save();
            $out['started']++;
        }
        $buyers = User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($x) => $x->hasPermission('appro.manage'))->pluck('id');
        foreach (Rental::where('status', 'active')->get() as $r) {
            $to = collect([$r->responsible_id])->merge($buyers)->filter()->unique();
            $name = $r->partner_id ? Partner::find($r->partner_id)?->name : null;
            if ($r->end_date->lt($today) && (! $r->reminded_on || $r->reminded_on->lt($today))) {
                AppNotification::send($c->id, $to, 'appro', 'Location dépassée : à rendre ou à prolonger', $r->number.' — '.$r->title.($name ? ' ('.$name.')' : '').' : fin prévue le '.$r->end_date->format('d/m/Y').'.', 'appro', (string) $r->id);
                $r->forceFill(['reminded_on' => $today->toDateString()])->save();
                $out['overdue']++;
            } elseif ($r->end_date->gte($today) && $r->end_date->lte($today->copy()->addDays(2)) && ! $r->reminded_on) {
                AppNotification::send($c->id, $to, 'appro', 'Fin de location dans '.$today->diffInDays($r->end_date).' jour(s)', $r->number.' — '.$r->title.($name ? ' ('.$name.')' : '').' : prévoir le retour ou la prolongation.', 'appro', (string) $r->id);
                $r->forceFill(['reminded_on' => $today->toDateString()])->save();
                $out['reminders']++;
            }
        }

        return $out;
    }

    public function toApi(Rental $r, bool $full = false): array
    {
        $cost = $this->cost($r, in_array($r->status, ['planned', 'active'], true) ? max($r->end_date, now()->startOfDay()) : null);
        $out = $r->only(['id', 'number', 'kind', 'title', 'partner_id', 'rate', 'rate_unit', 'quantity', 'deposit', 'deposit_returned', 'site', 'project_id', 'reference', 'status', 'invoice_id', 'responsible_id', 'notes']) + [
            'kind_label' => self::KINDS[$r->kind] ?? $r->kind, 'unit_label' => self::UNITS[$r->rate_unit] ?? $r->rate_unit, 'status_label' => self::STATUSES[$r->status] ?? $r->status,
            'partner' => $r->partner_id ? Partner::withTrashed()->find($r->partner_id)?->name : null, 'responsible' => $r->responsible_id ? User::find($r->responsible_id)?->name : null,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(), 'returned_at' => $r->returned_at?->toDateString(),
            'days' => $cost['days'], 'units' => $cost['units'], 'amount' => $cost['amount'], 'planned_amount' => $cost['planned_amount'],
            'overdue' => $r->status === 'active' && $r->end_date->lt(now()->startOfDay()), 'overdue_days' => $r->status === 'active' && $r->end_date->lt(now()->startOfDay()) ? $r->end_date->diffInDays(now()->startOfDay()) : 0,
            'days_left' => in_array($r->status, ['planned', 'active'], true) ? (int) now()->startOfDay()->diffInDays($r->end_date, false) : null,
            'invoice_number' => $r->invoice_id ? PurchaseDocument::find($r->invoice_id)?->number : null,
        ];
        if ($full) {
            $ev = RentalEvent::where('rental_id', $r->id)->orderByDesc('id')->get();
            $names = User::whereIn('id', $ev->pluck('user_id')->filter())->pluck('name', 'id');
            $out['events'] = $ev->map(fn ($e) => ['type' => $e->type, 'body' => $e->body, 'data' => $e->data, 'user' => $names[$e->user_id] ?? null, 'at' => $e->created_at])->values();
        }

        return $out;
    }
}
