<?php

namespace App\Modules\Appro;

use App\Models\Company;
use App\Modules\Core\Models\Partner;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Historique et évaluation des fournisseurs dans la durée : achats, prix, délais, conformité des livraisons, paiement. */
class SupplierHistory
{
    protected function docs(Company $c, ?string $partnerId = null)
    {
        return DB::table('trade_documents')->where('company_id', $c->id)->where('direction', 'purchase')->where('status', 'validated')->whereNull('deleted_at')
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId));
    }

    /** Délai de livraison et conformité à partir des bons de commande et des réceptions. */
    protected function delivery(Company $c, ?string $partnerId, ?string $since = null): array
    {
        $orders = (clone $this->docs($c, $partnerId))->where('type', 'purchase_order')->when($since, fn ($q) => $q->where('date', '>=', $since))
            ->get(['id', 'partner_id', 'date', 'due_date', 'receipt_status']);
        if ($orders->isEmpty()) {
            return [];
        }
        $receipts = DB::table('purchase_receipts')->where('company_id', $c->id)->whereNull('deleted_at')->where('status', 'done')->whereIn('order_id', $orders->pluck('id'))
            ->get(['order_id', 'date'])->groupBy('order_id');
        $out = [];
        foreach ($orders->groupBy('partner_id') as $pid => $g) {
            $lead = [];
            $onTime = 0;
            $timed = 0;
            foreach ($g as $o) {
                $first = $receipts->get($o->id)?->min('date');
                if (! $first) {
                    continue;
                }
                $lead[] = Carbon::parse($o->date)->diffInDays(Carbon::parse($first));
                if ($o->due_date) {
                    $timed++;
                    $onTime += Carbon::parse($first)->lte(Carbon::parse($o->due_date)) ? 1 : 0;
                }
            }
            $received = $g->whereIn('receipt_status', ['received', 'partial']);
            $out[$pid] = ['orders' => $g->count(), 'lead_days' => $lead ? round(array_sum($lead) / count($lead), 1) : null, 'on_time' => $timed ? round($onTime * 100 / $timed) : null,
                'complete' => $received->count() ? round($received->where('receipt_status', 'received')->count() * 100 / $received->count()) : null, 'partial' => $g->where('receipt_status', 'partial')->count()];
        }

        return $out;
    }

    /** Tableau d'évaluation de tous les fournisseurs (12 derniers mois par défaut). */
    public function scorecard(Company $c, int $months = 12): array
    {
        $since = now()->subMonths($months)->toDateString();
        $prevSince = now()->subMonths($months * 2)->toDateString();
        $spend = (clone $this->docs($c))->where('type', 'supplier_invoice')->where('date', '>=', $since)->whereNotNull('partner_id')
            ->groupBy('partner_id')->selectRaw('partner_id, SUM(total * COALESCE(exchange_rate, 1)) s, COUNT(*) n, MAX(date) last')->get()->keyBy('partner_id');
        $prev = (clone $this->docs($c))->where('type', 'supplier_invoice')->whereBetween('date', [$prevSince, $since])->whereNotNull('partner_id')
            ->groupBy('partner_id')->selectRaw('partner_id, SUM(total * COALESCE(exchange_rate, 1)) s')->pluck('s', 'partner_id');
        $del = $this->delivery($c, null, $since);
        $ids = collect(array_keys($del))->merge($spend->keys())->unique();
        $partners = Partner::whereIn('id', $ids)->get(['id', 'name', 'city', 'country', 'aux_code'])->keyBy('id');
        $rows = [];
        foreach ($ids as $pid) {
            $p = $partners[$pid] ?? null;
            if (! $p) {
                continue;
            }
            $d = $del[$pid] ?? [];
            $score = null;
            $parts = array_filter([$d['on_time'] ?? null, $d['complete'] ?? null], fn ($x) => $x !== null);
            if ($parts) {
                $score = round(array_sum($parts) / count($parts) / 20, 1); // note sur 5
            }
            $rows[] = ['partner_id' => $pid, 'name' => $p->name, 'city' => $p->city, 'country' => $p->country, 'spend' => round((float) ($spend[$pid]->s ?? 0), 2), 'invoices' => (int) ($spend[$pid]->n ?? 0),
                'spend_prev' => round((float) ($prev[$pid] ?? 0), 2), 'last' => $spend[$pid]->last ?? null, 'orders' => $d['orders'] ?? 0, 'lead_days' => $d['lead_days'] ?? null,
                'on_time' => $d['on_time'] ?? null, 'complete' => $d['complete'] ?? null, 'score' => $score];
        }
        usort($rows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        return $rows;
    }

    /** Fiche historique d'un fournisseur. */
    public function history(Company $c, string $partnerId): array
    {
        $p = Partner::withTrashed()->findOrFail($partnerId);
        $inv = (clone $this->docs($c, $partnerId))->where('type', 'supplier_invoice')->get(['id', 'date', 'total', 'exchange_rate', 'currency', 'payment_status', 'amount_paid']);
        $years = $inv->groupBy(fn ($d) => substr((string) $d->date, 0, 4))->map(fn ($g, $y) => ['year' => (int) $y, 'invoices' => $g->count(), 'total' => round($g->sum(fn ($d) => $d->total * ($d->exchange_rate ?: 1)), 2)])
            ->sortBy('year')->values();
        $start = now()->startOfMonth()->subMonths(23);
        $months = [];
        $byMonth = $inv->groupBy(fn ($d) => substr((string) $d->date, 0, 7));
        for ($m = $start->copy(); $m->lte(now()); $m->addMonth()) {
            $months[] = ['month' => $m->format('Y-m'), 'total' => round($byMonth->get($m->format('Y-m'), collect())->sum(fn ($d) => $d->total * ($d->exchange_rate ?: 1)), 2)];
        }
        // Prix par article dans le temps
        $lines = DB::table('trade_document_lines as l')->join('trade_documents as d', 'd.id', '=', 'l.document_id')
            ->where('d.company_id', $c->id)->where('d.partner_id', $partnerId)->where('d.direction', 'purchase')->whereIn('d.type', ['purchase_order', 'supplier_invoice'])->where('d.status', 'validated')->whereNull('d.deleted_at')
            ->whereNotNull('l.product_id')->orderBy('d.date')->get(['l.product_id', 'l.description', 'l.quantity', 'l.unit_price', 'd.exchange_rate', 'd.date', 'd.type']);
        $names = DB::table('products')->whereIn('id', $lines->pluck('product_id')->unique())->pluck('name', 'id');
        $products = [];
        foreach ($lines->groupBy('product_id') as $pid => $g) {
            $g = $g->where('type', $g->contains('type', 'purchase_order') ? 'purchase_order' : 'supplier_invoice')->values();
            $prices = $g->map(fn ($x) => round((float) $x->unit_price * (float) ($x->exchange_rate ?: 1), 2));
            $first = $prices->first();
            $last = $prices->last();
            $products[] = ['product_id' => $pid, 'name' => $names[$pid] ?? $g->first()->description, 'purchases' => $g->count(), 'quantity' => round($g->sum('quantity'), 3),
                'first_price' => $first, 'last_price' => $last, 'min' => $prices->min(), 'max' => $prices->max(), 'first_date' => $g->first()->date, 'last_date' => $g->last()->date,
                'change' => $first > 0 ? round(($last - $first) * 100 / $first, 1) : null,
                'points' => $g->map(fn ($x) => ['date' => $x->date, 'price' => round((float) $x->unit_price * (float) ($x->exchange_rate ?: 1), 2)])->values()];
        }
        usort($products, fn ($a, $b) => $b['purchases'] <=> $a['purchases']);
        // Paiement : délai moyen entre la facture et son règlement complet
        $pay = DB::table('payments')->whereIn('document_id', $inv->where('payment_status', 'paid')->pluck('id'))->whereNull('deleted_at')->groupBy('document_id')->selectRaw('document_id, MAX(date) d')->pluck('d', 'document_id');
        $payDays = $inv->where('payment_status', 'paid')->filter(fn ($d) => isset($pay[$d->id]))->map(fn ($d) => Carbon::parse($d->date)->diffInDays(Carbon::parse($pay[$d->id])));
        $del = $this->delivery($c, $partnerId)[$partnerId] ?? ['orders' => 0, 'lead_days' => null, 'on_time' => null, 'complete' => null, 'partial' => 0];
        $parts = array_filter([$del['on_time'], $del['complete']], fn ($x) => $x !== null);

        return [
            'partner' => ['id' => $p->id, 'name' => $p->name, 'email' => $p->email, 'phone' => $p->phone, 'city' => $p->city, 'country' => $p->country, 'aux_code' => $p->aux_code, 'since' => $inv->min('date') ?? $lines->min('date')],
            'kpis' => ['total' => round($inv->sum(fn ($d) => $d->total * ($d->exchange_rate ?: 1)), 2), 'invoices' => $inv->count(), 'unpaid' => round($inv->sum(fn ($d) => ($d->total - $d->amount_paid) * ($d->exchange_rate ?: 1)), 2),
                'pay_days' => $payDays->count() ? round($payDays->avg()) : null, 'score' => $parts ? round(array_sum($parts) / count($parts) / 20, 1) : null] + $del,
            'years' => $years, 'months' => $months, 'products' => array_slice($products, 0, 50),
            'imports' => DB::table('ap_imports')->where('company_id', $c->id)->where('partner_id', $partnerId)->orderByDesc('id')->limit(20)->get(['id', 'number', 'title', 'status', 'eta', 'arrived_at']),
            'rentals' => DB::table('ap_rentals')->where('company_id', $c->id)->where('partner_id', $partnerId)->orderByDesc('id')->limit(20)->get(['id', 'number', 'title', 'status', 'start_date', 'end_date']),
            'comparisons' => DB::table('ap_comparisons')->where('company_id', $c->id)->where('offers', 'like', '%'.$partnerId.'%')->orderByDesc('id')->limit(20)->get(['id', 'number', 'title', 'status', 'awarded', 'offers'])
                ->map(fn ($x) => ['id' => $x->id, 'number' => $x->number, 'title' => $x->title, 'status' => $x->status,
                    'won' => $x->awarded && (collect(json_decode((string) $x->offers, true))->firstWhere('key', $x->awarded)['partner_id'] ?? null) === $partnerId]),
        ];
    }
}
