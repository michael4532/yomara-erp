<?php

namespace App\Modules\Projets;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Projets\Models\Project;
use App\Modules\Projets\Models\Timesheet;
use Illuminate\Support\Facades\DB;

/**
 * Rentabilité d'un projet : budget, main-d'œuvre (heures × coût horaire), achats engagés et réalisés,
 * chiffre d'affaires facturé, marge et projection à terminaison.
 */
class ProjectCostService
{
    public function settings(Company $c): array
    {
        $s = (array) data_get($c->settings, 'projets', []);

        return ['hourly_cost' => (float) ($s['hourly_cost'] ?? 0), 'user_costs' => array_map('floatval', (array) ($s['user_costs'] ?? []))];
    }

    public function saveSettings(Company $c, array $v): array
    {
        $s = (array) ($c->settings ?? []);
        $cur = $this->settings($c);
        if (array_key_exists('hourly_cost', $v)) {
            $cur['hourly_cost'] = round((float) $v['hourly_cost'], 2);
        }
        if (array_key_exists('user_costs', $v)) {
            $valid = User::where('company_id', $c->id)->pluck('id')->map(fn ($i) => (string) $i)->all();
            $cur['user_costs'] = collect((array) $v['user_costs'])->filter(fn ($x, $k) => in_array((string) $k, $valid, true) && $x !== null && $x !== '')
                ->map(fn ($x) => round((float) $x, 2))->all();
        }
        $s['projets'] = array_merge((array) ($s['projets'] ?? []), $cur);
        $c->forceFill(['settings' => $s])->save();

        return $this->settings($c);
    }

    public function costFor(array $settings, int $userId): float
    {
        return (float) ($settings['user_costs'][(string) $userId] ?? $settings['hourly_cost']);
    }

    /** Somme HT des documents rattachés au projet (avoirs déduits pour les ventes). */
    protected function docs(string $projectId, string $direction, array $types, array $statuses = ['validated'])
    {
        return DB::table('trade_documents')->where('company_id', app('tenant')->id)->where('project_id', $projectId)->where('direction', $direction)
            ->whereIn('type', $types)->whereIn('status', $statuses)->whereNull('deleted_at');
    }

    public function compute(Project $p): array
    {
        $c = app('tenant');
        $set = $this->settings($c);
        $times = Timesheet::where('project_id', $p->id)->get(['user_id', 'hours']);
        $users = User::where('company_id', $c->id)->pluck('name', 'id');
        $byUser = $times->groupBy('user_id')->map(function ($g, $uid) use ($set, $users) {
            $h = round($g->sum('hours'), 2);
            $rate = $this->costFor($set, (int) $uid);

            return ['user_id' => (int) $uid, 'name' => $users[$uid] ?? '—', 'hours' => $h, 'hourly_cost' => $rate, 'cost' => round($h * $rate, 2)];
        })->sortByDesc('cost')->values();
        $hours = round($times->sum('hours'), 2);
        $labour = round($byUser->sum('cost'), 2);

        $hasAchats = $c->hasModule('achats');
        $purchases = $hasAchats ? round((float) $this->docs($p->id, 'purchase', ['supplier_invoice'])->sum('subtotal'), 2) : 0.0;
        // Engagements : bons de commande validés, moins ce qui a déjà été facturé dessus
        $commitments = 0.0;
        $orders = [];
        if ($hasAchats) {
            foreach ($this->docs($p->id, 'purchase', ['purchase_order'])->get(['id', 'number', 'partner_name', 'date', 'subtotal']) as $o) {
                $invoiced = (float) DB::table('trade_documents')->where('company_id', $c->id)->where('direction', 'purchase')->where('type', 'supplier_invoice')
                    ->where('source_id', $o->id)->where('status', '!=', 'cancelled')->whereNull('deleted_at')->sum('subtotal');
                $open = round(max(0, (float) $o->subtotal - $invoiced), 2);
                $commitments += $open;
                $orders[] = ['id' => $o->id, 'number' => $o->number, 'partner_name' => $o->partner_name, 'date' => $o->date, 'amount' => (float) $o->subtotal, 'invoiced' => round($invoiced, 2), 'open' => $open];
            }
        }
        $commitments = round($commitments, 2);
        $invoices = $hasAchats ? $this->docs($p->id, 'purchase', ['supplier_invoice'])->orderByDesc('date')->limit(50)->get(['id', 'number', 'partner_name', 'date', 'subtotal'])
            ->map(fn ($d) => ['id' => $d->id, 'number' => $d->number, 'partner_name' => $d->partner_name, 'date' => $d->date, 'amount' => (float) $d->subtotal])->all() : [];

        // Notes de frais approuvées ou remboursées rattachées au projet (TTC : TVA rarement récupérable)
        $expenses = 0.0;
        $expenseRows = [];
        if ($c->hasModule('notes_frais')) {
            $rows = DB::table('nf_expenses')->where('company_id', $c->id)->where('project_id', $p->id)->whereIn('status', ['approved', 'paid'])->whereNull('deleted_at')->orderByDesc('date')->get(['id', 'date', 'amount', 'description', 'employee_id', 'status']);
            $expenses = round((float) $rows->sum('amount'), 2);
            $names = DB::table('rh_employees')->whereIn('id', $rows->pluck('employee_id')->unique())->get()->mapWithKeys(fn ($e) => [$e->id => trim(($e->first_name ?? '').' '.($e->last_name ?? ''))]);
            $expenseRows = $rows->take(50)->map(fn ($e) => ['id' => $e->id, 'date' => $e->date, 'amount' => (float) $e->amount, 'description' => $e->description, 'employee' => $names[$e->employee_id] ?? null, 'status' => $e->status])->all();
        }

        $hasVentes = $c->hasModule('ventes');
        $revenue = $hasVentes ? round((float) $this->docs($p->id, 'sale', ['invoice'])->sum('subtotal') - (float) $this->docs($p->id, 'sale', ['credit_note'])->sum('subtotal'), 2) : 0.0;
        $ordered = $hasVentes ? round((float) $this->docs($p->id, 'sale', ['order', 'quote'], ['validated'])->where('type', 'order')->sum('subtotal'), 2) : 0.0;

        $actual = round($labour + $purchases + $expenses, 2);
        $committed = round($actual + $commitments, 2);
        // Projection : heures restantes au budget d'heures, valorisées au coût moyen constaté
        $avgRate = $hours > 0 ? $labour / $hours : $set['hourly_cost'];
        $remainingHours = $p->budget_hours > 0 ? max(0, (float) $p->budget_hours - $hours) : 0;
        $forecastCost = round($committed + $remainingHours * $avgRate, 2);
        $expectedRevenue = max($revenue, $ordered, (float) ($p->billing_mode === 'fixed' ? DB::table('prj_milestones')->where('project_id', $p->id)->whereNull('deleted_at')->sum('amount') : 0),
            $p->billing_mode === 'time' && $p->budget_hours > 0 ? (float) $p->budget_hours * (float) $p->hourly_rate : 0);
        $budget = (float) $p->budget_amount;

        return [
            'project_id' => $p->id, 'currency' => $c->currency,
            'budget' => $budget ?: null,
            'hours' => $hours, 'labour' => $labour, 'labour_configured' => $set['hourly_cost'] > 0 || $set['user_costs'] !== [],
            'purchases' => $purchases, 'expenses' => $expenses, 'commitments' => $commitments,
            'actual_cost' => $actual, 'committed_cost' => $committed,
            'revenue' => $revenue, 'margin' => round($revenue - $actual, 2), 'margin_rate' => $revenue > 0 ? round(($revenue - $actual) * 100 / $revenue, 1) : null,
            'budget_used' => $budget > 0 ? round($committed * 100 / $budget, 1) : null,
            'budget_left' => $budget > 0 ? round($budget - $committed, 2) : null,
            'forecast_cost' => $forecastCost, 'expected_revenue' => round($expectedRevenue, 2),
            'forecast_margin' => $expectedRevenue > 0 ? round($expectedRevenue - $forecastCost, 2) : null,
            'by_user' => $byUser, 'orders' => $orders, 'invoices' => $invoices, 'expense_list' => $expenseRows,
        ];
    }

    /** Alerte au chef de projet quand les coûts engagés franchissent 80 % puis 100 % du budget. */
    public function checkAlert(Project $p, ?array $costs = null): ?int
    {
        if (! ($p->budget_amount > 0) || in_array($p->status, ['done', 'cancelled'], true)) {
            return null;
        }
        $costs ??= $this->compute($p);
        $used = (float) $costs['budget_used'];
        $level = $used >= 100 ? 100 : ($used >= 80 ? 80 : null);
        if ($level === null) {
            if ($p->cost_alerted) {
                $p->forceFill(['cost_alerted' => null])->saveQuietly();
            }

            return null;
        }
        if ((int) $p->cost_alerted >= $level) {
            return null;
        }
        $p->forceFill(['cost_alerted' => $level])->saveQuietly();
        $to = array_filter([$p->manager_id]) ?: User::where('company_id', app('tenant')->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('projets.manage'))->pluck('id')->all();
        $fmt = fn ($v) => number_format($v, 0, ',', ' ');
        AppNotification::send(app('tenant')->id, $to, 'projets', ($level >= 100 ? 'Budget dépassé : ' : 'Budget à '.$level.' % : ').$p->name,
            'Coûts engagés '.$fmt($costs['committed_cost']).' / budget '.$fmt($p->budget_amount).' '.$costs['currency'].' ('.$used.' %)', 'projets', $p->id);

        return $level;
    }

    public function runDaily(Company $c): int
    {
        $n = 0;
        foreach (Project::whereNotIn('status', ['done', 'cancelled'])->where('budget_amount', '>', 0)->get() as $p) {
            $n += $this->checkAlert($p) ? 1 : 0;
        }

        return $n;
    }
}
