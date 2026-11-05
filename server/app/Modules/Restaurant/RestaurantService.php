<?php

namespace App\Modules\Restaurant;

use App\Models\AppNotification;
use App\Models\Company;
use App\Modules\Core\Models\Product;
use App\Modules\Restaurant\Models\Order;
use App\Modules\Restaurant\Models\OrderLine;
use App\Modules\Restaurant\Models\Station;
use App\Modules\Restaurant\Models\Table;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Collection;

class RestaurantService
{
    /** Poste qui prépare ce produit : celui de sa catégorie, sinon la première cuisine. */
    public function stationFor(Product $product): ?Station
    {
        $stations = Station::orderBy('sort')->orderBy('name')->get();
        if ($product->category_id) {
            $s = $stations->first(fn (Station $s) => in_array($product->category_id, $s->category_ids ?? [], true));
            if ($s) {
                return $s;
            }
        }

        return $stations->firstWhere('kind', 'kitchen') ?? $stations->first();
    }

    /** Tickets de caisse (non annulés) qui règlent cette commande. */
    public function bills(Order $order): Collection
    {
        return SalesDocument::where('rs_order_id', $order->id)->where('status', '!=', 'cancelled')->get();
    }

    /** Quantités déjà réglées par ligne. */
    public function paid(Order $order): array
    {
        $paid = [];
        foreach ($this->bills($order) as $b) {
            foreach ((array) ($b->rs_bill ?? []) as $lineId => $q) {
                $paid[$lineId] = ($paid[$lineId] ?? 0) + (float) $q;
            }
        }

        return $paid;
    }

    /** Montant TTC restant à encaisser. */
    public function remaining(Order $order): float
    {
        $paid = $this->paid($order);
        $left = 0;
        foreach ($order->lines()->where('status', '!=', 'cancelled')->get() as $l) {
            $q = max(0, $l->quantity - ($paid[$l->id] ?? 0));
            $left += $q * $l->unit_price * (1 + $l->tax_rate / 100);
        }

        return round($left, 2);
    }

    public function assertCancellable(Order $order, ?string $reason, bool $manager): void
    {
        if ($this->bills($order)->isNotEmpty()) {
            throw new \DomainException('Cette commande est déjà encaissée en partie : annulez d\'abord le ticket.');
        }
        $sent = $order->lines()->whereNotIn('status', ['pending', 'cancelled'])->exists();
        if ($sent && blank($reason)) {
            throw new \DomainException('Indiquez le motif de l\'annulation.');
        }
        if ($order->lines()->where('status', 'served')->exists() && ! $manager) {
            throw new \DomainException('Des plats ont déjà été servis : seul un responsable peut annuler la commande.');
        }
    }

    public function assertClosable(Order $order): void
    {
        $left = $this->remaining($order);
        if ($left > 0.009) {
            throw new \DomainException('Il reste '.number_format($left, 0, ',', ' ').' à encaisser sur cette commande.');
        }
    }

    /** Clôture automatique quand tout est réglé ; réouverture si un ticket est annulé. */
    public function refresh(Order $order): void
    {
        $hasLines = $order->lines()->where('status', '!=', 'cancelled')->exists();
        $billed = $this->bills($order)->isNotEmpty();
        $left = $this->remaining($order);
        if ($order->status === 'open' && $hasLines && $billed && $left <= 0.009) {
            $order->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        } elseif ($order->status === 'closed' && $left > 0.009) {
            $order->forceFill(['status' => 'open', 'closed_at' => null])->save();
        }
    }

    public function lineChanged(OrderLine $line, bool $creating): void
    {
        $order = Order::find($line->order_id);
        if (! $order) {
            return;
        }
        if ($line->status === 'ready' && $order->waiter_id && $order->waiter_id !== auth()->id()) {
            AppNotification::send($order->company_id, [$order->waiter_id], 'restaurant', $order->label().' : prêt à servir',
                rtrim(rtrim(number_format($line->quantity, 2, ',', ''), '0'), ',').' × '.$line->description, 'restaurant', $order->id);
        }
        if ($line->status === 'cancelled') {
            $this->refresh($order);
        }
    }

    /** Tableau de bord du restaurant sur une période. */
    public function stats(Company $company, string $from, string $to): array
    {
        $tz = $company->settings['timezone'] ?? 'UTC';
        $start = \Carbon\Carbon::parse($from, $tz)->startOfDay()->utc();
        $end = \Carbon\Carbon::parse($to, $tz)->endOfDay()->utc();
        $orders = Order::where('status', '!=', 'cancelled')->whereBetween('opened_at', [$start, $end])->get();
        $ids = $orders->pluck('id');
        $receipts = SalesDocument::whereIn('rs_order_id', $ids)->where('status', 'validated')->get();
        $revenue = (float) $receipts->sum('total');
        $covers = (int) $orders->where('kind', 'dine_in')->sum('guests');
        $lines = OrderLine::whereIn('order_id', $ids)->get();
        $valid = $lines->where('status', '!=', 'cancelled');
        $cancelled = $lines->where('status', 'cancelled')->where('sent_at', '!=', null);

        $top = $valid->groupBy('description')->map(fn ($g, $name) => ['name' => $name, 'quantity' => round($g->sum('quantity'), 2),
            'amount' => round($g->sum(fn ($l) => $l->total()), 0)])->sortByDesc('quantity')->values()->take(10);

        $stations = Station::all()->keyBy('id');
        $prep = $valid->filter(fn ($l) => $l->sent_at && $l->ready_at)->groupBy('station_id')->map(fn ($g, $sid) => [
            'station' => $stations[$sid]->name ?? 'Sans poste',
            'minutes' => round($g->avg(fn ($l) => $l->sent_at->diffInSeconds($l->ready_at)) / 60, 1),
            'count' => $g->count(),
        ])->values();

        $byOrder = $receipts->groupBy('rs_order_id')->map(fn ($g) => (float) $g->sum('total'));
        $waiters = $orders->groupBy(fn ($o) => $o->waiter_name ?: '—')->map(fn ($g, $name) => [
            'name' => $name,
            'orders' => $g->count(),
            'covers' => (int) $g->where('kind', 'dine_in')->sum('guests'),
            'revenue' => round($g->sum(fn ($o) => $byOrder[$o->id] ?? 0), 0),
        ])->sortByDesc('revenue')->values();

        $hours = $orders->groupBy(fn ($o) => (int) $o->opened_at->copy()->setTimezone($tz)->format('G'))->map->count()->sortKeys();

        return [
            'orders' => $orders->count(),
            'covers' => $covers,
            'revenue' => round($revenue, 0),
            // Ticket moyen : tables encaissées seulement (les tables en cours fausseraient la moyenne)
            'per_cover' => ($billed = (int) $orders->where('kind', 'dine_in')->whereIn('id', $byOrder->keys())->sum('guests'))
                ? round($receipts->whereIn('rs_order_id', $orders->where('kind', 'dine_in')->pluck('id'))->sum('total') / $billed, 0) : 0,
            'takeaway' => $orders->where('kind', 'takeaway')->count(),
            'open_now' => Order::where('status', 'open')->count(),
            'tables' => Table::where('active', true)->count(),
            'cancelled_lines' => $cancelled->count(),
            'cancelled_amount' => round($cancelled->sum(fn ($l) => $l->total()), 0),
            'top' => $top,
            'prep' => $prep,
            'waiters' => $waiters,
            'hours' => $hours,
        ];
    }
}
