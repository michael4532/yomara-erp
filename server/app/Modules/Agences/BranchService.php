<?php

namespace App\Modules\Agences;

use App\Models\Company;
use App\Modules\Agences\Models\Branch;
use App\Support\BranchScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Tableau de bord comparatif des agences : ventes, marge, dépenses, trésorerie, stock, créances. */
class BranchService
{
    public function summary(Company $c, string $from, string $to): array
    {
        $allowed = BranchScope::allowed();
        $branches = Branch::orderByDesc('is_head_office')->orderBy('name')->get();
        $keys = $branches->pluck('id')->all();
        if ($allowed === null) {
            $keys[] = null; // documents sans agence
        }
        $sign = "(CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END)";
        $rate = 'COALESCE(d.exchange_rate, 1)';
        $saleDocs = fn () => DB::table('trade_documents as d')->where('d.company_id', $c->id)->whereNull('d.deleted_at')->where('d.direction', 'sale')
            ->where('d.status', 'validated')->whereIn('d.type', ['invoice', 'receipt', 'credit_note'])->whereBetween('d.date', [$from, $to])
            ->where(fn ($w) => $w->whereNull('d.is_opening')->orWhere('d.is_opening', false));
        $by = fn ($rows) => collect($rows)->keyBy(fn ($r) => $r->b ?? '');
        $sales = $by($saleDocs()->groupBy('d.branch_id')->selectRaw("d.branch_id b, SUM($sign * d.subtotal * $rate) ht, SUM(CASE WHEN d.type = 'receipt' THEN 1 ELSE 0 END) tickets, SUM(CASE WHEN d.type = 'invoice' THEN 1 ELSE 0 END) invoices, SUM(CASE WHEN d.type <> 'credit_note' THEN d.total * $rate ELSE 0 END) ttc, SUM(CASE WHEN d.type <> 'credit_note' THEN 1 ELSE 0 END) n")->get());
        $margin = $by($saleDocs()->join('trade_document_lines as l', 'l.document_id', '=', 'd.id')->leftJoin('products as pr', 'pr.id', '=', 'l.product_id')->groupBy('d.branch_id')
            ->selectRaw("d.branch_id b, SUM(CASE WHEN pr.purchase_price > 0 THEN $sign * (l.subtotal * $rate - l.quantity * COALESCE(l.unit_factor, 1) * pr.purchase_price) ELSE 0 END) m, SUM(CASE WHEN pr.purchase_price > 0 THEN $sign * l.subtotal * $rate ELSE 0 END) base")->get());
        $purch = $by(DB::table('trade_documents as d')->where('d.company_id', $c->id)->whereNull('d.deleted_at')->where('d.direction', 'purchase')->where('d.status', 'validated')
            ->where('d.type', 'supplier_invoice')->whereBetween('d.date', [$from, $to])->groupBy('d.branch_id')->selectRaw("d.branch_id b, SUM(d.subtotal * $rate) ht")->get());
        $unpaid = $by(DB::table('trade_documents as d')->where('d.company_id', $c->id)->whereNull('d.deleted_at')->where('d.direction', 'sale')->where('d.status', 'validated')
            ->where('d.type', 'invoice')->whereIn('d.payment_status', ['unpaid', 'partial'])->groupBy('d.branch_id')->selectRaw("d.branch_id b, SUM((d.total - d.amount_paid) * $rate) due")->get());
        $hasTr = \Illuminate\Support\Facades\Schema::hasTable('tr_operations');
        $ops = $hasTr ? $by(DB::table('tr_operations as o')->where('o.company_id', $c->id)->whereNull('o.deleted_at')->whereNull('o.transfer_id')->whereBetween('o.date', [$from, $to])
            ->groupBy('o.branch_id')->selectRaw('o.branch_id b, SUM(CASE WHEN o.amount < 0 THEN -o.amount ELSE 0 END) expenses, SUM(CASE WHEN o.amount > 0 THEN o.amount ELSE 0 END) incomes')->get()) : collect();
        $stock = $by(DB::table('stock_moves as m')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')->leftJoin('products as pr', 'pr.id', '=', 'm.product_id')
            ->where('m.company_id', $c->id)->whereNull('m.deleted_at')->where('m.date', '<=', $to)->groupBy('w.branch_id')->selectRaw('w.branch_id b, SUM(m.quantity * COALESCE(pr.purchase_price, 0)) v')->get());
        $cash = [];
        if ($hasTr && $c->hasModule('tresorerie')) {
            $svc = app(\App\Modules\Tresorerie\TreasuryService::class);
            foreach (\App\Modules\Tresorerie\Models\TreasuryAccount::where('active', true)->get() as $a) {
                $cash[$a->branch_id ?? ''] = ($cash[$a->branch_id ?? ''] ?? 0) + $svc->balance($a, $to);
            }
        }
        $users = DB::table('branch_user')->whereIn('branch_id', $branches->pluck('id'))->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) n')->pluck('n', 'branch_id');
        $rows = [];
        foreach ($keys as $id) {
            $k = $id ?? '';
            $b = $id ? $branches->firstWhere('id', $id) : null;
            $s = $sales->get($k);
            $mg = $margin->get($k);
            $ex = (float) ($ops->get($k)->expenses ?? 0);
            $row = [
                'id' => $id, 'name' => $b?->name ?? 'Sans agence (siège / non attribué)', 'code' => $b?->code, 'city' => $b?->city, 'is_head_office' => (bool) $b?->is_head_office,
                'sales' => round((float) ($s->ht ?? 0), 2), 'sales_ttc' => round((float) ($s->ttc ?? 0), 2), 'tickets' => (int) ($s->tickets ?? 0), 'invoices' => (int) ($s->invoices ?? 0),
                'basket' => ($s->n ?? 0) > 0 ? round((float) $s->ttc / $s->n, 2) : 0.0,
                'margin' => round((float) ($mg->m ?? 0), 2), 'margin_rate' => ($mg->base ?? 0) > 0 ? round(100 * $mg->m / $mg->base, 1) : null,
                // part du CA dont la marge est connue (articles avec prix d'achat)
                'margin_coverage' => ($s->ht ?? 0) > 0 ? round(min(100, 100 * (float) ($mg->base ?? 0) / (float) $s->ht), 1) : null,
                'purchases' => round((float) ($purch->get($k)->ht ?? 0), 2), 'expenses' => round($ex, 2), 'other_income' => round((float) ($ops->get($k)->incomes ?? 0), 2),
                'result' => round((float) ($mg->m ?? 0) - $ex, 2), 'receivables' => round((float) ($unpaid->get($k)->due ?? 0), 2),
                'cash' => round($cash[$k] ?? 0, 2), 'stock_value' => round((float) ($stock->get($k)->v ?? 0), 2), 'users' => (int) ($users[$id] ?? 0),
            ];
            if ($id || array_sum(array_map(fn ($x) => is_numeric($x) ? abs($x) : 0, array_intersect_key($row, array_flip(['sales', 'purchases', 'expenses', 'cash', 'stock_value'])))) > 0) {
                $rows[] = $row;
            }
        }
        $sum = fn (string $f) => round(array_sum(array_column($rows, $f)), 2);
        // Ventes mensuelles par agence
        $months = [];
        for ($m = Carbon::parse($from)->startOfMonth(); $m->lte(Carbon::parse($to)); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }
        $monthly = $saleDocs()->groupBy('d.branch_id', DB::raw(\App\Modules\Rapports\Sql::bucket('month', 'd.date')))
            ->selectRaw('d.branch_id b, '.\App\Modules\Rapports\Sql::bucket('month', 'd.date')." mth, SUM($sign * d.subtotal * $rate) ht")->get();
        $series = [];
        foreach ($rows as $r) {
            $series[] = ['id' => $r['id'], 'name' => $r['name'], 'values' => array_map(fn ($mth) => round((float) ($monthly->first(fn ($x) => ($x->b ?? null) === $r['id'] && $x->mth === $mth)->ht ?? 0), 2), $months)];
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'months' => $months, 'series' => $series,
            'totals' => ['margin_base' => round($margin->sum(fn ($x) => (float) $x->base), 2), 'sales' => $sum('sales'), 'margin' => $sum('margin'), 'expenses' => $sum('expenses'), 'result' => $sum('result'), 'cash' => $sum('cash'), 'stock_value' => $sum('stock_value'), 'receivables' => $sum('receivables'), 'purchases' => $sum('purchases')],
            'restricted' => $allowed !== null];
    }
}
