<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Modules\Ventes\Models\SalesDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord analytique : chiffres clés et leurs évolutions, courbe sur 12 mois comparée à l'année précédente,
 * indicateurs de pilotage, score de santé et alertes intelligentes, performance par agence (ou par client),
 * répartition du chiffre d'affaires. Les requêtes passent par les modèles : société et agences de l'utilisateur respectées.
 */
class InsightService
{
    public const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    protected Company $c;

    /** Documents de vente comptant dans le chiffre d'affaires (factures, tickets, avoirs validés). */
    protected function sales(string $from, string $to)
    {
        return SalesDocument::query()->where('status', 'validated')->whereIn('type', ['invoice', 'receipt', 'credit_note'])
            ->whereBetween('date', [$from, $to])->where(fn ($w) => $w->whereNull('is_opening')->orWhere('is_opening', false));
    }

    protected function revenue(string $from, string $to): float
    {
        return round((float) $this->sales($from, $to)->sum(DB::raw("(CASE WHEN type = 'credit_note' THEN -1 ELSE 1 END) * subtotal * COALESCE(exchange_rate, 1)")), 2);
    }

    /** Marge brute sur les articles qui ont un prix d'achat : [marge, CA couvert]. */
    protected function margin(string $from, string $to): array
    {
        $ids = $this->sales($from, $to)->select('id');
        $r = DB::table('trade_document_lines as l')->join('trade_documents as d', 'd.id', '=', 'l.document_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereIn('l.document_id', $ids)->where('p.purchase_price', '>', 0)
            ->selectRaw("SUM((CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END) * l.subtotal * COALESCE(d.exchange_rate, 1)) base,
                SUM((CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END) * l.quantity * p.purchase_price) cost")->first();

        return [round((float) ($r->base ?? 0) - (float) ($r->cost ?? 0), 2), round((float) ($r->base ?? 0), 2)];
    }

    protected function expenses(string $from, string $to): float
    {
        $v = 0.0;
        if ($this->c->hasModule('achats')) {
            $v += (float) \App\Modules\Achats\Models\PurchaseDocument::query()->where('status', 'validated')->whereIn('type', ['supplier_invoice', 'supplier_credit'])
                ->whereBetween('date', [$from, $to])->sum(DB::raw("(CASE WHEN type = 'supplier_credit' THEN -1 ELSE 1 END) * subtotal * COALESCE(exchange_rate, 1)"));
        }
        if ($this->c->hasModule('tresorerie')) {
            // Dépenses directes de caisse ou de banque (hors virements internes et règlements de factures)
            $v += abs((float) DB::table('tr_operations')->where('company_id', $this->c->id)->whereNull('deleted_at')->whereNull('transfer_id')->where('amount', '<', 0)
                ->whereBetween('date', [$from, $to])->sum('amount'));
        }

        return round($v, 2);
    }

    protected function cashflow(string $from, string $to): float
    {
        $in = (float) DB::table('payments')->where('company_id', $this->c->id)->whereNull('deleted_at')->where('direction', 'sale')->whereBetween('date', [$from, $to])->sum('amount');
        $out = (float) DB::table('payments')->where('company_id', $this->c->id)->whereNull('deleted_at')->where('direction', 'purchase')->whereBetween('date', [$from, $to])->sum('amount');

        return round($in - $out - $this->opsOut($from, $to), 2);
    }

    protected function opsOut(string $from, string $to): float
    {
        return $this->c->hasModule('tresorerie') ? abs((float) DB::table('tr_operations')->where('company_id', $this->c->id)->whereNull('deleted_at')->whereNull('transfer_id')
            ->where('amount', '<', 0)->whereBetween('date', [$from, $to])->sum('amount')) : 0.0;
    }

    protected function pct(float $now, float $before): ?float
    {
        return abs($before) > 0.5 ? round(($now - $before) * 100 / abs($before), 1) : null;
    }

    protected function money(float $v): string
    {
        return number_format($v, 0, ',', ' ').' F';
    }

    public function build(Company $c, ?User $u): array
    {
        $this->c = $c;
        $now = now();
        $m0 = $now->copy()->startOfMonth();
        $today = $now->toDateString();
        $d = fn (Carbon $x) => $x->toDateString();
        // Mois en cours (à date) comparé au même nombre de jours du mois précédent et du même mois l'an dernier
        $dayN = $now->day;
        $prevStart = $m0->copy()->subMonthNoOverflow();
        $prevTo = $prevStart->copy()->addDays(min($dayN, $prevStart->daysInMonth) - 1);
        $lyStart = $m0->copy()->subYear();
        $lyTo = $lyStart->copy()->addDays(min($dayN, $lyStart->daysInMonth) - 1);

        $rev = $this->revenue($d($m0), $today);
        $revPrev = $this->revenue($d($prevStart), $d($prevTo));
        $revLy = $this->revenue($d($lyStart), $d($lyTo));
        $ytd = $this->revenue($d($now->copy()->startOfYear()), $today);
        $ytdLy = $this->revenue($d($now->copy()->subYear()->startOfYear()), $d($now->copy()->subYear()));
        [$mg, $mgBase] = $this->margin($d($m0), $today);
        [$mgPrev, $mgBasePrev] = $this->margin($d($prevStart), $d($prevTo));
        $marginRate = $mgBase > 0 ? round($mg * 100 / $mgBase, 1) : null;
        $marginRatePrev = $mgBasePrev > 0 ? round($mgPrev * 100 / $mgBasePrev, 1) : null;
        $exp = $this->expenses($d($m0), $today);
        $exp3 = [];
        for ($i = 1; $i <= 3; $i++) {
            $s = $m0->copy()->subMonthsNoOverflow($i);
            $exp3[] = $this->expenses($d($s), $d($s->copy()->addDays(min($dayN, $s->daysInMonth) - 1)));
        }
        $expAvg = array_sum($exp3) / 3;
        $flow30 = $this->cashflow($d($now->copy()->subDays(29)), $today);
        $flowPrev = $this->cashflow($d($now->copy()->subDays(59)), $d($now->copy()->subDays(30)));
        $cash = null;
        if ($c->hasModule('tresorerie')) {
            $svc = app(\App\Modules\Tresorerie\TreasuryService::class);
            $cash = round(\App\Modules\Tresorerie\Models\TreasuryAccount::where('active', true)->get()->sum(fn ($a) => $svc->balance($a)));
        }

        // Courbe sur 12 mois glissants, comparée à l'année précédente
        $series = [];
        for ($i = 11; $i >= 0; $i--) {
            $s = $m0->copy()->subMonthsNoOverflow($i);
            $e = $i === 0 ? $now->copy() : $s->copy()->endOfMonth();
            $series[] = ['month' => $s->format('Y-m'), 'label' => self::MONTHS[$s->month - 1], 'current' => $this->revenue($d($s), $d($e)),
                'previous' => $this->revenue($d($s->copy()->subYear()), $d($s->copy()->subYear()->endOfMonth()))];
        }

        // Créances
        $open = SalesDocument::query()->where('status', 'validated')->where('type', 'invoice')->where('payment_status', '!=', 'paid');
        $receivables = round((float) (clone $open)->sum(DB::raw('(total - amount_paid) * COALESCE(exchange_rate, 1)')), 2);
        $overdue = round((float) (clone $open)->whereDate('due_date', '<', $today)->sum(DB::raw('(total - amount_paid) * COALESCE(exchange_rate, 1)')), 2);
        $over60 = (clone $open)->whereDate('due_date', '<', $d($now->copy()->subDays(60)))->selectRaw('COUNT(DISTINCT partner_id) n, SUM((total - amount_paid) * COALESCE(exchange_rate, 1)) v')->first();
        $billed90 = (float) SalesDocument::query()->where('status', 'validated')->where('type', 'invoice')->whereBetween('date', [$d($now->copy()->subDays(89)), $today])->sum(DB::raw('total * COALESCE(exchange_rate, 1)'));
        $paid90 = (float) SalesDocument::query()->where('status', 'validated')->where('type', 'invoice')->whereBetween('date', [$d($now->copy()->subDays(89)), $today])->sum(DB::raw('amount_paid * COALESCE(exchange_rate, 1)'));
        $collection = $billed90 > 0 ? round($paid90 * 100 / $billed90, 1) : null;
        $dso = $billed90 > 0 ? (int) round($receivables / ($billed90 / 90)) : null;
        $lowStock = $c->hasModule('stock') ? (int) collect(app(\App\Modules\Stock\Dashboard::class)($c))->firstWhere('key', 'low_stock')['value'] : 0;

        // Score de santé (100 = excellent)
        $score = 100;
        $notes = [];
        if ($receivables > 0) {
            $penalty = (int) round(min(30, $overdue * 30 / $receivables));
            $score -= $penalty;
            if ($penalty >= 12) {
                $notes[] = 'les retards de paiement clients';
            }
        }
        if ($cash !== null && $expAvg > 0 && $cash < $expAvg) {
            $score -= 20;
            $notes[] = 'la trésorerie, inférieure à un mois de dépenses';
        }
        if ($marginRate !== null && $marginRate < 15) {
            $score -= 15;
            $notes[] = 'la marge brute';
        }
        if ($ytdLy > 0 && $ytd < $ytdLy) {
            $score -= 15;
            $notes[] = 'le chiffre d\'affaires, en recul sur un an';
        }
        if ($expAvg > 0 && $exp > $expAvg * 1.15) {
            $score -= 8;
            $notes[] = 'les dépenses du mois';
        }
        if ($lowStock > 0) {
            $score -= min(7, $lowStock);
        }
        $score = max(5, min(100, $score));
        $summary = ($score >= 80 ? 'Activité solide' : ($score >= 60 ? 'Situation correcte' : 'Points de vigilance importants'))
            .($notes ? ' ; à surveiller : '.implode(', ', array_slice($notes, 0, 2)).'.' : ' : chiffre d\'affaires, marge et encaissements sont au bon niveau.');

        // Alertes intelligentes
        $alerts = [];
        if ($over60 && (float) $over60->v > 0) {
            $alerts[] = ['level' => 'critical', 'title' => 'Créances anciennes', 'text' => $this->money((float) $over60->v).' de factures échues depuis plus de 60 jours chez '.$over60->n.' client(s) : risque sur la trésorerie.', 'target' => 'relances'];
        }
        if ($cash !== null && $expAvg > 0 && $cash < $expAvg) {
            $alerts[] = ['level' => 'critical', 'title' => 'Trésorerie tendue', 'text' => 'La trésorerie disponible ('.$this->money($cash).') couvre moins d\'un mois de dépenses moyennes ('.$this->money($expAvg).').', 'target' => 'tresorerie'];
        }
        if ($expAvg > 0 && $exp > $expAvg * 1.1) {
            $alerts[] = ['level' => 'warning', 'title' => 'Dépenses en hausse', 'text' => 'Les dépenses du mois dépassent de '.round(($exp / $expAvg - 1) * 100).' % la moyenne des trois mois précédents à la même date.', 'target' => 'achats'];
        }
        if ($lowStock > 0) {
            $alerts[] = ['level' => 'warning', 'title' => 'Ruptures à prévoir', 'text' => $lowStock.' article(s) sous le seuil d\'alerte de stock : pensez à commander.', 'target' => 'stock'];
        }
        // Catégorie d'articles qui progresse le plus sur le mois
        $byCat = fn (string $f, string $t) => DB::table('trade_document_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereIn('l.document_id', $this->sales($f, $t)->select('id'))->groupBy('p.category_id')->selectRaw('p.category_id c, SUM(l.subtotal) v')->pluck('v', 'c');
        $catNow = $byCat($d($m0), $today);
        $catPrev = $byCat($d($prevStart), $d($prevTo));
        $best = null;
        foreach ($catNow as $cat => $v) {
            $p = (float) ($catPrev[$cat] ?? 0);
            if ($cat && $p > 0 && $v > $p * 1.1 && (! $best || ($v - $p) > $best[1])) {
                $best = [$cat, $v - $p, round(($v / $p - 1) * 100)];
            }
        }
        if ($best) {
            $name = DB::table('product_categories')->where('id', $best[0])->value('name');
            $alerts[] = ['level' => 'pattern', 'title' => 'Tendance : '.$name, 'text' => 'Les ventes de « '.$name.' » progressent de '.$best[2].' % par rapport au mois dernier à la même date (+'.$this->money($best[1]).' HT).', 'target' => 'ventes'];
        }
        if ($ytdLy > 0 && $ytd >= $ytdLy) {
            $alerts[] = ['level' => 'ok', 'title' => 'Objectif annuel en bonne voie', 'text' => 'Chiffre d\'affaires depuis janvier : '.$this->money($ytd).', soit +'.round(($ytd / $ytdLy - 1) * 100, 1).' % par rapport à la même période l\'an dernier.', 'target' => 'rapports'];
        } elseif ($collection !== null && $collection >= 80) {
            $alerts[] = ['level' => 'ok', 'title' => 'Bons encaissements', 'text' => $collection.' % des factures des 90 derniers jours sont déjà encaissées.', 'target' => 'ventes'];
        }

        // Performance par agence (multi-agences) ou par client
        $table = ['kind' => 'customers', 'rows' => []];
        if ($c->hasModule('agences') && \App\Modules\Agences\Models\Branch::count() > 1) {
            $svc = app(\App\Modules\Agences\BranchService::class);
            $cur = collect($svc->summary($c, $d($now->copy()->startOfYear()), $today)['rows']);
            $prev = collect($svc->summary($c, $d($now->copy()->subYear()->startOfYear()), $d($now->copy()->subYear()))['rows'])->keyBy('id');
            $table = ['kind' => 'branches', 'rows' => $cur->filter(fn ($r) => $r['id'])->map(function ($r) use ($prev) {
                $g = $this->pct((float) $r['sales'], (float) ($prev[$r['id']]['sales'] ?? 0));
                $status = ($r['margin_rate'] ?? 100) < 10 ? 'Marge faible' : ((float) $r['receivables'] > (float) $r['sales'] * .3 ? 'Impayés' : ($g !== null && $g > 10 ? 'En forte hausse' : 'Bon niveau'));

                return ['name' => $r['name'], 'revenue' => (float) $r['sales'], 'margin_rate' => $r['margin_rate'], 'growth' => $g, 'status' => $status,
                    'tone' => match ($status) { 'Marge faible' => 'warning', 'Impayés' => 'critical', 'En forte hausse' => 'pattern', default => 'ok' }];
            })->sortByDesc('revenue')->values()->all()];
        } else {
            $from = $d($now->copy()->startOfYear());
            $rows = $this->sales($from, $today)->whereNotNull('partner_id')->groupBy('partner_id')
                ->selectRaw("partner_id, SUM((CASE WHEN type = 'credit_note' THEN -1 ELSE 1 END) * subtotal * COALESCE(exchange_rate, 1)) v")->orderByDesc('v')->limit(6)->get();
            $prev = $this->sales($d($now->copy()->subYear()->startOfYear()), $d($now->copy()->subYear()))->whereIn('partner_id', $rows->pluck('partner_id'))->groupBy('partner_id')
                ->selectRaw("partner_id, SUM((CASE WHEN type = 'credit_note' THEN -1 ELSE 1 END) * subtotal * COALESCE(exchange_rate, 1)) v")->pluck('v', 'partner_id');
            $names = DB::table('partners')->whereIn('id', $rows->pluck('partner_id'))->pluck('name', 'id');
            $late = SalesDocument::query()->where('status', 'validated')->where('type', 'invoice')->where('payment_status', '!=', 'paid')->whereDate('due_date', '<', $today)
                ->whereIn('partner_id', $rows->pluck('partner_id'))->groupBy('partner_id')->selectRaw('partner_id, SUM(total - amount_paid) v')->pluck('v', 'partner_id');
            $table['rows'] = $rows->map(function ($r) use ($ytd, $prev, $names, $late) {
                $g = $this->pct((float) $r->v, (float) ($prev[$r->partner_id] ?? 0));
                $status = ($late[$r->partner_id] ?? 0) > 0 ? 'Retard de paiement' : ($g !== null && $g > 10 ? 'En forte hausse' : ($g !== null && $g < -10 ? 'En baisse' : 'Bon payeur'));

                return ['name' => $names[$r->partner_id] ?? '—', 'revenue' => round((float) $r->v, 2), 'share' => $ytd > 0 ? round((float) $r->v * 100 / $ytd, 1) : null, 'growth' => $g,
                    'status' => $status, 'tone' => match ($status) { 'Retard de paiement' => 'critical', 'En baisse' => 'warning', 'En forte hausse' => 'pattern', default => 'ok' }];
            })->values()->all();
        }

        // Répartition du chiffre d'affaires de l'année par catégorie d'articles
        $mix = DB::table('trade_document_lines as l')->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->join('trade_documents as d', 'd.id', '=', 'l.document_id')
            ->whereIn('l.document_id', $this->sales($d($now->copy()->startOfYear()), $today)->select('id'))
            ->groupByRaw("CASE WHEN l.product_id IS NULL THEN 'Prestations & services' ELSE COALESCE(pc.name, 'Articles non classés') END")
            ->selectRaw("CASE WHEN l.product_id IS NULL THEN 'Prestations & services' ELSE COALESCE(pc.name, 'Articles non classés') END n, SUM((CASE WHEN d.type = 'credit_note' THEN -1 ELSE 1 END) * l.subtotal * COALESCE(d.exchange_rate, 1)) v")->orderByDesc('v')->get();
        $parts = $mix->take(5)->map(fn ($r) => ['label' => $r->n ?: 'Sans catégorie', 'value' => round((float) $r->v, 2)])->all();
        if ($mix->count() > 5) {
            $parts[] = ['label' => 'Autres', 'value' => round((float) $mix->slice(5)->sum('v'), 2)];
        }
        $growthByCat = collect($catNow)->map(fn ($v, $k) => $this->pct((float) $v, (float) ($catPrev[$k] ?? 0)))->filter(fn ($v) => $v !== null)->sortDesc();
        $fastest = $growthByCat->keys()->first();

        // Marge, dépenses et trésorerie : réservées à la direction et aux finances (pas à un commercial ou un caissier)
        $finance = ! $u || $u->is_super_admin || collect(['rapports.view', 'rapports.manage', 'compta.view', 'compta.manage', 'tresorerie.view', 'tresorerie.manage', 'core.settings.manage'])
            ->contains(fn ($p) => $u->hasPermission($p));
        if (! $finance) {
            $alerts = array_values(array_filter($alerts, fn ($a) => ! in_array($a['target'] ?? '', ['achats', 'tresorerie', 'compta'], true)));
        }

        return [
            'currency' => $c->currency,
            'finance' => $finance,
            'period' => ['from' => $d($m0), 'to' => $today, 'label' => self::MONTHS[$now->month - 1].' '.$now->year],
            'kpis' => [
                'revenue' => ['value' => $rev, 'vs_last_year' => $this->pct($rev, $revLy), 'vs_last_month' => $this->pct($rev, $revPrev)],
                'margin' => $finance ? ['value' => $mg, 'rate' => $marginRate, 'rate_change' => $marginRate !== null && $marginRatePrev !== null ? round($marginRate - $marginRatePrev, 1) : null] : null,
                'expenses' => $finance ? ['value' => $exp, 'vs_average' => $this->pct($exp, $expAvg)] : null,
                'cash' => $finance ? ['value' => $cash, 'flow_30' => $flow30, 'flow_change' => $this->pct($flow30, $flowPrev)] : null,
            ],
            'series' => $series,
            'year' => ['ytd' => $ytd, 'ytd_last' => $ytdLy, 'vs_last_year' => $this->pct($ytd, $ytdLy)],
            'gauges' => array_values(array_filter([
                $ytdLy > 0 ? ['label' => 'Réalisé vs année dernière', 'value' => round($ytd * 100 / $ytdLy), 'unit' => '%', 'progress' => min(1, $ytd / $ytdLy), 'tone' => $ytd >= $ytdLy ? 'primary' : 'warning'] : null,
                $finance && $marginRate !== null ? ['label' => 'Marge brute', 'value' => $marginRate, 'unit' => '%', 'progress' => min(1, max(0, $marginRate / 50)), 'tone' => $marginRate >= 20 ? 'ok' : 'warning'] : null,
                $collection !== null ? ['label' => 'Encaissement à 90 jours', 'value' => $collection, 'unit' => '%', 'progress' => min(1, $collection / 100), 'tone' => $collection >= 75 ? 'ok' : 'warning'] : null,
                $dso !== null ? ['label' => 'Délai moyen de paiement', 'value' => $dso, 'unit' => ' j', 'progress' => min(1, $dso / 90), 'tone' => $dso <= 45 ? 'ok' : ($dso <= 75 ? 'warning' : 'critical')] : null,
            ])),
            'health' => ['score' => $score, 'summary' => $summary, 'receivables' => $receivables, 'overdue' => $overdue],
            'alerts' => array_slice($alerts, 0, 5),
            'table' => $table,
            'mix' => ['parts' => $parts, 'largest' => $parts[0]['label'] ?? null,
                'fastest' => $fastest ? DB::table('product_categories')->where('id', $fastest)->value('name') : null, 'fastest_growth' => $fastest ? $growthByCat->first() : null],
        ];
    }
}
