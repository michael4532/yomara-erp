<?php

namespace App\Modules\Pilotage;

use App\Models\Company;
use App\Models\User;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs de gestion calculés à partir de l'activité (sans attendre la comptabilité) :
 * soldes intermédiaires de gestion par mois, prévision des ventes, seuil de rentabilité, rentabilité.
 */
class PilotageService
{
    public const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public const MONTH_OPTIONS = ['1' => 'Janvier', '2' => 'Février', '3' => 'Mars', '4' => 'Avril', '5' => 'Mai', '6' => 'Juin', '7' => 'Juillet', '8' => 'Août',
        '9' => 'Septembre', '10' => 'Octobre', '11' => 'Novembre', '12' => 'Décembre'];

    /** Catégories des opérations de trésorerie → postes de gestion. */
    public const OPS = ['external' => ['rent', 'utilities', 'telecom', 'supplies', 'transport', 'maintenance', 'fees', 'other_expense', 'expense_report'],
        'personnel' => ['salaries', 'social'], 'taxes' => ['taxes'], 'financial' => ['bank_fees', 'interest']];

    /** Lignes de la cascade des soldes intermédiaires de gestion. */
    public const SIG = [
        ['revenue', 'Chiffre d\'affaires HT', 'line'],
        ['cogs', 'Coût d\'achat des marchandises vendues', 'minus'],
        ['gross_margin', 'Marge brute', 'total'],
        ['external', 'Charges externes (services, loyers, énergie, transport…)', 'minus'],
        ['value_added', 'Valeur ajoutée', 'total'],
        ['personnel', 'Charges de personnel', 'minus'],
        ['taxes', 'Impôts et taxes', 'minus'],
        ['ebitda', 'Excédent brut d\'exploitation (EBE)', 'total'],
        ['financial', 'Frais financiers (intérêts, frais bancaires)', 'minus'],
        ['result', 'Résultat avant amortissements et impôt', 'total'],
    ];

    protected Company $c;

    protected function forCompany(Company $c): static
    {
        $this->c = $c;

        return $this;
    }

    protected function sales(string $from, string $to)
    {
        return SalesDocument::query()->where('status', 'validated')->whereIn('type', ['invoice', 'receipt', 'credit_note'])
            ->whereBetween('date', [$from, $to])->where(fn ($w) => $w->whereNull('is_opening')->orWhere('is_opening', false));
    }

    /** Montants par mois « AAAA-MM » pour chaque poste, sur la période. */
    public function monthly(Company $c, string $from, string $to): array
    {
        $this->forCompany($c);
        $m = fn () => [];
        $out = ['revenue' => [], 'cogs' => [], 'covered' => [], 'external' => [], 'personnel' => [], 'taxes' => [], 'financial' => []];
        $sign = "(CASE WHEN d.type IN ('credit_note', 'supplier_credit') THEN -1 ELSE 1 END)";
        foreach ($this->sales($from, $to)->selectRaw("substr(date, 1, 7) as ym, SUM((CASE WHEN type = 'credit_note' THEN -1 ELSE 1 END) * subtotal * COALESCE(exchange_rate, 1)) as v")
            ->groupBy('ym')->toBase()->get() as $r) {
            $out['revenue'][$r->ym] = (float) $r->v;
        }
        $ids = $this->sales($from, $to)->select('id');
        foreach (DB::table('trade_document_lines as l')->join('trade_documents as d', 'd.id', '=', 'l.document_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereIn('l.document_id', $ids)->where('p.purchase_price', '>', 0)
            ->selectRaw("substr(d.date, 1, 7) as ym, SUM($sign * l.quantity * p.purchase_price) as cost, SUM($sign * l.subtotal * COALESCE(d.exchange_rate, 1)) as base")
            ->groupBy('ym')->get() as $r) {
            $out['cogs'][$r->ym] = (float) $r->cost;
            $out['covered'][$r->ym] = (float) $r->base;
        }
        // achats de services et frais (lignes sans article) ; les marchandises passent par le coût des ventes
        if ($c->hasModule('achats')) {
            $pids = \App\Modules\Achats\Models\PurchaseDocument::query()->where('status', 'validated')->whereIn('type', ['supplier_invoice', 'supplier_credit'])->whereBetween('date', [$from, $to])->select('id');
            foreach (DB::table('trade_document_lines as l')->join('trade_documents as d', 'd.id', '=', 'l.document_id')->whereIn('l.document_id', $pids)->whereNull('l.product_id')
                ->selectRaw("substr(d.date, 1, 7) as ym, SUM($sign * l.subtotal * COALESCE(d.exchange_rate, 1)) as v")->groupBy('ym')->get() as $r) {
                $out['external'][$r->ym] = ($out['external'][$r->ym] ?? 0) + (float) $r->v;
            }
        }
        // salaires : coût employeur des bulletins validés (sinon les salaires payés en trésorerie)
        $payroll = [];
        if ($c->hasModule('paie') && \Illuminate\Support\Facades\Schema::hasTable('paie_payslips')) {
            foreach (DB::table('paie_payslips as s')->join('paie_runs as r', 'r.id', '=', 's.run_id')->where('r.company_id', $c->id)->whereIn('r.status', ['validated', 'paid'])
                ->whereBetween('r.period', [substr($from, 0, 7), substr($to, 0, 7)])->selectRaw('r.period as ym, SUM(s.cost) as v')->groupBy('r.period')->get() as $r) {
                $payroll[$r->ym] = (float) $r->v;
            }
        }
        if ($c->hasModule('tresorerie')) {
            foreach (DB::table('tr_operations')->where('company_id', $c->id)->whereNull('deleted_at')->whereNull('transfer_id')->where('amount', '<', 0)->whereBetween('date', [$from, $to])
                ->selectRaw('substr(date, 1, 7) as ym, category, SUM(amount) as v')->groupBy('ym', 'category')->get() as $r) {
                foreach (self::OPS as $post => $cats) {
                    if (in_array($r->category, $cats, true) && ! ($post === 'personnel' && isset($payroll[$r->ym]))) {
                        $out[$post][$r->ym] = ($out[$post][$r->ym] ?? 0) + abs((float) $r->v);
                    }
                }
            }
        }
        foreach ($payroll as $ym => $v) {
            $out['personnel'][$ym] = $v;
        }

        return $out;
    }

    /** Cascade des soldes à partir des postes. */
    public static function cascade(array $v): array
    {
        $g = fn ($k) => round((float) ($v[$k] ?? 0), 2);
        $r = ['revenue' => $g('revenue'), 'cogs' => $g('cogs'), 'external' => $g('external'), 'personnel' => $g('personnel'), 'taxes' => $g('taxes'), 'financial' => $g('financial')];
        $r['gross_margin'] = $r['revenue'] - $r['cogs'];
        $r['value_added'] = $r['gross_margin'] - $r['external'];
        $r['ebitda'] = $r['value_added'] - $r['personnel'] - $r['taxes'];
        $r['result'] = $r['ebitda'] - $r['financial'];
        $r['fixed'] = $r['external'] + $r['personnel'] + $r['taxes'] + $r['financial'];
        $r['covered'] = $g('covered');

        return $r;
    }

    protected static function months(string $from, string $to): array
    {
        $out = [];
        for ($d = Carbon::parse($from)->startOfMonth(); $d->lte(Carbon::parse($to)); $d->addMonth()) {
            $out[] = $d->format('Y-m');
        }

        return $out;
    }

    protected static function pick(array $series, string $ym): array
    {
        return array_map(fn ($s) => $s[$ym] ?? 0, $series);
    }

    protected static function sum(array $series, array $months): array
    {
        $t = [];
        foreach ($series as $k => $s) {
            $t[$k] = array_sum(array_map(fn ($m) => $s[$m] ?? 0, $months));
        }

        return $t;
    }

    public static function monthLabel(string $ym): string
    {
        return ucfirst(self::MONTHS[(int) substr($ym, 5, 2) - 1]).' '.substr($ym, 0, 4);
    }

    /** Synthèse : cascade de la période, comparaison N-1, détail mois par mois. */
    public function overview(Company $c, string $from, string $to): array
    {
        $months = self::months($from, $to);
        $cur = $this->monthly($c, $from, $to);
        $pf = Carbon::parse($from)->subYear()->toDateString();
        $pt = Carbon::parse($to)->subYear()->toDateString();
        $prev = $this->monthly($c, $pf, $pt);
        $now = self::cascade(self::sum($cur, $months));
        $before = self::cascade(self::sum($prev, self::months($pf, $pt)));
        $lines = [];
        foreach (self::SIG as [$key, $label, $kind]) {
            $lines[] = ['key' => $key, 'label' => $label, 'kind' => $kind, 'value' => $now[$key], 'previous' => $before[$key],
                'rate' => $now['revenue'] ? round($now[$key] / $now['revenue'] * 100, 1) : null,
                'change' => abs($before[$key]) > 0.01 ? round(($now[$key] - $before[$key]) / abs($before[$key]) * 100, 1) : null];
        }
        $rows = [];
        foreach ($months as $ym) {
            $x = self::cascade(self::pick($cur, $ym));
            $rows[] = ['month' => $ym, 'label' => self::monthLabel($ym), 'revenue' => $x['revenue'], 'gross_margin' => $x['gross_margin'], 'fixed' => $x['fixed'], 'ebitda' => $x['ebitda'], 'result' => $x['result'],
                'previous_revenue' => (float) ($prev['revenue'][Carbon::parse($ym.'-01')->subYear()->format('Y-m')] ?? 0)];
        }

        return [
            'from' => $from, 'to' => $to, 'lines' => $lines, 'months' => $rows,
            'margin_rate' => $now['revenue'] ? round($now['gross_margin'] / $now['revenue'] * 100, 1) : null,
            'ebitda_rate' => $now['revenue'] ? round($now['ebitda'] / $now['revenue'] * 100, 1) : null,
            // part du CA dont le coût d'achat est connu (articles avec prix d'achat)
            'cost_coverage' => $now['revenue'] > 0 ? round(min(100, $now['covered'] / $now['revenue'] * 100)) : null,
            'sources' => array_values(array_filter(['ventes', $c->hasModule('achats') ? 'achats' : null, $c->hasModule('tresorerie') ? 'tresorerie' : null, $c->hasModule('paie') ? 'paie' : null])),
        ];
    }

    /** Objectifs mensuels de l'année : mensuels saisis, sinon objectif annuel réparti selon la saisonnalité. */
    public function targets(Company $c, int $year, array $season): array
    {
        $rows = DB::table('pl_targets')->where('company_id', $c->id)->where('year', $year)->get();
        $out = array_fill(1, 12, null);
        foreach ($rows->whereNotNull('month') as $t) {
            $out[(int) $t->month] = (float) $t->revenue;
        }
        if ($annual = $rows->whereNull('month')->first()) {
            $free = array_keys(array_filter($out, fn ($v) => $v === null));
            $left = max(0, (float) $annual->revenue - array_sum(array_filter($out)));
            $w = array_sum(array_map(fn ($m) => $season[$m] ?? 1, $free)) ?: 1;
            foreach ($free as $m) {
                $out[$m] = round($left * ($season[$m] ?? 1) / $w, 2);
            }
        }

        return $out;
    }

    /** Prévision des ventes sur 12 mois : tendance (régression) × saisonnalité, fourchette à 80 %, atterrissage de l'année. */
    public function forecast(Company $c): array
    {
        $today = now();
        $start = $today->copy()->startOfMonth()->subMonths(24);
        $series = $this->monthly($c, $start->toDateString(), $today->toDateString())['revenue'];
        $hist = [];
        for ($d = $start->copy(); $d->lt($today->copy()->startOfMonth()); $d->addMonth()) {
            $hist[$d->format('Y-m')] = (float) ($series[$d->format('Y-m')] ?? 0);
        }
        // ne garder l'historique qu'à partir du premier mois actif
        while ($hist && reset($hist) <= 0) {
            array_shift($hist);
        }
        $vals = array_values($hist);
        $n = count($vals);
        $mean = $n ? array_sum($vals) / $n : 0;
        $season = array_fill(1, 12, 1.0);
        if ($n >= 12 && $mean > 0) {
            $by = [];
            foreach ($hist as $ym => $v) {
                $by[(int) substr($ym, 5, 2)][] = $v;
            }
            foreach ($by as $m => $vs) {
                // un seul relevé pour ce mois : coefficient rapproché de 1 (moins de bruit)
                $raw = max(0.5, min(2, (array_sum($vs) / count($vs)) / $mean));
                $season[$m] = 1 + ($raw - 1) * min(1, count($vs) / 2);
            }
            $norm = array_sum($season) / 12;
            foreach ($season as $m => $s) {
                $season[$m] = $s / $norm;
            }
        }
        // tendance sur les 12 derniers mois désaisonnalisés
        $keys = array_slice(array_keys($hist), -12);
        $pts = [];
        foreach ($keys as $i => $ym) {
            $pts[] = [$i, $hist[$ym] / $season[(int) substr($ym, 5, 2)]];
        }
        [$a, $b] = self::regression($pts);
        // niveau = moyenne désaisonnalisée des 12 derniers mois ; tendance plafonnée à ±3 % du niveau par mois
        $level = $pts ? array_sum(array_column($pts, 1)) / count($pts) : 0;
        $b = max(-0.03 * $level, min(0.03 * $level, $b));
        $center = (count($pts) - 1) / 2;
        $a = $level - $b * $center;
        $resid = [];
        foreach ($keys as $i => $ym) {
            $resid[] = $hist[$ym] - max(0, ($a + $b * $i) * $season[(int) substr($ym, 5, 2)]);
        }
        $sigma = count($resid) > 2 ? sqrt(array_sum(array_map(fn ($r) => $r * $r, $resid)) / (count($resid) - 2)) : $mean * 0.25;
        $base = count($keys);
        $actualNow = (float) ($series[$today->format('Y-m')] ?? 0);
        $fc = [];
        for ($k = 0; $k < 12; $k++) {
            $d = $today->copy()->startOfMonth()->addMonths($k);
            $v = max(0, ($a + $b * ($base + $k)) * $season[$d->month]);
            if ($n < 3) {
                $v = $mean; // trop peu d'historique : moyenne simple
            }
            $fc[] = ['month' => $d->format('Y-m'), 'label' => self::monthLabel($d->format('Y-m')), 'value' => round($v, 2),
                'low' => round(max(0, $v - 1.28 * $sigma), 2), 'high' => round($v + 1.28 * $sigma, 2), 'actual' => $k === 0 ? $actualNow : null];
        }
        // atterrissage de l'année en cours
        $year = $today->year;
        $done = 0.0;
        foreach ($series as $ym => $v) {
            if (str_starts_with($ym, $year.'-') && $ym < $today->format('Y-m')) {
                $done += $v;
            }
        }
        $rest = 0.0;
        foreach ($fc as $i => $f) {
            if (str_starts_with($f['month'], $year.'-')) {
                $rest += $i === 0 ? max($actualNow, $f['value']) : $f['value'];
            }
        }
        $targets = $this->targets($c, $year, $season);
        $hasTarget = array_filter($targets, fn ($v) => $v !== null) !== [];
        $annual = $hasTarget ? array_sum(array_filter($targets)) : null;
        $ytdTarget = $hasTarget ? array_sum(array_filter($targets, fn ($v, $m) => $m <= $today->month && $v !== null, ARRAY_FILTER_USE_BOTH)) : null;
        $prevYear = (float) array_sum(array_filter($this->monthly($c, ($year - 1).'-01-01', ($year - 1).'-12-31')['revenue']));

        return [
            'history' => array_map(fn ($ym, $v) => ['month' => $ym, 'label' => self::monthLabel($ym), 'value' => $v], array_keys($hist), $vals),
            'forecast' => $fc,
            'season' => array_map(fn ($s) => round($s, 2), $season),
            'trend_monthly' => round($b, 2),
            'trend_pct' => $mean > 0 ? round($b / $mean * 100, 1) : null,
            'months_of_history' => $n,
            'year' => $year,
            'ytd' => round($done + $actualNow, 2),
            'landing' => round($done + $rest, 2),
            'previous_year' => round($prevYear, 2),
            'targets' => array_values($targets),
            'annual_target' => $annual,
            'ytd_target' => $ytdTarget,
            'target_gap' => $annual !== null ? round($done + $rest - $annual, 2) : null,
        ];
    }

    protected static function regression(array $pts): array
    {
        $n = count($pts);
        if ($n === 0) {
            return [0.0, 0.0];
        }
        if ($n === 1) {
            return [$pts[0][1], 0.0];
        }
        $sx = $sy = $sxy = $sxx = 0.0;
        foreach ($pts as [$x, $y]) {
            $sx += $x;
            $sy += $y;
            $sxy += $x * $y;
            $sxx += $x * $x;
        }
        $den = $n * $sxx - $sx * $sx;
        $b = $den ? ($n * $sxy - $sx * $sy) / $den : 0.0;

        return [($sy - $b * $sx) / $n, $b];
    }

    /** Seuil de rentabilité (moyenne des 3 derniers mois complets) et point mort du mois et de l'année. */
    public function breakeven(Company $c, int $window = 3): array
    {
        $to = now()->startOfMonth()->subDay();
        $from = $to->copy()->startOfMonth()->subMonths($window - 1);
        $months = self::months($from->toDateString(), $to->toDateString());
        $s = self::cascade(self::sum($this->monthly($c, $from->toDateString(), $to->toDateString()), $months));
        $k = max(1, count($months));
        $avg = ['revenue' => $s['revenue'] / $k, 'cogs' => $s['cogs'] / $k, 'gross_margin' => $s['gross_margin'] / $k, 'fixed' => $s['fixed'] / $k,
            'external' => $s['external'] / $k, 'personnel' => $s['personnel'] / $k, 'taxes' => $s['taxes'] / $k, 'financial' => $s['financial'] / $k, 'result' => $s['result'] / $k];
        $rate = $avg['revenue'] > 0 ? $avg['gross_margin'] / $avg['revenue'] : null;
        $be = $rate && $rate > 0 ? $avg['fixed'] / $rate : null;
        // mois en cours
        $today = now();
        $mtd = (float) ($this->monthly($c, $today->copy()->startOfMonth()->toDateString(), $today->toDateString())['revenue'][$today->format('Y-m')] ?? 0);
        $pace = $mtd / max(1, $today->day);
        $day = $be && $pace > 0 ? (int) ceil($be / $pace) : null;
        // année : jour où la marge cumulée couvre les charges fixes cumulées
        $ystart = $today->copy()->startOfYear();
        $ytd = self::cascade(self::sum($this->monthly($c, $ystart->toDateString(), $today->toDateString()), self::months($ystart->toDateString(), $today->toDateString())));
        $annualBe = $be !== null ? $be * 12 : null;
        $yearDate = null;
        if ($be && $rate) {
            $daily = $avg['revenue'] / 30.4;
            $remaining = max(0, $annualBe - $ytd['revenue']);
            $yearDate = $ytd['revenue'] >= $annualBe ? 'atteint' : ($daily > 0 ? $today->copy()->addDays((int) ceil($remaining / $daily))->toDateString() : null);
        }

        return [
            'window' => count($months), 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'average' => array_map(fn ($v) => round($v, 2), $avg),
            'margin_rate' => $rate !== null ? round($rate * 100, 1) : null,
            'breakeven' => $be !== null ? round($be, 2) : null,
            'safety_margin' => $be && $avg['revenue'] > 0 ? round(($avg['revenue'] - $be) / $avg['revenue'] * 100, 1) : null,
            'month_to_date' => round($mtd, 2),
            'breakeven_day' => $day,
            'breakeven_day_label' => $day === null ? null : ($day <= $today->daysInMonth ? 'le '.$day.' '.self::MONTHS[$today->month - 1] : 'pas atteint ce mois-ci au rythme actuel'),
            'annual_breakeven' => $annualBe !== null ? round($annualBe, 2) : null,
            'ytd_revenue' => round($ytd['revenue'], 2),
            'year_breakeven_date' => $yearDate,
        ];
    }

    /** Rentabilité par client, article ou catégorie : CA, coût d'achat, marge et taux. */
    public function profitability(Company $c, string $by, string $from, string $to): array
    {
        $this->forCompany($c);
        $ids = $this->sales($from, $to)->select('id');
        $sign = "(CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END)";
        $q = DB::table('trade_document_lines as l')->join('trade_documents as d', 'd.id', '=', 'l.document_id')->leftJoin('products as p', 'p.id', '=', 'l.product_id')->whereIn('l.document_id', $ids);
        $key = match ($by) {
            'product' => 'l.product_id',
            'category' => 'p.category_id',
            default => 'd.partner_id',
        };
        $rows = $q->selectRaw("$key as k, SUM($sign * l.subtotal * COALESCE(d.exchange_rate, 1)) as revenue,
                SUM(CASE WHEN p.purchase_price > 0 THEN $sign * l.quantity * p.purchase_price ELSE 0 END) as cost,
                SUM(CASE WHEN p.purchase_price > 0 THEN $sign * l.subtotal * COALESCE(d.exchange_rate, 1) ELSE 0 END) as covered,
                SUM($sign * l.quantity) as qty, COUNT(DISTINCT d.id) as docs")
            ->groupBy($key)->get();
        $names = match ($by) {
            'product' => DB::table('products')->whereIn('id', $rows->pluck('k')->filter())->pluck('name', 'id'),
            'category' => DB::table('product_categories')->whereIn('id', $rows->pluck('k')->filter())->pluck('name', 'id'),
            default => DB::table('partners')->whereIn('id', $rows->pluck('k')->filter())->pluck('name', 'id'),
        };
        $empty = ['product' => 'Lignes libres (sans article)', 'category' => 'Sans catégorie', 'customer' => 'Client de passage'][$by] ?? '—';
        $list = $rows->map(fn ($r) => [
            'id' => $r->k, 'name' => $r->k ? ($names[$r->k] ?? '—') : $empty, 'revenue' => round((float) $r->revenue, 2), 'cost' => round((float) $r->cost, 2),
            'margin' => round((float) $r->revenue - (float) $r->cost, 2), 'quantity' => round((float) $r->qty, 2), 'documents' => (int) $r->docs,
            'margin_rate' => (float) $r->revenue > 0 ? round(((float) $r->revenue - (float) $r->cost) / (float) $r->revenue * 100, 1) : null,
            'cost_known' => (float) $r->revenue > 0 ? round(min(100, (float) $r->covered / (float) $r->revenue * 100)) : null,
        ])->sortByDesc('revenue')->values();
        $tot = ['revenue' => round($list->sum('revenue'), 2), 'cost' => round($list->sum('cost'), 2)];
        $tot['margin'] = round($tot['revenue'] - $tot['cost'], 2);
        $tot['margin_rate'] = $tot['revenue'] > 0 ? round($tot['margin'] / $tot['revenue'] * 100, 1) : null;
        $acc = 0.0;
        $pareto = 0;
        foreach ($list as $r) {
            $acc += $r['margin'];
            $pareto++;
            if ($tot['margin'] > 0 && $acc >= 0.8 * $tot['margin']) {
                break;
            }
        }

        return ['by' => $by, 'from' => $from, 'to' => $to, 'rows' => $list->take(100)->all(), 'count' => $list->count(), 'totals' => $tot,
            // nombre d'éléments qui font 80 % de la marge
            'pareto' => $tot['margin'] > 0 ? $pareto : null,
            'losses' => $list->filter(fn ($r) => $r['cost_known'] >= 90 && $r['margin'] < 0)->count()];
    }

    // ------------------------------------------------------------------ objectifs (fiches)

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        $c = app('tenant');
        $year = (int) ($data['year'] ?? $old['year']);
        $month = array_key_exists('month', $data) ? ($data['month'] === null || $data['month'] === '' ? null : (int) $data['month']) : ($old['month'] ?? null);
        if ($year < 2000 || $year > 2100) {
            throw new \DomainException('Année invalide.');
        }
        $dup = DB::table('pl_targets')->where('company_id', $c->id)->where('year', $year)->when($month, fn ($q) => $q->where('month', $month), fn ($q) => $q->whereNull('month'))
            ->when($old, fn ($q) => $q->where('id', '!=', $old['id']))->exists();
        if ($dup) {
            throw new \DomainException('Un objectif existe déjà pour cette période : modifiez-le.');
        }
        $data['month'] = $month;
        $data['title'] = $month ? ucfirst(self::MONTHS[$month - 1]).' '.$year : 'Année '.$year;

        return $data;
    }

    public function present(string $res, array $row): array
    {
        $c = app('tenant');
        $from = $row['month'] ? sprintf('%04d-%02d-01', $row['year'], $row['month']) : $row['year'].'-01-01';
        $to = $row['month'] ? Carbon::parse($from)->endOfMonth()->toDateString() : $row['year'].'-12-31';
        $v = array_sum($this->monthly($c, $from, min($to, now()->toDateString()))['revenue']);
        $row['actual'] = round($v, 2);
        $row['achieved'] = $from > now()->toDateString() ? null : number_format($v, 0, ',', ' ').((float) $row['revenue'] > 0 ? ' · '.round($v / (float) $row['revenue'] * 100).' %' : '');

        return $row;
    }

    public function dashboard(Company $c): array
    {
        $ms = now()->startOfMonth()->toDateString();
        $x = self::cascade(self::pick($this->monthly($c, $ms, now()->toDateString()), now()->format('Y-m')));

        return ['ebitda_month' => $x['ebitda'], 'margin_month' => $x['gross_margin']];
    }
}
