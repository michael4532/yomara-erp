<?php

namespace App\Modules\Analytique;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Analytique\Models\AnLine;
use App\Modules\Analytique\Models\Budget;
use App\Modules\Analytique\Models\BudgetLine;
use App\Modules\Analytique\Models\Section;
use App\Modules\Compta\Chart;
use App\Modules\Core\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Budgets : montants prévus par section et/ou par compte, mois par mois,
 * comparés au réalisé (lignes analytiques, ou grand livre pour un budget par compte)
 * et aux engagements (bons de commande validés non encore facturés).
 */
class BudgetService
{
    public function __construct(private AnalyticService $an) {}

    public function figures(Company $company, Budget $b, BudgetLine $l): array
    {
        $from = $b->start_date->toDateString();
        $to = $b->end_date->toDateString();
        $monthly = array_fill_keys($b->months(), 0.0);
        $realized = null;
        $month = fn (string $col) => DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', $col)" : "DATE_FORMAT($col, '%Y-%m')";

        if ($l->section_id) {
            $q = AnLine::whereIn('section_id', $this->an->descendants($l->section_id))->whereBetween('date', [$from, $to])
                ->when($l->account_prefix, fn ($q, $p) => $q->where('account_code', 'like', $p.'%'));
            $expr = $l->kind === 'revenue' ? AnalyticService::REV : AnalyticService::COST;
            foreach ($q->selectRaw($month('date').' m, SUM('.$expr.') s')->groupBy('m')->get() as $r) {
                if (array_key_exists($r->m, $monthly)) {
                    $monthly[$r->m] = round((float) $r->s, 2);
                }
            }
            $realized = round(array_sum($monthly), 2);
        } elseif ($l->account_prefix && $company->hasModule('compta')) {
            $rows = DB::table('compta_entry_lines as l')->join('compta_entries as e', 'e.id', '=', 'l.entry_id')->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('e.company_id', $company->id)->where('e.kind', '!=', 'closing')->whereBetween('e.date', [$from, $to])->where('a.code', 'like', $l->account_prefix.'%')
                ->selectRaw($month('e.date').' m, SUM(l.debit) d, SUM(l.credit) c')->groupBy('m')->get();
            foreach ($rows as $r) {
                if (array_key_exists($r->m, $monthly)) {
                    $monthly[$r->m] = round($l->kind === 'revenue' ? (float) $r->c - (float) $r->d : (float) $r->d - (float) $r->c, 2);
                }
            }
            $realized = round(array_sum($monthly), 2);
        }

        $committed = $l->kind === 'expense' && $l->section_id ? $this->committed($company, $l, $from, $to) : 0.0;
        $total = (float) $l->total;
        $consumed = ($realized ?? 0) + $committed;

        return [
            'realized' => $realized,
            'committed' => round($committed, 2),
            'remaining' => $realized === null ? null : round($total - $consumed, 2),
            'percent' => $realized === null || $total <= 0 ? null : round($consumed / $total * 100, 1),
            'monthly_realized' => $monthly,
        ];
    }

    /** Bons de commande validés, pas encore transformés en facture fournisseur validée, ventilés sur la section. */
    public function committed(Company $company, BudgetLine $l, string $from, string $to): float
    {
        if (! $company->hasModule('achats')) {
            return 0.0;
        }
        $sections = $this->an->descendants($l->section_id);
        $invoiced = DB::table('trade_documents')->where('company_id', $company->id)->where('direction', 'purchase')->where('type', 'supplier_invoice')
            ->where('status', 'validated')->whereNull('deleted_at')->whereNotNull('source_id')->pluck('source_id')->all();
        $orders = \App\Modules\Achats\Models\PurchaseDocument::with('lines')->where('type', 'purchase_order')->where('status', 'validated')
            ->whereBetween('date', [$from, $to])->whereNotIn('id', $invoiced)->get();
        $map = Chart::mapping($company);
        $sum = 0.0;
        foreach ($orders as $o) {
            $products = Product::withoutGlobalScopes()->whereIn('id', $o->lines->pluck('product_id')->filter())->get(['id', 'type', 'category_id'])->keyBy('id');
            foreach ($o->lines as $line) {
                $p = $line->product_id ? $products->get($line->product_id) : null;
                $dist = Distribution::merge($this->an->ruleDistribution('purchase', ['product_id' => $line->product_id, 'category_id' => $p?->category_id, 'partner_id' => $o->partner_id]), $o->analytic, $line->analytic);
                if (! $dist) {
                    continue;
                }
                $account = $map[($p?->type ?? 'product') === 'service' ? 'purchases_services' : 'purchases_goods'];
                if ($l->account_prefix && ! str_starts_with($account, $l->account_prefix)) {
                    continue;
                }
                foreach ($dist as $sid => $pct) {
                    if (in_array($sid, $sections, true)) {
                        $sum += (float) $line->subtotal * (float) ($o->exchange_rate ?: 1) * $pct / 100;
                    }
                }
            }
        }

        return round($sum, 2);
    }

    public function summary(Company $company, Budget $b): array
    {
        $names = Section::withTrashed()->pluck('name', 'id');
        $lines = [];
        $tot = ['expense' => ['budget' => 0.0, 'realized' => 0.0, 'committed' => 0.0], 'revenue' => ['budget' => 0.0, 'realized' => 0.0, 'committed' => 0.0]];
        $monthsBudget = array_fill_keys($b->months(), ['expense' => 0.0, 'revenue' => 0.0, 'expense_real' => 0.0, 'revenue_real' => 0.0]);
        foreach ($b->lines as $l) {
            $f = $this->figures($company, $b, $l);
            $lines[] = $l->toArray() + $f + ['section_name' => $l->section_id ? ($names[$l->section_id] ?? '—') : null];
            $tot[$l->kind]['budget'] += (float) $l->total;
            $tot[$l->kind]['realized'] += (float) ($f['realized'] ?? 0);
            $tot[$l->kind]['committed'] += $f['committed'];
            foreach ($monthsBudget as $m => &$v) {
                $v[$l->kind] += (float) ($l->amounts[$m] ?? 0);
                $v[$l->kind.'_real'] += (float) ($f['monthly_realized'][$m] ?? 0);
            }
            unset($v);
        }
        foreach ($tot as &$t) {
            $t = array_map(fn ($x) => round($x, 2), $t);
            $t['remaining'] = round($t['budget'] - $t['realized'] - $t['committed'], 2);
            $t['percent'] = $t['budget'] > 0 ? round(($t['realized'] + $t['committed']) / $t['budget'] * 100, 1) : null;
        }
        unset($t);

        return $b->only(['id', 'name', 'status', 'warn_percent', 'notes']) + [
            'start_date' => $b->start_date->toDateString(), 'end_date' => $b->end_date->toDateString(),
            'approved_at' => $b->approved_at?->toIso8601String(),
            'months' => $b->months(),
            'lines' => $lines,
            'totals' => $tot,
            'monthly' => collect($monthsBudget)->map(fn ($v, $m) => ['month' => $m] + array_map(fn ($x) => round($x, 2), $v))->values(),
            'compta' => $company->hasModule('compta'),
        ];
    }

    /** Remplace les lignes d'un budget (brouillon ou approuvé). */
    public function saveLines(Company $company, Budget $b, array $lines): void
    {
        if ($b->status === 'closed') {
            throw new \DomainException('Ce budget est clôturé.');
        }
        $months = $b->months();
        DB::transaction(function () use ($company, $b, $lines, $months) {
            BudgetLine::where('budget_id', $b->id)->delete();
            foreach (array_values($lines) as $i => $l) {
                if (empty($l['section_id']) && empty($l['account_prefix'])) {
                    throw new \DomainException('Ligne '.($i + 1).' : choisissez une section ou un compte.');
                }
                if (empty($l['section_id']) && ! $company->hasModule('compta')) {
                    throw new \DomainException('Ligne '.($i + 1).' : choisissez une section (le budget par compte seul demande le module Comptabilité).');
                }
                if (! empty($l['section_id']) && ! Section::whereKey($l['section_id'])->exists()) {
                    throw new \DomainException('Ligne '.($i + 1).' : section introuvable.');
                }
                $amounts = [];
                if (isset($l['amounts']) && is_array($l['amounts'])) {
                    foreach ($months as $m) {
                        $amounts[$m] = round(max(0, (float) ($l['amounts'][$m] ?? 0)), 2);
                    }
                } else { // montant annuel réparti également
                    $dec = \App\Modules\Core\Models\TradeDocumentLine::decimalsFor($company->currency);
                    $total = round(max(0, (float) ($l['total'] ?? 0)), $dec);
                    $each = floor($total / count($months) * 10 ** $dec) / 10 ** $dec;
                    foreach ($months as $k => $m) {
                        $amounts[$m] = $k === count($months) - 1 ? round($total - $each * (count($months) - 1), $dec) : $each;
                    }
                }
                $line = new BudgetLine([
                    'budget_id' => $b->id, 'kind' => ($l['kind'] ?? 'expense') === 'revenue' ? 'revenue' : 'expense',
                    'section_id' => $l['section_id'] ?? null, 'account_prefix' => isset($l['account_prefix']) ? (preg_replace('/\D/', '', (string) $l['account_prefix']) ?: null) : null,
                    'label' => isset($l['label']) ? mb_substr((string) $l['label'], 0, 255) : null, 'owner_id' => $l['owner_id'] ?? null,
                    'amounts' => $amounts, 'total' => round(array_sum($amounts), 2), 'sort' => $i,
                ]);
                $line->company_id = $company->id;
                $line->save();
            }
        });
    }

    /** Budget prérempli avec le réalisé d'une période passée (± pourcentage), mois par mois. */
    public function fromActual(Company $company, Budget $b, string $sourceFrom, int $percent, ?string $axisId): int
    {
        $months = $b->months();
        $start = Carbon::parse($sourceFrom)->startOfMonth();
        $end = $start->copy()->addMonths(count($months))->subDay();
        $monthExpr = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', date)" : "DATE_FORMAT(date, '%Y-%m')";
        $rows = AnLine::when($axisId, fn ($q, $a) => $q->where('axis_id', $a))->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("section_id, CASE WHEN account_code LIKE '7%' OR (account_code IS NULL AND amount > 0) THEN 'revenue' ELSE 'expense' END kind, $monthExpr m, SUM(amount) s")
            ->groupBy('section_id', 'kind', 'm')->get();
        $lines = [];
        foreach ($rows as $r) {
            $key = $r->section_id.'|'.$r->kind;
            [$y, $mo] = array_map('intval', explode('-', $r->m));
            $target = $months[($y - (int) $start->year) * 12 + ($mo - (int) $start->month)] ?? null;
            if (! $target) {
                continue;
            }
            $lines[$key] ??= ['kind' => $r->kind, 'section_id' => $r->section_id, 'amounts' => array_fill_keys($months, 0)];
            $lines[$key]['amounts'][$target] += round(abs((float) $r->s) * (100 + $percent) / 100, 0);
        }
        $existing = $b->lines->map(fn ($l) => $l->only(['kind', 'section_id', 'account_prefix', 'label', 'owner_id', 'amounts']))->all();
        $this->saveLines($company, $b, array_merge($existing, array_values($lines)));

        return count($lines);
    }

    /** Alerte les responsables quand un budget de charges approche ou dépasse son montant. */
    public function checkAlerts(Company $company, array $sectionIds = []): int
    {
        $today = now()->toDateString();
        $budgets = Budget::with('lines')->where('status', 'approved')->where('start_date', '<=', $today)->where('end_date', '>=', $today)->get();
        $sent = 0;
        $managers = null;
        foreach ($budgets as $b) {
            foreach ($b->lines->where('kind', 'expense') as $l) {
                if ($sectionIds && (! $l->section_id || ! array_intersect($this->an->descendants($l->section_id), $sectionIds))) {
                    continue;
                }
                if ((float) $l->total <= 0) {
                    continue;
                }
                $f = $this->figures($company, $b, $l);
                if ($f['percent'] === null) {
                    continue;
                }
                $level = $f['percent'] >= 100 ? 100 : ($f['percent'] >= $b->warn_percent ? $b->warn_percent : 0);
                if ($level > $l->alerted) {
                    $section = $l->section_id ? Section::withTrashed()->find($l->section_id) : null;
                    $what = trim(($l->label ?: ($section?->name ?? 'Compte '.$l->account_prefix)).($l->account_prefix && $section ? ' ('.$l->account_prefix.')' : ''));
                    $managers ??= User::withoutGlobalScopes()->where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('analytique.manage'))->pluck('id')->all();
                    $to = array_filter(array_unique(array_merge($managers, [$l->owner_id, $section?->manager_id])));
                    $title = $level >= 100 ? 'Budget dépassé : '.$what : 'Budget consommé à '.round($f['percent']).' % : '.$what;
                    $body = $b->name.' — '.number_format((float) $l->total, 0, ',', ' ').' prévus, '.number_format(($f['realized'] ?? 0) + $f['committed'], 0, ',', ' ').' consommés (dont '.number_format($f['committed'], 0, ',', ' ').' engagés).';
                    AppNotification::send($company->id, $to, 'budget', $title, $body, 'analytique', (string) $b->id);
                    $sent++;
                }
                if ($level !== $l->alerted) {
                    $l->alerted = $level;
                    $l->save();
                }
            }
        }

        return $sent;
    }

    /** Budget restant des sections (pour la saisie d'un achat ou d'une note de frais). */
    public function status(Company $company, array $sectionIds): array
    {
        $today = now()->toDateString();
        $out = [];
        foreach (Budget::with('lines')->where('status', 'approved')->where('start_date', '<=', $today)->where('end_date', '>=', $today)->get() as $b) {
            foreach ($b->lines->where('kind', 'expense') as $l) {
                if (! $l->section_id || ! in_array($l->section_id, $sectionIds, true)) {
                    continue;
                }
                $f = $this->figures($company, $b, $l);
                $out[] = ['budget' => $b->name, 'section_id' => $l->section_id, 'account_prefix' => $l->account_prefix, 'label' => $l->label,
                    'total' => (float) $l->total, 'consumed' => round(($f['realized'] ?? 0) + $f['committed'], 2), 'remaining' => $f['remaining'], 'percent' => $f['percent']];
            }
        }

        return $out;
    }
}
