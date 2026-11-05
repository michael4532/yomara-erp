<?php

namespace App\Modules\Analytique;

use App\Models\Company;
use App\Modules\Analytique\Models\AnLine;
use App\Modules\Analytique\Models\Axis;
use App\Modules\Analytique\Models\Rule;
use App\Modules\Analytique\Models\Section;
use App\Modules\Compta\Chart;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\TradeDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comptabilité analytique : chaque montant (vente, achat, note de frais, paie)
 * est réparti sur des sections selon, par ordre de priorité :
 *   1. la ventilation saisie sur la ligne,
 *   2. celle du document (ou de la note de frais),
 *   3. la règle automatique la plus précise (produit > catégorie de frais > catégorie > tiers > service),
 *   4. la section du projet.
 * Les lignes analytiques sont recalculées à chaque validation / annulation.
 */
class AnalyticService
{
    public const SALE_SIGNS = ['invoice' => 1, 'receipt' => 1, 'credit_note' => -1];

    public const PURCHASE_SIGNS = ['supplier_invoice' => -1];

    private array $rulesCache = [];

    /** Produit = compte de classe 7 (ou montant positif sans compte) ; un avoir vient en diminution du produit. */
    public const REV = "CASE WHEN account_code LIKE '7%' OR (account_code IS NULL AND amount > 0) THEN amount ELSE 0 END";

    public const COST = "CASE WHEN account_code LIKE '7%' OR (account_code IS NULL AND amount > 0) THEN 0 ELSE -amount END";

    public function enabled(?Company $company = null): bool
    {
        $company ??= app()->bound('tenant') ? app('tenant') : null;

        return (bool) $company?->hasModule('analytique');
    }

    // ------------------------------------------------------------------
    // Règles et projets
    // ------------------------------------------------------------------

    public function ruleDistribution(string $appliesTo, array $ctx): ?array
    {
        $cid = app('tenant')->id;
        $rules = $this->rulesCache[$cid] ??= Rule::where('active', true)->orderBy('sort')->get()->all();
        $best = null;
        foreach ($rules as $r) {
            if (! in_array($r->applies_to, [$appliesTo, 'any'], true)) {
                continue;
            }
            foreach (['product_id', 'category_id', 'partner_id', 'expense_category_id', 'department'] as $k) {
                if ($r->{$k} !== null && $r->{$k} !== '' && mb_strtolower((string) $r->{$k}) !== mb_strtolower((string) ($ctx[$k] ?? ''))) {
                    continue 2;
                }
            }
            if (! $best || $r->score() > $best->score()) {
                $best = $r;
            }
        }

        return $best?->distribution ?: null;
    }

    public function flushRules(): void
    {
        $this->rulesCache = [];
    }

    public function projectsAxis(bool $create = true): ?Axis
    {
        $axis = Axis::where('system', 'projects')->first();
        if (! $axis && $create) {
            $axis = new Axis(['name' => 'Projets', 'code' => 'PRJ', 'sort' => 90]);
            $axis->system = 'projects';
            $axis->save();
        }

        return $axis;
    }

    public function branchesAxis(bool $create = true): ?Axis
    {
        $axis = Axis::where('system', 'branches')->first();
        if (! $axis && $create) {
            $axis = new Axis(['name' => 'Agences', 'code' => 'AGC', 'sort' => 80]);
            $axis->system = 'branches';
            $axis->save();
        }

        return $axis;
    }

    /** Section suivant une agence (multi-agences). */
    public function branchSection(object $branch, bool $create = true): ?Section
    {
        $axis = $this->branchesAxis($create);
        if (! $axis) {
            return null;
        }
        $s = Section::withTrashed()->where('branch_id', $branch->id)->first();
        if (! $s && ! $create) {
            return null;
        }
        $s ??= new Section;
        $s->axis_id = $axis->id;
        $s->branch_id = $branch->id;
        $s->name = mb_substr($branch->name, 0, 120);
        $s->code = $branch->code ? mb_substr($branch->code, 0, 30) : null;
        $s->manager_id = $branch->manager_id ?? null;
        $s->active = (bool) ($branch->active ?? true) && ! ($branch->trashed() ?? false);
        $s->deleted_at = null;
        if ($s->isDirty()) {
            $s->save();
        }

        return $s;
    }

    /** Ventilation à 100 % sur la section de l'agence du document. */
    protected function branchDistribution(?string $branchId): ?array
    {
        if (! $branchId || ! app('tenant')->hasModule('agences')) {
            return null;
        }
        $b = \App\Modules\Agences\Models\Branch::withoutGlobalScopes()->withTrashed()->find($branchId);
        $s = $b ? $this->branchSection($b) : null;

        return $s ? [$s->id => 100] : null;
    }

    /** Section suivant un projet (créée au besoin). */
    public function projectSection(object $project, bool $create = true): ?Section
    {
        $s = Section::withTrashed()->where('project_id', $project->id)->first();
        if (! $s && ! $create) {
            return null;
        }
        $axis = $this->projectsAxis();
        $s ??= new Section;
        $s->axis_id = $axis->id;
        $s->project_id = $project->id;
        $s->name = mb_substr($project->name, 0, 120);
        $s->code = $project->code ? mb_substr($project->code, 0, 30) : null;
        $s->manager_id = $project->manager_id ?? null;
        $s->active = ! in_array($project->status ?? 'active', ['cancelled'], true) && ! ($project->trashed() ?? false);
        $s->deleted_at = null;
        if ($s->isDirty()) {
            $s->save();
        }

        return $s;
    }

    // ------------------------------------------------------------------
    // Génération des lignes
    // ------------------------------------------------------------------

    public function removeSource(string $type, string $id): array
    {
        $q = AnLine::where('source_type', $type)->where('source_id', $id);
        $sections = (clone $q)->distinct()->pluck('section_id')->all();
        $q->delete();

        return $sections;
    }

    /** Facture, ticket, avoir, facture fournisseur validés → lignes analytiques. */
    public function syncDocument(TradeDocument $doc): array
    {
        $touched = $this->removeSource('document', $doc->id);
        $sign = $doc::DIRECTION === 'sale' ? (self::SALE_SIGNS[$doc->type] ?? null) : (self::PURCHASE_SIGNS[$doc->type] ?? null);
        if (! $sign || $doc->status !== 'validated' || $doc->is_opening) {
            return $touched;
        }
        $company = app('tenant');
        $map = Chart::mapping($company);
        $doc->loadMissing('lines');
        $products = Product::withoutGlobalScopes()->whereIn('id', $doc->lines->pluck('product_id')->filter())->get(['id', 'type', 'category_id'])->keyBy('id');
        $applies = $doc::DIRECTION === 'sale' ? 'sale' : 'purchase';
        // Nature d'achat choisie ou mémorisée par la comptabilité
        $purchaseAccounts = $applies === 'purchase' ? app(\App\Modules\Compta\PurchaseReview::class)->accounts($company, $doc) : [];
        $branchDist = $this->branchDistribution($doc->branch_id ?? null);
        $rows = [];
        foreach ($doc->lines as $line) {
            $p = $line->product_id ? $products->get($line->product_id) : null;
            $dist = Distribution::merge($branchDist, $this->ruleDistribution($applies, [
                'product_id' => $line->product_id, 'category_id' => $p?->category_id, 'partner_id' => $doc->partner_id,
            ]), $doc->analytic, $line->analytic);
            if (! $dist || abs((float) $line->subtotal) < 0.005) {
                continue;
            }
            $service = ($p?->type ?? 'product') === 'service';
            $account = $applies === 'sale' ? $map[$service ? 'sales_services' : 'sales_goods'] : ($purchaseAccounts[$line->id]['code'] ?? $map[$service ? 'purchases_services' : 'purchases_goods']);
            $rows = array_merge($rows, $this->split($dist, $sign * (float) $line->subtotal * (float) ($doc->exchange_rate ?: 1), [
                'date' => $doc->date->toDateString(), 'account_code' => $account, 'source_type' => 'document', 'source_id' => $doc->id,
                'source_line_id' => $line->id, 'label' => trim(($doc::TYPES[$doc->type][0] ?? '').' '.$doc->number.' — '.$line->description),
                'partner_id' => $doc->partner_id, 'product_id' => $line->product_id, 'quantity' => (float) $line->quantity,
            ]));
        }

        return array_values(array_unique(array_merge($touched, $this->insert($rows))));
    }

    /** Note de frais approuvée → charge ventilée. */
    public function syncExpense(object $e): array
    {
        $touched = $this->removeSource('expense', $e->id);
        if (! in_array($e->status, ['approved', 'paid'], true) || $e->trashed()) {
            return $touched;
        }
        $cat = DB::table('nf_categories')->where('id', $e->category_id)->first(['name', 'gl_code']);
        $emp = DB::table('rh_employees')->where('id', $e->employee_id)->first(['first_name', 'last_name', 'department']);
        $dist = Distribution::merge($this->ruleDistribution('expense', ['expense_category_id' => $e->category_id, 'department' => $emp?->department]), $e->analytic);
        if (! $dist) {
            return $touched;
        }
        $rows = $this->split($dist, -(float) $e->amount, [
            'date' => Carbon::parse($e->date)->toDateString(), 'account_code' => $cat?->gl_code ?? '638', 'source_type' => 'expense', 'source_id' => $e->id,
            'label' => 'Note de frais '.trim(($emp?->first_name ?? '').' '.($emp?->last_name ?? '')).' — '.$e->description,
        ]);

        return array_values(array_unique(array_merge($touched, $this->insert($rows))));
    }

    /** Paie validée → coût employeur de chaque salarié ventilé selon son service. */
    public function syncPayroll(object $run): array
    {
        $touched = $this->removeSource('payroll', (string) $run->id);
        if (! in_array($run->status, ['validated', 'paid'], true)) {
            return $touched;
        }
        $end = Carbon::createFromFormat('Y-m', $run->period)->endOfMonth()->toDateString();
        $rows = [];
        $slips = DB::table('paie_payslips')->where('run_id', $run->id)->get();
        $departments = DB::table('rh_employees')->whereIn('id', $slips->pluck('employee_id')->filter())->pluck('department', 'id');
        foreach ($slips as $slip) {
            $snap = json_decode($slip->employee ?? '[]', true) ?: [];
            $dept = $snap['department'] ?? ($departments[$slip->employee_id] ?? null);
            $dist = $this->ruleDistribution('payroll', ['department' => $dept]);
            if (! $dist || (float) $slip->cost <= 0) {
                continue;
            }
            $name = $snap['full_name'] ?? trim(($snap['first_name'] ?? '').' '.($snap['last_name'] ?? ''));
            $rows = array_merge($rows, $this->split($dist, -(float) $slip->cost, [
                'date' => $end, 'account_code' => \App\Modules\Compta\Chart::local('66'), 'source_type' => 'payroll', 'source_id' => (string) $run->id, 'source_line_id' => (string) $slip->id,
                'label' => 'Paie '.$run->period.' — '.$name,
            ]));
        }

        return array_values(array_unique(array_merge($touched, $this->insert($rows))));
    }

    /**
     * Source quelconque (dotations aux amortissements…) : remplace ses lignes.
     * @param  list<array{date: string, amount: float, account: ?string, label: string, line_id?: ?string}>  $entries
     */
    public function syncSource(string $type, string $id, array $entries, ?array $dist): array
    {
        $touched = $this->removeSource($type, $id);
        if (! $dist) {
            return $touched;
        }
        $rows = [];
        foreach ($entries as $e) {
            $rows = array_merge($rows, $this->split($dist, (float) $e['amount'], [
                'date' => $e['date'], 'account_code' => $e['account'] ?? null, 'source_type' => $type, 'source_id' => $id,
                'source_line_id' => $e['line_id'] ?? null, 'label' => $e['label'],
            ]));
        }

        return array_values(array_unique(array_merge($touched, $this->insert($rows))));
    }

    /** Réaffectation ou montant saisi à la main (ex. loyer réparti entre sites). */
    public function manual(string $date, string $label, float $amount, ?string $account, array $dist): array
    {
        $dist = Distribution::clean($dist);
        if (! $dist) {
            throw new \DomainException('Indiquez au moins une section.');
        }
        $ref = (string) \Illuminate\Support\Str::uuid();

        return $this->insert($this->split($dist, $amount, [
            'date' => $date, 'account_code' => $account, 'source_type' => 'manual', 'source_id' => $ref, 'label' => $label, 'user_id' => auth()->id(),
        ]));
    }

    /** Recalcule tout (après création de règles ou activation tardive). */
    public function rebuild(Company $company, ?string $from = null): array
    {
        $this->flushRules();
        $n = ['documents' => 0, 'expenses' => 0, 'payroll' => 0];
        $docs = \App\Modules\Ventes\Models\SalesDocument::where('status', 'validated')->whereIn('type', array_keys(self::SALE_SIGNS))
            ->when($from, fn ($q) => $q->where('date', '>=', $from))->with('lines')->get();
        if ($company->hasModule('achats')) {
            $docs = $docs->concat(\App\Modules\Achats\Models\PurchaseDocument::where('status', 'validated')->whereIn('type', array_keys(self::PURCHASE_SIGNS))
                ->when($from, fn ($q) => $q->where('date', '>=', $from))->with('lines')->get());
        }
        foreach ($docs as $d) {
            $this->syncDocument($d);
            $n['documents']++;
        }
        if ($company->hasModule('notes_frais') && Schema::hasTable('nf_expenses')) {
            foreach (\App\Modules\NotesFrais\Models\Expense::whereIn('status', ['approved', 'paid'])->when($from, fn ($q) => $q->where('date', '>=', $from))->get() as $e) {
                $this->syncExpense($e);
                $n['expenses']++;
            }
        }
        if ($company->hasModule('paie')) {
            foreach (\App\Modules\Paie\Models\PayrollRun::whereIn('status', ['validated', 'paid'])->get() as $run) {
                if (! $from || $run->period >= substr($from, 0, 7)) {
                    $this->syncPayroll($run);
                    $n['payroll']++;
                }
            }
        }

        return $n;
    }

    private function split(array $dist, float $amount, array $base): array
    {
        $axes = Distribution::axesOf(array_keys($dist));
        $rows = [];
        foreach ($dist as $sid => $pct) {
            if (! isset($axes[$sid])) {
                continue;
            }
            $v = round($amount * $pct / 100, 2);
            if (abs($v) < 0.005) {
                continue;
            }
            $rows[] = $base + ['axis_id' => $axes[$sid], 'section_id' => $sid, 'amount' => $v, 'percent' => $pct];
        }

        return $rows;
    }

    private function insert(array $rows): array
    {
        if (! $rows) {
            return [];
        }
        $cid = app('tenant')->id;
        $now = now();
        $cols = ['axis_id', 'section_id', 'date', 'amount', 'account_code', 'source_type', 'source_id', 'source_line_id', 'label', 'partner_id', 'product_id', 'quantity', 'percent', 'user_id'];
        $batch = [];
        foreach ($rows as $r) {
            $row = ['company_id' => $cid, 'created_at' => $now, 'updated_at' => $now];
            foreach ($cols as $c) {
                $row[$c] = isset($r[$c]) ? (is_string($r[$c]) ? mb_substr($r[$c], 0, $c === 'label' ? 255 : 64) : $r[$c]) : null;
            }
            $batch[] = $row;
        }
        foreach (array_chunk($batch, 200) as $chunk) {
            AnLine::insert($chunk);
        }

        return array_values(array_unique(array_column($rows, 'section_id')));
    }

    // ------------------------------------------------------------------
    // Contrôle : axes obligatoires
    // ------------------------------------------------------------------

    public function assertRequiredAxes(TradeDocument $doc): void
    {
        $required = Axis::where('required', true)->where('active', true)->get(['id', 'name']);
        if ($required->isEmpty()) {
            return;
        }
        $doc->loadMissing('lines');
        $products = Product::withoutGlobalScopes()->whereIn('id', $doc->lines->pluck('product_id')->filter())->pluck('category_id', 'id');
        foreach ($doc->lines as $line) {
            $dist = Distribution::merge($this->ruleDistribution('purchase', ['product_id' => $line->product_id, 'category_id' => $products[$line->product_id] ?? null, 'partner_id' => $doc->partner_id]), $doc->analytic, $line->analytic);
            $full = Distribution::fullAxes($dist);
            foreach ($required as $axis) {
                if (! in_array($axis->id, $full, true)) {
                    throw new \DomainException('Ventilez la ligne « '.$line->description.' » à 100 % sur l\'axe « '.$axis->name.' » (axe obligatoire pour les achats).');
                }
            }
        }
    }

    public function assertExpenseAxes(object $e): void
    {
        $required = Axis::where('required', true)->where('active', true)->get(['id', 'name']);
        if ($required->isEmpty()) {
            return;
        }
        $dept = DB::table('rh_employees')->where('id', $e->employee_id)->value('department');
        $full = Distribution::fullAxes(Distribution::merge($this->ruleDistribution('expense', ['expense_category_id' => $e->category_id, 'department' => $dept]), $e->analytic));
        foreach ($required as $axis) {
            if (! in_array($axis->id, $full, true)) {
                throw new \DomainException('Choisissez la section « '.$axis->name.' » de cette dépense (axe obligatoire).');
            }
        }
    }

    // ------------------------------------------------------------------
    // États
    // ------------------------------------------------------------------

    /** Totaux « à ventiler » de la période (ventes HT, achats HT, frais, coût de la paie). */
    public function bases(Company $company, string $from, string $to): array
    {
        $sales = 0.0;
        foreach (DB::table('trade_documents')->where('company_id', $company->id)->where('direction', 'sale')->where('status', 'validated')->where('is_opening', false)
            ->whereNull('deleted_at')->whereIn('type', array_keys(self::SALE_SIGNS))->whereBetween('date', [$from, $to])
            ->selectRaw('type, SUM(subtotal * exchange_rate) s')->groupBy('type')->get() as $r) {
            $sales += self::SALE_SIGNS[$r->type] * (float) $r->s;
        }
        $cost = (float) DB::table('trade_documents')->where('company_id', $company->id)->where('direction', 'purchase')->where('status', 'validated')->where('is_opening', false)
            ->whereNull('deleted_at')->where('type', 'supplier_invoice')->whereBetween('date', [$from, $to])->sum(DB::raw('subtotal * exchange_rate'));
        if (Schema::hasTable('nf_expenses')) {
            $cost += (float) DB::table('nf_expenses')->where('company_id', $company->id)->whereIn('status', ['approved', 'paid'])->whereNull('deleted_at')->whereBetween('date', [$from, $to])->sum('amount');
        }
        if (Schema::hasTable('paie_runs')) {
            $cost += (float) DB::table('paie_payslips')->join('paie_runs', 'paie_runs.id', '=', 'paie_payslips.run_id')
                ->where('paie_runs.company_id', $company->id)->whereIn('paie_runs.status', ['validated', 'paid'])
                ->whereBetween('paie_runs.period', [substr($from, 0, 7), substr($to, 0, 7)])->sum('paie_payslips.cost');
        }

        if (Schema::hasTable('im_lines')) {
            $cost += (float) DB::table('im_lines')->where('company_id', $company->id)->whereNotNull('posted_at')->where('kind', 'normal')->whereBetween('period_end', [$from, $to])->sum('amount');
        }

        return ['revenue' => round($sales, 2), 'cost' => round($cost, 2)];
    }

    public function report(Company $company, string $from, string $to, ?string $axisId = null): array
    {
        $axes = Axis::orderBy('sort')->orderBy('name')->get();
        $axis = $axisId ? $axes->firstWhere('id', $axisId) : ($axes->firstWhere('active', true) ?? $axes->first());
        if (! $axis) {
            return ['axes' => [], 'axis' => null, 'sections' => [], 'months' => [], 'totals' => null];
        }
        $sections = Section::withTrashed()->where('axis_id', $axis->id)->get()->keyBy('id');
        $sums = AnLine::where('axis_id', $axis->id)->whereBetween('date', [$from, $to])
            ->selectRaw('section_id, SUM('.self::REV.') rev, SUM('.self::COST.') cost, COUNT(*) n')
            ->groupBy('section_id')->get()->keyBy('section_id');

        $rows = [];
        foreach ($sections as $s) {
            $x = $sums->get($s->id);
            if (! $x && (! $s->active || $s->trashed())) {
                continue;
            }
            $rev = round((float) ($x->rev ?? 0), 2);
            $cost = round((float) ($x->cost ?? 0), 2);
            $rows[] = [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'parent_id' => $s->parent_id, 'active' => $s->active && ! $s->trashed(),
                'project_id' => $s->project_id, 'revenue' => $rev, 'cost' => $cost, 'margin' => round($rev - $cost, 2),
                'margin_rate' => $rev > 0 ? round(($rev - $cost) / $rev * 100, 1) : null, 'lines' => (int) ($x->n ?? 0),
            ];
        }
        // Les sections parentes cumulent leurs sous-sections
        $byId = collect($rows)->keyBy('id');
        $rollup = function (string $id) use (&$rollup, $byId): array {
            $r = $byId[$id];
            $rev = $r['revenue'];
            $cost = $r['cost'];
            foreach ($byId->where('parent_id', $id) as $child) {
                [$cr, $cc] = $rollup($child['id']);
                $rev += $cr;
                $cost += $cc;
            }

            return [$rev, $cost];
        };
        foreach ($rows as &$r) {
            if ($byId->where('parent_id', $r['id'])->isNotEmpty()) {
                [$rev, $cost] = $rollup($r['id']);
                $r['total_revenue'] = round($rev, 2);
                $r['total_cost'] = round($cost, 2);
                $r['total_margin'] = round($rev - $cost, 2);
            }
        }
        unset($r);
        usort($rows, fn ($a, $b) => [$a['code'] ?? 'zzz', $a['name']] <=> [$b['code'] ?? 'zzz', $b['name']]);

        $allocRev = round($sums->sum('rev'), 2);
        $allocCost = round($sums->sum('cost'), 2);
        $base = $this->bases($company, $from, $to);
        $manual = AnLine::where('axis_id', $axis->id)->where('source_type', 'manual')->whereBetween('date', [$from, $to])
            ->selectRaw('SUM('.self::REV.') rev, SUM('.self::COST.') cost')->first();

        // Évolution mensuelle
        $monthExpr = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', date)" : "DATE_FORMAT(date, '%Y-%m')";
        $months = AnLine::where('axis_id', $axis->id)->whereBetween('date', [$from, $to])
            ->selectRaw("$monthExpr m, SUM(".self::REV.') rev, SUM('.self::COST.') cost')
            ->groupBy('m')->orderBy('m')->get()->map(fn ($m) => ['month' => $m->m, 'revenue' => round((float) $m->rev, 2), 'cost' => round((float) $m->cost, 2)])->all();

        return [
            'axes' => $axes->map->only(['id', 'name', 'code', 'system', 'required', 'active'])->values(),
            'axis' => $axis->only(['id', 'name', 'code', 'system', 'required']),
            'sections' => $rows,
            'months' => $months,
            'totals' => [
                'revenue' => $allocRev, 'cost' => $allocCost, 'margin' => round($allocRev - $allocCost, 2),
                'base_revenue' => $base['revenue'], 'base_cost' => $base['cost'],
                'unallocated_revenue' => round(max(0, $base['revenue'] - ($allocRev - (float) ($manual->rev ?? 0))), 2),
                'unallocated_cost' => round(max(0, $base['cost'] - ($allocCost - (float) ($manual->cost ?? 0))), 2),
            ],
        ];
    }

    public function sectionDetail(Company $company, Section $section, string $from, string $to): array
    {
        $ids = $this->descendants($section->id);
        $q = AnLine::whereIn('section_id', $ids)->whereBetween('date', [$from, $to]);
        $names = Chart::ACCOUNTS;
        if (Schema::hasTable('compta_accounts')) {
            $names = DB::table('compta_accounts')->where('company_id', $company->id)->pluck('name', 'code')->all() + $names;
        }
        $accounts = (clone $q)->selectRaw('account_code, SUM(amount) total, COUNT(*) n')->groupBy('account_code')->get()
            ->map(fn ($a) => ['code' => $a->account_code, 'name' => $a->account_code ? ($names[$a->account_code] ?? $this->accountName($a->account_code)) : 'Sans compte', 'amount' => round((float) $a->total, 2), 'lines' => (int) $a->n])
            ->sortBy('code')->values();
        $monthExpr = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', date)" : "DATE_FORMAT(date, '%Y-%m')";
        $months = (clone $q)->selectRaw("$monthExpr m, SUM(".self::REV.') rev, SUM('.self::COST.') cost')
            ->groupBy('m')->orderBy('m')->get()->map(fn ($m) => ['month' => $m->m, 'revenue' => round((float) $m->rev, 2), 'cost' => round((float) $m->cost, 2)]);
        $lines = (clone $q)->orderByDesc('date')->orderByDesc('id')->limit(300)->get(['id', 'date', 'amount', 'account_code', 'source_type', 'source_id', 'label', 'percent', 'section_id']);

        return [
            'section' => $section->only(['id', 'code', 'name', 'axis_id', 'parent_id', 'project_id']),
            'accounts' => $accounts,
            'months' => $months,
            'lines' => $lines,
            'revenue' => round((float) (clone $q)->selectRaw('SUM('.self::REV.') v')->value('v'), 2),
            'cost' => round((float) (clone $q)->selectRaw('SUM('.self::COST.') v')->value('v'), 2),
        ];
    }

    /** La section et toutes ses sous-sections. */
    public function descendants(string $id): array
    {
        $all = Section::withTrashed()->get(['id', 'parent_id'])->groupBy('parent_id');
        $out = [$id];
        $stack = [$id];
        while ($stack) {
            $cur = array_pop($stack);
            foreach ($all->get($cur, collect()) as $child) {
                if (! in_array($child->id, $out, true)) {
                    $out[] = $child->id;
                    $stack[] = $child->id;
                }
            }
        }

        return $out;
    }

    public function accountName(string $code): string
    {
        $ref = app()->bound('tenant') ? \App\Modules\Compta\Referentials::of(app('tenant')) : 'syscohada';
        $groups = \App\Modules\Compta\Referentials::groups($ref);
        $all = $groups['produits'] + $groups['charges'];
        $g = \App\Modules\Compta\Referentials::group($all, $code);

        return $g !== null ? $all[$g] : 'Compte '.$code;
    }
}
