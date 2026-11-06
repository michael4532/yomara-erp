<?php

namespace App\Modules\Compta;

use App\Models\Company;
use App\Modules\Compta\Models\GroupMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consolidation des comptes d'un groupe (société mère + filiales de la même installation).
 *
 * Chaque filiale autorise la mère par un code de rattachement. Méthodes : intégration globale (IG), intégration
 * proportionnelle (IP), mise en équivalence (MEE). Les soldes de chaque société sont traduits dans le plan de la mère,
 * convertis au cours indiqué, cumulés, puis retraités : élimination des créances / dettes et des ventes / achats entre
 * sociétés du groupe (reconnues par leur NCC ou leur raison sociale), élimination des titres contre les capitaux propres,
 * écart d'acquisition, intérêts minoritaires, quote-part des sociétés mises en équivalence.
 */
class Consolidation
{
    public const METHODS = ['ig' => 'Intégration globale', 'ip' => 'Intégration proportionnelle', 'mee' => 'Mise en équivalence'];

    public const SUMMARY_LABELS = [
        'result' => 'Résultat consolidé', 'minority_result' => 'dont part des minoritaires', 'group_result' => 'Résultat net part du groupe', 'group_equity' => 'Capitaux propres part du groupe',
        'minority_interests_total' => 'Intérêts minoritaires (y compris leur part du résultat)', 'minority_interests' => 'dont hors résultat de l\'exercice', 'goodwill' => 'Écarts d\'acquisition (net)', 'goodwill_charge' => 'Amortissement et dépréciation des écarts d\'acquisition',
        'translation_difference' => 'Écarts de conversion (part du groupe)', 'equity_method_result' => 'Quote-part des sociétés mises en équivalence',
        'intercompany_sales' => 'Ventes internes éliminées', 'intercompany_purchases' => 'Achats internes éliminés', 'intercompany_receivables' => 'Créances internes éliminées',
        'intercompany_payables' => 'Dettes internes éliminées', 'intercompany_dividends' => 'Dividendes internes éliminés', 'stock_margin' => 'Marges internes sur stocks éliminées (nettes des reprises)',
        'deferred_tax' => 'Impôts différés actifs sur retraitements', 'deferred_tax_rate' => 'Taux d\'impôt différé',
    ];

    public function __construct(protected Statements $st) {}

    protected function assert(bool $ok, string $msg): void
    {
        if (! $ok) {
            throw new \DomainException($msg);
        }
    }

    // ------------------------------------------------------------------------------------------------ rattachement

    /** Code à communiquer à la société mère (valable 7 jours). */
    public function linkCode(Company $member): array
    {
        DB::table('compta_group_links')->where('company_id', $member->id)->delete();
        $code = strtoupper(Str::random(4).'-'.Str::random(4));
        DB::table('compta_group_links')->insert(['company_id' => $member->id, 'code' => $code, 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

        return ['code' => $code, 'expires_at' => now()->addDays(7)->toDateTimeString()];
    }

    public function attach(Company $parent, string $code, array $d): GroupMember
    {
        $link = DB::table('compta_group_links')->where('code', strtoupper(trim($code)))->where('expires_at', '>', now())->first();
        $this->assert((bool) $link, 'Code de rattachement inconnu ou expiré : demandez à la filiale d\'en générer un nouveau (Comptabilité › Consolidation).');
        $this->assert((int) $link->company_id !== $parent->id, 'Une société ne peut pas se rattacher à elle-même.');
        $this->assert(! GroupMember::where('member_id', $parent->id)->exists(), 'Cette société est elle-même filiale d\'un groupe.');
        $member = Company::findOrFail($link->company_id);
        $this->assert($member->hasModule('compta'), 'La comptabilité n\'est pas active chez '.$member->name.'.');
        $this->assert(! GroupMember::where('member_id', $member->id)->where('parent_id', '!=', $parent->id)->exists(), $member->name.' est déjà consolidée par un autre groupe.');
        $g = GroupMember::updateOrCreate(['parent_id' => $parent->id, 'member_id' => $member->id], $this->clean($d) + ['interest_pct' => 100, 'control_pct' => 100, 'method' => 'ig']);
        DB::table('compta_group_links')->where('id', $link->id)->delete();

        return $g->fresh();
    }

    public function clean(array $d): array
    {
        $out = [];
        foreach (['interest_pct', 'control_pct'] as $k) {
            if (isset($d[$k])) {
                $out[$k] = max(0, min(100, (float) $d[$k]));
            }
        }
        if (isset($d['method'])) {
            $this->assert(isset(self::METHODS[$d['method']]), 'Méthode inconnue.');
            $out['method'] = $d['method'];
        }
        foreach (['investment_cost', 'equity_at_acquisition'] as $k) {
            if (array_key_exists($k, $d)) {
                $out[$k] = round((float) $d[$k], 2);
            }
        }
        if (array_key_exists('acquisition_date', $d)) {
            $out['acquisition_date'] = $d['acquisition_date'] ?: null;
        }
        if (array_key_exists('goodwill_years', $d)) {
            $out['goodwill_years'] = $d['goodwill_years'] ? max(1, min(40, (int) $d['goodwill_years'])) : null;
        }
        if (array_key_exists('goodwill_impairment', $d)) {
            $out['goodwill_impairment'] = max(0, round((float) $d['goodwill_impairment'], 2));
        }
        if (array_key_exists('goodwill_impairment_date', $d)) {
            $out['goodwill_impairment_date'] = $d['goodwill_impairment_date'] ?: null;
        }
        if (array_key_exists('exit_date', $d)) {
            $out['exit_date'] = $d['exit_date'] ?: null;
        }
        if (array_key_exists('exit_proceeds', $d)) {
            $out['exit_proceeds'] = $d['exit_proceeds'] === null || $d['exit_proceeds'] === '' ? null : max(0, round((float) $d['exit_proceeds'], 2));
        }
        foreach (['rate', 'average_rate', 'historical_rate'] as $k) {
            if (array_key_exists($k, $d)) {
                $out[$k] = $d[$k] === null || $d[$k] === '' || (float) $d[$k] <= 0 ? null : (float) $d[$k];
            }
        }

        return $out;
    }

    /** Achat complémentaire (+) ou cession partielle (−) de titres d'une filiale. */
    public function addStake(Company $parent, GroupMember $g, array $d): GroupMember
    {
        $date = Carbon::parse($d['date'])->toDateString();
        $this->assert(! $g->acquisition_date || $date > $g->acquisition_date->toDateString(), 'La variation doit être postérieure à la date d\'acquisition.');
        $this->assert(! $g->exit_date || $date < $g->exit_date->toDateString(), 'La filiale est sortie du périmètre à cette date.');
        $this->assert((float) $d['change_pct'] != 0, 'Indiquez le nombre de points achetés (+) ou cédés (−).');
        [$before] = $g->stakeAt($date);
        $after = $before + (float) $d['change_pct'];
        $this->assert($after > 0 && $after <= 100, 'Le pourcentage après l\'opération doit être compris entre 0 et 100 % (pour une cession totale, indiquez la sortie du périmètre).');
        $this->assert(! $this->isFrozenAt($parent, $date), 'Les comptes consolidés de cette période sont arrêtés : rouvrez-les avant d\'enregistrer l\'opération.');
        $g->stakes()->create(['date' => $date, 'change_pct' => round((float) $d['change_pct'], 3), 'amount' => round((float) ($d['amount'] ?? 0), 2),
            'cost_basis' => isset($d['cost_basis']) && $d['cost_basis'] !== '' && $d['cost_basis'] !== null ? round((float) $d['cost_basis'], 2) : null, 'note' => $d['note'] ?? null]);
        return $g->fresh(['member', 'stakes']);
    }

    protected function isFrozenAt(Company $parent, string $date): bool
    {
        return DB::table('compta_group_snapshots')->where('parent_id', $parent->id)->where('period_from', '<=', $date)->where('period_to', '>=', $date)->exists();
    }

    public function members(Company $parent): array
    {
        return GroupMember::with('member')->where('parent_id', $parent->id)->orderBy('id')->get()->map(fn ($g) => $this->memberToApi($parent, $g))->values()->all();
    }

    public function memberToApi(Company $parent, GroupMember $g): array
    {
        $m = $g->member;

        return $g->only(['id', 'member_id', 'interest_pct', 'control_pct', 'method', 'investment_cost', 'equity_at_acquisition', 'rate', 'average_rate', 'historical_rate', 'goodwill_years', 'goodwill_impairment']) + [
            'acquisition_date' => $g->acquisition_date?->toDateString(), 'goodwill_impairment_date' => $g->goodwill_impairment_date?->toDateString(),
            'exit_date' => $g->exit_date?->toDateString(), 'exit_proceeds' => $g->exit_proceeds,
            'current_pct' => $g->stakeAt(now()->toDateString())[0], 'current_cost' => $g->stakeAt(now()->toDateString())[1],
            'stakes' => $g->stakes->map(fn ($x) => ['id' => $x->id, 'date' => $x->date->toDateString(), 'change_pct' => $x->change_pct, 'amount' => $x->amount,
                'cost_basis' => $x->change_pct < 0 ? $g->costBasisOf($x) : null, 'cost_basis_entered' => $x->cost_basis !== null, 'note' => $x->note,
                'pct_after' => $g->stakeAt($x->date->toDateString())[0]])->values()->all(), 'method_label' => self::METHODS[$g->method] ?? $g->method,
            'name' => $m?->name, 'ncc' => $m?->tax_id, 'currency' => $m?->currency, 'country' => $m?->country,
            'referential' => $m ? Referentials::LIST[Referentials::of($m)]['short'] : null, 'fiscal_start_month' => $m ? FiscalCalendar::startMonth($m) : null,
            'suggested_rate' => $m && $m->currency !== $parent->currency ? $this->rate($parent, $m, null) : 1.0,
            'foreign' => $m && $m->currency !== $parent->currency,
        ];
    }

    protected function rate(Company $parent, Company $member, ?float $rate): float
    {
        if ($rate && $rate > 0) {
            return $rate;
        }
        if ($member->currency === $parent->currency) {
            return 1.0;
        }
        $fx = app(\App\Modules\Devises\FxService::class);
        // cours de la devise de la filiale exprimé dans la monnaie de la mère
        $r = $fx->rate($parent, $member->currency);

        return $r ?: 1.0;
    }

    /**
     * Cours de conversion d'une filiale : clôture (bilan), moyen de la période (compte de résultat), historique (capitaux propres).
     * À défaut de saisie : cours du module Devises à la date de clôture, moyenne des cours enregistrés sur la période.
     */
    protected function rates(Company $parent, Company $member, ?GroupMember $g, string $from, string $to): array
    {
        if ($member->currency === $parent->currency) {
            return [1.0, 1.0, 1.0];
        }
        $fx = app(\App\Modules\Devises\FxService::class);
        $rc = ($g?->rate > 0) ? $g->rate : ($fx->rate($parent, $member->currency, $to) ?: 1.0);
        $ra = $g?->average_rate > 0 ? $g->average_rate : null;
        if (! $ra) {
            $ra = \App\Modules\Devises\Models\FxRate::withoutGlobalScopes()->where('company_id', $parent->id)->where('currency', strtoupper($member->currency))
                ->whereBetween('date', [$from, $to])->whereNull('deleted_at')->avg('rate');
            $ra = $ra ? (float) $ra : $rc;
        }
        $rh = $g?->historical_rate > 0 ? $g->historical_rate : $rc;

        return [(float) $rc, (float) $ra, (float) $rh];
    }

    /** Taux d'impôt différé du groupe (par défaut : taux d'impôt sur les bénéfices de la mère). */
    public static function deferredTaxRate(Company $parent): float
    {
        $r = data_get($parent->settings, 'compta.group.deferred_tax_rate');

        return $r === null ? (float) (Closing::fiscalSettings($parent)['is_rate'] ?? 0) : (float) $r;
    }

    protected static function deferredTaxCode(string $ref): string
    {
        return match ($ref) { 'pcg' => '4487', 'cgnc' => '3458', default => '4497' };
    }

    protected static function goodwillAmortCode(string $ref): string
    {
        return match ($ref) { 'pcg' => '2807', 'cgnc' => '28230', default => '2815' };
    }

    /** Amortissement cumulé de l'écart d'acquisition à une date (linéaire au prorata des jours depuis l'acquisition). */
    protected static function goodwillAmortAt(GroupMember $g, float $gw, string $at): float
    {
        if (! $g->goodwill_years || ! $g->acquisition_date || $gw <= 0 || $at < $g->acquisition_date->toDateString()) {
            return 0.0;
        }
        $days = $g->acquisition_date->diffInDays(Carbon::parse($at)) + 1;

        return round(min($gw, $gw * $days / (365.25 * $g->goodwill_years)), 2);
    }

    protected static function dividendCode(string $ref): string
    {
        return match ($ref) { 'pcg' => '761', 'cgnc' => '7321', default => '772' };
    }

    // ------------------------------------------------------------------------------------------------ calcul

    /** Soldes d'une société pour la période : cumul à la date (P&L antérieurs reclassés en report à nouveau) et mouvements. */
    protected function entityBalances(Company $c, string $from, string $to): array
    {
        $ref = Referentials::of($c);
        $classes = Referentials::resultClasses($ref);
        $cum = $this->st->balances($c, null, $to, false, $from);
        $mov = $this->st->balances($c, $from, $to, true);
        $prior = 0.0;
        foreach ($cum as $code => $v) {
            $code = (string) $code;
            if (in_array($code[0], $classes, true)) {
                $prior += $v - ($mov[$code] ?? 0);
                $cum[$code] = $mov[$code] ?? 0.0;
            }
        }
        $ran = Referentials::code($ref, '121');
        $cum[$ran] = ($cum[$ran] ?? 0) + $prior;

        return [$cum, $mov];
    }

    protected static function equityPrefixes(string $ref): array
    {
        return match ($ref) {
            'pcg' => ['10', '11', '12', '13', '14'],
            'cgnc' => ['111', '112', '113', '114', '115', '116', '118', '119', '13'],
            default => ['10', '11', '12', '13', '14', '15'],
        };
    }

    protected static function goodwillCode(string $ref): string
    {
        return match ($ref) { 'pcg' => '207', 'cgnc' => '2230', default => '215' };
    }

    protected static function norm(?string $s): string
    {
        return mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', \Illuminate\Support\Str::ascii((string) $s)));
    }

    /** Tiers d'une société qui représentent une autre société du groupe (même NCC ou même raison sociale). */
    protected function groupPartners(Company $c, array $companies): array
    {
        $out = [];
        $byNcc = [];
        $byName = [];
        foreach ($companies as $other) {
            if ($other->id === $c->id) {
                continue;
            }
            if ($other->tax_id) {
                $byNcc[self::norm($other->tax_id)] = $other->id;
            }
            $byName[self::norm($other->name)] = $other->id;
        }
        foreach (DB::table('partners')->where('company_id', $c->id)->whereNull('deleted_at')->get(['id', 'name', 'tax_id']) as $p) {
            $target = ($p->tax_id && isset($byNcc[self::norm($p->tax_id)])) ? $byNcc[self::norm($p->tax_id)] : ($byName[self::norm($p->name)] ?? null);
            if ($target) {
                $out[$p->id] = $target;
            }
        }

        return $out;
    }

    public function compute(Company $parent, string $from, string $to): array
    {
        $pRef = Referentials::of($parent);
        $m = Statements::def($pRef);
        // Une filiale acquise après la fin de la période n'est pas dans le périmètre (comparatif N-1)
        // ni une filiale sortie du périmètre avant le début de la période
        $members = GroupMember::with(['member', 'stakes'])->where('parent_id', $parent->id)->get()->filter(fn ($g) => $g->member
            && (! $g->acquisition_date || $g->acquisition_date->toDateString() <= $to) && (! $g->exit_date || $g->exit_date->toDateString() >= $from));
        $entities = collect([['company' => $parent, 'method' => 'ig', 'pct' => 1.0, 'g' => null, 'exit' => null]])
            ->merge($members->map(function ($g) use ($to) {
                $exit = $g->exit_date && $g->exit_date->toDateString() <= $to ? $g->exit_date->toDateString() : null;

                return ['company' => $g->member, 'method' => $g->method, 'pct' => $g->stakeAt($exit ?? $to)[0] / 100, 'g' => $g, 'exit' => $exit];
            }));
        // Les opérations avec une société sortie du périmètre ne sont plus des opérations internes
        $companies = $entities->filter(fn ($e) => ! $e['exit'])->pluck('company')->all();
        $perimeter = ['entry' => [], 'exit' => []];
        $miBy = [];
        $tdBy = [];
        $cum = [];
        $mov = [];
        $add = function (array &$arr, string $code, float $v) {
            $arr[$code] = round(($arr[$code] ?? 0) + $v, 2);
        };
        $adjust = [];   // retraitements et éliminations (montant positif = débit)
        $warnings = [];
        $rows = [];
        $mi = 0.0;      // intérêts minoritaires dans les capitaux propres (hors résultat de l'exercice)
        $td = 0.0;      // écarts de conversion, part du groupe
        $goodwillCharge = 0.0;
        $stockMargin = 0.0;
        $goodwillNet = 0.0;
        $deferredTax = 0.0;
        $taxRate = self::deferredTaxRate($parent) / 100;
        $dividends = 0.0;
        $ws = [];
        $miResult = 0.0;
        $meeResult = 0.0;
        $tr = fn (string $code, string $eRef) => $eRef === $pRef ? $code : Referentials::translate($code, $eRef, $pRef);
        $ic = ['receivable' => [], 'payable' => [], 'sales' => [], 'purchases' => []];

        foreach ($entities as $e) {
            /** @var Company $c */
            $c = $e['company'];
            $eRef = Referentials::of($c);
            [$rate, $avg, $hist] = $this->rates($parent, $c, $e['g'], $from, $to);
            $w = match ($e['method']) { 'ig' => 1.0, 'ip' => $e['pct'], default => 0.0 };
            $ws[$c->id] = ['w' => $w, 'p' => $e['pct'], 'ig' => $c->id !== $parent->id && $w > 0, 'ref' => $eRef, 'rate' => $rate, 'avg' => $avg];
            $upTo = $e['exit'] ?? $to;
            // Le résultat d'avant l'acquisition fait partie des capitaux propres acquis
            $fromE = $e['g']?->acquisition_date && $e['g']->acquisition_date->toDateString() > $from ? $e['g']->acquisition_date->toDateString() : $from;
            [$ec, $em] = $this->entityBalances($c, $fromE, $upTo);
            $classes = Referentials::resultClasses($eRef);
            $eqPrefixes = self::equityPrefixes($eRef);
            // Bilan au cours de clôture, gestion au cours moyen, capitaux propres au cours historique
            $conv = function (string $code) use ($classes, $eqPrefixes, $rate, $avg, $hist) {
                if (in_array($code[0], $classes, true)) {
                    return $avg;
                }
                foreach ($eqPrefixes as $p) {
                    if (str_starts_with($code, $p)) {
                        return $hist;
                    }
                }

                return $rate;
            };
            $ecC = [];
            foreach ($ec as $code => $v) {
                $ecC[(string) $code] = $v * $conv((string) $code);
            }
            $emC = [];
            foreach ($em as $code => $v) {
                $emC[(string) $code] = $v * $avg;
            }
            $tdE = round(array_sum($ecC), 2); // écart de conversion de la filiale (excédent de débits à créditer)
            $result = round(-array_sum(array_filter($emC, fn ($v, $k) => in_array(((string) $k)[0], $classes, true), ARRAY_FILTER_USE_BOTH)), 2);
            $equity = 0.0;
            $eqCodes = [];
            foreach ($ecC as $code => $v) {
                if (collect($eqPrefixes)->contains(fn ($p) => str_starts_with((string) $code, $p))) {
                    $equity -= $v;
                    $eqCodes[$tr((string) $code, $eRef)] = ($eqCodes[$tr((string) $code, $eRef)] ?? 0) + $v;
                }
            }
            $equity = round($equity, 2);
            if ($c->id !== $parent->id) {
                if (FiscalCalendar::startMonth($c) !== FiscalCalendar::startMonth($parent)) {
                    $warnings[] = $c->name.' : exercice décalé différent de la mère ; ses comptes sont pris sur les mêmes dates.';
                }
                if ($c->currency !== $parent->currency) {
                    $fmt = fn ($r) => rtrim(rtrim(number_format($r, 6, ',', ' '), '0'), ',');
                    $warnings[] = $c->name.' : comptes en '.$c->currency.' convertis en '.$parent->currency.' (clôture '.$fmt($rate).', moyen '.$fmt($avg).', historique '.$fmt($hist).').';
                }
            }
            $rows[] = ['company_id' => $c->id, 'name' => $c->name, 'method' => $e['method'], 'method_label' => self::METHODS[$e['method']], 'interest_pct' => round($e['pct'] * 100, 3),
                'currency' => $c->currency, 'rate' => $rate, 'average_rate' => $avg, 'historical_rate' => $hist, 'translation' => $tdE, 'referential' => Referentials::LIST[$eRef]['short'], 'equity' => $equity, 'result' => $result, 'weight' => $w,
                'exit_date' => $e['exit'], 'acquisition_date' => $e['g']?->acquisition_date?->toDateString()];
            $resCode = Referentials::code($pRef, '118');
            $nonCash = fn (string $code) => ! in_array($code[0], $classes, true) && ! collect($eqPrefixes)->contains(fn ($x) => str_starts_with($code, $x));
            // Résultat par tranche de pourcentage d'intérêts (achats ou cessions de titres en cours de période)
            $segments = [];
            if ($e['g']) {
                $cuts = $e['g']->stakes->filter(fn ($x) => $x->date->toDateString() > $fromE && $x->date->toDateString() <= $upTo)->map(fn ($x) => $x->date->toDateString())->unique()->values()->all();
                $start = $fromE;
                foreach (array_merge($cuts, [null]) as $cut) {
                    $end = $cut ? Carbon::parse($cut)->subDay()->toDateString() : $upTo;
                    $segMov = $cuts ? $this->st->balances($c, $start, $end, true) : $em;
                    $segments[] = ['p' => $e['g']->stakeAt($start)[0] / 100, 'r' => round(-array_sum(array_filter($segMov, fn ($v, $k) => in_array(((string) $k)[0], $classes, true), ARRAY_FILTER_USE_BOTH)) * $avg, 2)];
                    $start = $cut;
                }
            }
            // Sortie du périmètre en cours de période : résultat jusqu'à la date de sortie, bilan exclu, résultat de cession consolidé
            if ($e['exit']) {
                $g = $e['g'];
                $p = $e['pct'];
                $lines = [];
                foreach ($emC as $code => $v) {
                    if (in_array(((string) $code)[0], $classes, true)) {
                        $add($cum, $tr((string) $code, $eRef), $v * $w);
                        $add($mov, $tr((string) $code, $eRef), $v * $w);
                    }
                }
                $lines[] = [$resCode, round($w * $result, 2)];
                $share = round(array_sum(array_map(fn ($sg) => $sg['p'] * $sg['r'], $segments)), 2);
                if ($w > 0) {
                    $miResult += round(array_sum(array_map(fn ($sg) => ($w - $sg['p']) * $sg['r'], $segments)), 2);
                } else {
                    // mise en équivalence : seule la quote-part du résultat jusqu'à la sortie
                    $lines = [[Referentials::code($pRef, '771'), -$share], [$resCode, $share]];
                    foreach ($emC as $code => $v) {
                        if (in_array(((string) $code)[0], $classes, true)) {
                            $add($cum, $tr((string) $code, $eRef), -$v * $w);
                            $add($mov, $tr((string) $code, $eRef), -$v * $w);
                        }
                    }
                    $meeResult += $share;
                }
                if ($w > 0) {
                    foreach ($lines as [$code, $amount]) {
                        $add($cum, (string) $code, $amount);
                    }
                    $lines = [];
                } else {
                    $adjust[] = ['kind' => 'exit_result', 'label' => 'Quote-part du résultat de '.$c->name.' jusqu\'à sa sortie', 'pl' => true, 'lines' => $lines];
                }
                $eqAcq = round($g->equity_at_acquisition * $hist, 2);
                $goodwill = round($g->investment_cost - ($g->interest_pct / 100) * $eqAcq, 2);
                $gwNet = 0.0;
                if ($goodwill > 0) {
                    $before = Carbon::parse($from)->subDay()->toDateString();
                    $priorA = self::goodwillAmortAt($g, $goodwill, $before);
                    $curA = round(self::goodwillAmortAt($g, $goodwill, $upTo) - $priorA, 2);
                    $impDate = $g->goodwill_impairment_date?->toDateString();
                    $imp = $impDate && $impDate <= $upTo ? min((float) $g->goodwill_impairment, max(0, $goodwill - $priorA - $curA)) : 0.0;
                    $curI = $impDate && $impDate >= $from ? $imp : 0.0;
                    $gwNet = round($goodwill - $priorA - $curA - $imp, 2);
                    if ($curA + $curI > 0.004) {
                        $goodwillCharge += round($curA + $curI, 2);
                        $adjust[] = ['kind' => 'goodwill', 'label' => 'Amortissement de l\'écart d\'acquisition sur '.$c->name.' jusqu\'à sa sortie', 'pl' => true,
                            'lines' => [[Referentials::code($pRef, '681'), round($curA + $curI, 2)], [$resCode, -round($curA + $curI, 2)]]];
                    }
                }
                // Résultat de cession : prix − (quote-part de l'actif net à la sortie + écart d'acquisition net) au lieu de prix − coût des titres
                $na = 0.0;
                $exitBal = [];
                foreach ($this->st->balances($c, null, $upTo) as $code => $v) {
                    if ($nonCash((string) $code)) {
                        $na += $v * $rate;
                        if (! str_starts_with((string) $code, '5')) {
                            $exitBal[$tr((string) $code, $eRef)] = ($exitBal[$tr((string) $code, $eRef)] ?? 0) + $v * $rate * $w;
                        }
                    }
                }
                if ($gwNet > 0) {
                    $exitBal[self::goodwillCode($pRef)] = ($exitBal[self::goodwillCode($pRef)] ?? 0) + $gwNet;
                }
                $carry = round($p * $na + max(0, $gwNet), 2);
                $perimeter['exit'][] = ['company_id' => $c->id, 'balances' => $exitBal, 'carry' => $carry];
                $book = $g->stakeAt($upTo)[1];
                $diff = round($carry - $book, 2);
                $rows[array_key_last($rows)] += ['exit_proceeds' => $g->exit_proceeds, 'exit_gain' => round((float) $g->exit_proceeds - $carry, 2), 'exit_gain_parent' => round((float) $g->exit_proceeds - $book, 2)];
                if (abs($diff) >= 0.005) {
                    $adjust[] = ['kind' => 'exit', 'label' => 'Sortie de '.$c->name.' le '.Carbon::parse($upTo)->format('d/m/Y').' : valeur consolidée des titres cédés', 'pl' => true,
                        'lines' => [[Referentials::code($pRef, '81'), $diff], [$resCode, -$diff]]];
                }
                $warnings[] = $c->name.' sortie du périmètre le '.Carbon::parse($upTo)->format('d/m/Y').' : résultat intégré jusqu\'à cette date, ses opérations avec le groupe ne sont plus éliminées.';

                continue;
            }
            // Entrée dans le périmètre pendant la période (tableau des flux)
            if ($e['g']?->acquisition_date && $e['g']->acquisition_date->toDateString() >= $from && $w > 0) {
                $acqBal = [];
                $acqAt = $e['g']->acquisition_date->toDateString();
                foreach ($this->st->balances($c, null, $acqAt) as $code => $v) {
                    if ($nonCash((string) $code) && ! str_starts_with((string) $code, '5')) {
                        $acqBal[$tr((string) $code, $eRef)] = ($acqBal[$tr((string) $code, $eRef)] ?? 0) + $v * $rate * $w;
                    }
                }
                $perimeter['entry'][] = ['company_id' => $c->id, 'balances' => $acqBal, 'goodwill' => max(0, round($e['g']->investment_cost - ($e['g']->interest_pct / 100) * $e['g']->equity_at_acquisition * $hist, 2))];
            }
            if ($w > 0) {
                foreach ($ecC as $code => $v) {
                    $add($cum, $tr((string) $code, $eRef), $v * $w);
                }
                foreach ($emC as $code => $v) {
                    $add($mov, $tr((string) $code, $eRef), $v * $w);
                }
                if ($c->id !== $parent->id) {
                    $td += round($e['pct'] * $tdE, 2);
                    $mi += round(($w - $e['pct']) * $tdE, 2);
                    $tdBy[$c->id] = round($e['pct'] * $tdE, 2);
                    $miBy[$c->id] = round(($w - $e['pct']) * $tdE, 2);
                }
                // Opérations avec les autres sociétés du groupe
                $gp = $this->groupPartners($c, $companies);
                if ($gp) {
                    $t = Referentials::tiers($eRef);
                    $lines = DB::table('compta_entry_lines as l')->join('compta_entries as en', 'en.id', '=', 'l.entry_id')->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
                        ->where('l.company_id', $c->id)->where('en.date', '<=', $to)->where('en.kind', '!=', 'closing')->whereIn('l.partner_id', array_keys($gp))
                        ->groupBy('l.partner_id', 'a.code')->selectRaw('l.partner_id, a.code, SUM(l.debit - l.credit) s')->get();
                    foreach ($lines as $x) {
                        $amount = round((float) $x->s * $rate * $w, 2);
                        if (abs($amount) < 0.005) {
                            continue;
                        }
                        $code = $tr((string) $x->code, $eRef);
                        // Dividendes reçus d'une société du groupe : produit annulé, réserves reconstituées
                        if (str_starts_with((string) $x->code, self::dividendCode($eRef))) {
                            $dv = DB::table('compta_entry_lines as l')->join('compta_entries as en', 'en.id', '=', 'l.entry_id')->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
                                ->where('l.company_id', $c->id)->whereBetween('en.date', [$from, $to])->where('en.kind', '!=', 'closing')->where('l.partner_id', $x->partner_id)->where('a.code', $x->code)
                                ->selectRaw('SUM(l.credit - l.debit) s')->value('s');
                            $dv = round((float) $dv * $avg * $w, 2);
                            if (abs($dv) >= 0.005) {
                                $payer = collect($companies)->firstWhere('id', $gp[$x->partner_id]);
                                $adjust[] = ['kind' => 'intercompany_dividends', 'label' => 'Élimination des dividendes reçus de '.$payer?->name.' par '.$c->name, 'pl' => true,
                                    'lines' => [[$code, $dv], [Referentials::code($pRef, '118'), -$dv]]];
                                $dividends += $dv;
                            }

                            continue;
                        }
                        if (str_starts_with((string) $x->code, $t['customers']) || str_starts_with((string) $x->code, $t['suppliers'])) {
                            $adjust[] = ['kind' => 'intercompany_balance', 'label' => 'Élimination '.(str_starts_with((string) $x->code, $t['customers']) ? 'créance' : 'dette').' de '.$c->name.' envers une société du groupe', 'lines' => [[$code, -$amount]], 'pl' => false];
                            $ic[str_starts_with((string) $x->code, $t['customers']) ? 'receivable' : 'payable'][] = ['from' => $c->name, 'to' => collect($companies)->firstWhere('id', $gp[$x->partner_id])?->name, 'amount' => abs($amount)];
                        }
                    }
                    $docs = DB::table('trade_documents')->where('company_id', $c->id)->whereNull('deleted_at')->where('status', 'validated')->whereBetween('date', [$from, $to])->whereIn('partner_id', array_keys($gp))
                        ->whereIn('type', ['invoice', 'receipt', 'credit_note', 'supplier_invoice'])->get(['type', 'direction', 'partner_id', 'subtotal', 'exchange_rate']);
                    foreach ($docs as $d) {
                        $v = round((float) $d->subtotal * (float) ($d->exchange_rate ?: 1) * ($d->type === 'credit_note' ? -1 : 1) * $avg * $w, 2);
                        $ic[$d->direction === 'sale' ? 'sales' : 'purchases'][] = ['from' => $c->name, 'to' => collect($companies)->firstWhere('id', $gp[$d->partner_id])?->name, 'amount' => $v];
                    }
                }
            }
            if ($c->id === $parent->id) {
                continue;
            }
            // Titres de participation chez la mère contre capitaux propres de la filiale
            $g = $e['g'];
            $p = $e['pct'];
            $p0 = $g->interest_pct / 100;
            $book = $g->stakeAt($to)[1];                       // coût des titres dans les comptes de la mère
            $eqAcq = round($g->equity_at_acquisition * $hist, 2);
            $goodwill = round($g->investment_cost - $p0 * $eqAcq, 2); // sur la prise de contrôle initiale
            $reserves = round($p * $equity - $book + $goodwill, 2);   // part du groupe dans les capitaux propres depuis l'acquisition
            $titres = match ($pRef) { 'pcg' => '261', 'cgnc' => '2510', default => '26' };
            $share = round(array_sum(array_map(fn ($sg) => $sg['p'] * $sg['r'], $segments)), 2);
            // Achats et cessions de titres de la période : le résultat de cession de la mère est une opération entre associés (capitaux propres)
            foreach ($g->stakes as $x) {
                $d = $x->date->toDateString();
                if ($x->change_pct < 0 && $d >= $from && $d <= $to) {
                    $basis = $g->costBasisOf($x);
                    $adjust[] = ['kind' => 'stake_sale', 'label' => 'Cession de '.rtrim(rtrim(number_format(-$x->change_pct, 3, ',', ''), '0'), ',').' % de '.$c->name.' le '.$x->date->format('d/m/Y').' (sans perte de contrôle)', 'pl' => true,
                        'lines' => array_values(array_filter([[Referentials::code($pRef, '82'), round($x->amount, 2)], [Referentials::code($pRef, '81'), -$basis], [$resCode, -round($x->amount - $basis, 2)]], fn ($l) => abs($l[1]) >= 0.005))];
                }
            }
            if ($w > 0) {
                $lines = [];
                foreach ($eqCodes as $code => $v) {
                    $lines[] = [$code, -$v * $w]; // capitaux propres de la filiale retirés
                }
                $lines[] = [$titres, -$book];
                if ($goodwill > 0) {
                    $lines[] = [self::goodwillCode($pRef), $goodwill];
                }
                $lines[] = [$resCode, -round($p * $equity - $book + max(0, $goodwill), 2)]; // réserves consolidées (écart d'acquisition négatif inclus)
                $miPart = round(($w - $p) * $equity, 2);
                $mi += $miPart;
                $miBy[$c->id] = ($miBy[$c->id] ?? 0) + $miPart;
                $miResult += round(array_sum(array_map(fn ($sg) => ($w - $sg['p']) * $sg['r'], $segments)), 2);
                $adjust[] = ['kind' => 'equity', 'label' => 'Élimination des titres '.$c->name.' contre ses capitaux propres', 'lines' => $lines, 'pl' => false, 'mi' => $miPart];
                // Résultat d'avant la variation de pourcentage : transféré entre le groupe et les minoritaires
                $transfer = $e['method'] === 'ig' ? round(array_sum(array_map(fn ($sg) => ($sg['p'] - $p) * $sg['r'], $segments)), 2) : 0.0;
                if ($e['method'] === 'ip' && count($segments) > 1) {
                    $warnings[] = $c->name.' (intégration proportionnelle) : le pourcentage de fin de période est appliqué à tout l\'exercice.';
                }
                if (abs($transfer) >= 0.005) {
                    $mi += $transfer;
                    $miBy[$c->id] += $transfer;
                    $adjust[] = ['kind' => 'stake_transfer', 'label' => 'Variation du pourcentage d\'intérêts dans '.$c->name.' : résultat antérieur à la variation reclassé entre groupe et minoritaires', 'pl' => false,
                        'lines' => [[$resCode, $transfer]]];
                }
                // Écart d'acquisition : amortissement et dépréciation (part antérieure en réserves, part de la période en charge)
                if ($goodwill > 0) {
                    $before = Carbon::parse($from)->subDay()->toDateString();
                    $priorA = self::goodwillAmortAt($g, $goodwill, $before);
                    $curA = round(self::goodwillAmortAt($g, $goodwill, $to) - $priorA, 2);
                    $imp = min((float) $g->goodwill_impairment, max(0, $goodwill - $priorA - $curA));
                    $impDate = $g->goodwill_impairment_date?->toDateString();
                    [$priorI, $curI] = $imp > 0 && $impDate && $impDate <= $to ? ($impDate < $from ? [$imp, 0.0] : [0.0, $imp]) : [0.0, 0.0];
                    if ($priorA + $curA + $priorI + $curI > 0.004) {
                        $charge = round($curA + $curI, 2);
                        $goodwillCharge += $charge;
                        $goodwillNet -= round($priorA + $curA + $priorI + $curI, 2);
                        $adjust[] = ['kind' => 'goodwill', 'label' => 'Amortissement et dépréciation de l\'écart d\'acquisition sur '.$c->name, 'pl' => $charge > 0, 'lines' => array_values(array_filter([
                            [Referentials::code($pRef, '681'), $charge], [$resCode, round($priorA + $priorI, 2)], [self::goodwillAmortCode($pRef), -round($priorA + $curA + $priorI + $curI, 2)],
                        ], fn ($l) => abs($l[1]) >= 0.005))];
                    }
                    $goodwillNet += $goodwill;
                }
            } else {
                // Mise en équivalence : titres revalorisés à la quote-part des capitaux propres (écart d'acquisition inclus)
                $delta = round($p * ($equity + $tdE) - $book + $goodwill + $share, 2);
                $td += round($p * $tdE, 2);
                $tdBy[$c->id] = round($p * $tdE, 2);
                $meeResult += $share;
                $adjust[] = ['kind' => 'equity_method', 'label' => 'Mise en équivalence de '.$c->name, 'pl' => true, 'lines' => [
                    [$titres, $delta], [$resCode, -$reserves], [Referentials::code($pRef, '771'), -$share],
                ]];
            }
            $rows[array_key_last($rows)] += ['goodwill' => $goodwill, 'group_reserves' => $reserves];
        }

        // Ventes / achats entre sociétés du groupe : chiffre d'affaires et achats réduits du montant facturé par le vendeur
        $sales = round(array_sum(array_column($ic['sales'], 'amount')), 2);
        $purchases = round(array_sum(array_column($ic['purchases'], 'amount')), 2);
        if (abs($sales) > 0.004) {
            $map = Chart::mapping($parent);
            $adjust[] = ['kind' => 'intercompany_sales', 'label' => 'Élimination des ventes et achats entre sociétés du groupe', 'pl' => true,
                'lines' => [[$map['sales_goods'], $sales], [$map['purchases_goods'], -$sales]]];
        }
        if (abs($sales - $purchases) > 1) {
            $warnings[] = 'Ventes entre sociétés du groupe ('.number_format($sales, 0, ',', ' ').') différentes des achats correspondants ('.number_format($purchases, 0, ',', ' ').') : factures non enregistrées des deux côtés ou à des dates différentes.';
        }
        // Créances et dettes réciproques : l'écart éventuel est porté en compte d'attente
        $recv = round(array_sum(array_column($ic['receivable'], 'amount')), 2);
        $pay = round(array_sum(array_column($ic['payable'], 'amount')), 2);
        $gap = round(array_sum(array_map(fn ($a) => array_sum(array_column($a['lines'], 1)), array_filter($adjust, fn ($a) => $a['kind'] === 'intercompany_balance'))), 2);
        if (abs($gap) > 0.004) {
            $adjust[] = ['kind' => 'reciprocity', 'label' => 'Écart de réciprocité des comptes entre sociétés du groupe', 'pl' => false, 'lines' => [[Referentials::code($pRef, '471'), -$gap]]];
            $warnings[] = 'Créances (' .number_format($recv, 0, ',', ' ').') et dettes ('.number_format($pay, 0, ',', ' ').') entre sociétés du groupe ne se compensent pas : écart de '.number_format(abs($gap), 0, ',', ' ').' porté en compte d\'attente.';
        }
        // Marges internes de la période précédente : le stock a été revendu, la marge est réalisée (reprise en résultat, réserves réduites)
        $prevEnd = Carbon::parse($from)->subDay()->toDateString();
        foreach (DB::table('compta_group_adjustments')->where('parent_id', $parent->id)->where('period_to', $prevEnd)->where('kind', 'stock_margin')->get() as $x) {
            $seller = $ws[$x->seller_id] ?? null;
            $buyer = $ws[$x->buyer_id] ?? null;
            if (! $seller || ! $buyer || $seller['w'] <= 0 || $buyer['w'] <= 0) {
                continue;
            }
            $mg = round((float) $x->amount * (float) $x->margin_pct / 100 * $buyer['rate'] * min($seller['w'], $buyer['w']), 2);
            $dt = round($mg * $taxRate, 2);
            $stockMargin -= $mg;
            // La part des minoritaires du vendeur réduit leurs intérêts d'ouverture et revient dans leur résultat
            $share = $seller['ig'] ? ($seller['w'] - $seller['p']) / max($seller['w'], 0.0001) : 0.0;
            $miResult += round($share * ($mg - $dt), 2);
            $mi -= round($share * ($mg - $dt), 2);
            $adjust[] = ['kind' => 'stock_margin_reversal', 'label' => 'Reprise : '.$x->label.' (période précédente)', 'pl' => true,
                'lines' => array_values(array_filter([[Referentials::code($pRef, '118'), round((1 - $share) * ($mg - $dt), 2)], [Referentials::code($pRef, '6031'), -$mg], [Referentials::code($pRef, '891'), $dt]], fn ($l) => abs($l[1]) >= 0.005))];
        }
        // Retraitements saisis pour la période : marge interne sur stocks, dividendes, écritures manuelles
        $pClasses = Referentials::resultClasses($pRef);
        foreach (DB::table('compta_group_adjustments')->where('parent_id', $parent->id)->where('period_to', $to)->orderBy('id')->get() as $x) {
            $seller = $ws[$x->seller_id] ?? null;
            if ($x->kind === 'stock_margin') {
                $buyer = $ws[$x->buyer_id] ?? null;
                if (! $seller || ! $buyer || $seller['w'] <= 0 || $buyer['w'] <= 0) {
                    $warnings[] = 'Marge sur stocks « '.$x->label.' » ignorée : vendeur ou acheteur hors périmètre intégré.';

                    continue;
                }
                // Stock chez l'acheteur (dans sa monnaie) × taux de marge du vendeur, au cours de clôture de l'acheteur
                $mg = round((float) $x->amount * (float) $x->margin_pct / 100 * $buyer['rate'] * min($seller['w'], $buyer['w']), 2);
                $stockHeld = array_sum(array_filter($this->st->balances(Company::find($x->buyer_id), null, $to), fn ($v, $k) => str_starts_with((string) $k, Referentials::of(Company::find($x->buyer_id)) === 'cgnc' ? '31' : '3') && ! str_starts_with((string) $k, '39'), ARRAY_FILTER_USE_BOTH));
                if ((float) $x->amount > $stockHeld + 0.5) {
                    $warnings[] = '« '.$x->label.' » : le stock déclaré ('.number_format((float) $x->amount, 0, ',', ' ').') dépasse le stock comptabilisé chez l\'acheteur ('.number_format($stockHeld, 0, ',', ' ').') : passez l\'écriture d\'inventaire.';
                }
                $dt = round($mg * $taxRate, 2); // impôt différé actif : la marge sera imposée à nouveau à la revente hors du groupe
                $stockMargin += $mg;
                $deferredTax += $dt;
                if ($seller['ig']) {
                    $miResult -= round(($seller['w'] - $seller['p']) * ($mg - $dt) / max($seller['w'], 0.0001), 2);
                }
                $adjust[] = ['kind' => 'stock_margin', 'label' => $x->label, 'pl' => true, 'id' => $x->id,
                    'lines' => array_values(array_filter([[Referentials::code($pRef, '6031'), $mg], [Referentials::code($pRef, '31'), -$mg],
                        [self::deferredTaxCode($pRef), $dt], [Referentials::code($pRef, '891'), -$dt]], fn ($l) => abs($l[1]) >= 0.005))];
            } elseif ($x->kind === 'dividend') {
                $buyer = $ws[$x->buyer_id] ?? null;
                $dv = round((float) $x->amount * ($buyer['avg'] ?? 1) * ($buyer['w'] ?? 1), 2);
                $dividends += $dv;
                $adjust[] = ['kind' => 'dividend', 'label' => $x->label, 'pl' => true, 'id' => $x->id,
                    'lines' => [[self::dividendCode($pRef), $dv], [Referentials::code($pRef, '118'), -$dv]]];
            } else {
                $lines = array_map(fn ($l) => [(string) $l[0], round((float) ($l[1] ?? 0) - (float) ($l[2] ?? 0), 2)], json_decode($x->lines ?: '[]', true));
                $pl = collect($lines)->contains(fn ($l) => in_array($l[0][0] ?? '', $pClasses, true));
                $adjust[] = ['kind' => 'entry', 'label' => $x->label, 'pl' => $pl, 'id' => $x->id, 'lines' => $lines];
            }
        }
        foreach ($adjust as $a) {
            foreach ($a['lines'] as [$code, $amount]) {
                $add($cum, (string) $code, $amount);
                if ($a['pl']) {
                    $add($mov, (string) $code, $amount);
                }
            }
        }

        return compact('m', 'cum', 'mov', 'adjust', 'warnings', 'rows', 'mi', 'miResult', 'meeResult', 'sales', 'purchases', 'recv', 'pay', 'td', 'dividends', 'stockMargin', 'deferredTax', 'goodwillCharge', 'goodwillNet', 'perimeter', 'miBy', 'tdBy') + ['ic' => $ic];
    }

    /** États consolidés de la période : version arrêtée si elle existe, sinon calcul à jour (avec N-1 au même périmètre). */
    public function statements(Company $parent, string $from, string $to, bool $fresh = false): array
    {
        $snap = DB::table('compta_group_snapshots')->where('parent_id', $parent->id)->where('period_from', $from)->where('period_to', $to)->first();
        if ($snap && ! $fresh) {
            return json_decode($snap->data, true) + ['frozen' => ['at' => (string) $snap->created_at, 'by' => DB::table('users')->where('id', $snap->user_id)->value('name')]];
        }

        return $this->build($parent, $from, $to) + ['frozen' => null];
    }

    /** Arrête les comptes consolidés de la période : ils ne bougent plus, même si les comptes des sociétés changent. */
    public function freeze(Company $parent, string $from, string $to, ?int $userId): array
    {
        $data = $this->build($parent, $from, $to);
        $this->assert($data['sheet']['balanced'], 'Le bilan consolidé n\'est pas équilibré : corrigez les retraitements avant d\'arrêter les comptes.');
        DB::table('compta_group_snapshots')->updateOrInsert(['parent_id' => $parent->id, 'period_from' => $from, 'period_to' => $to],
            ['data' => json_encode($data), 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);

        return $this->statements($parent, $from, $to);
    }

    public function unfreeze(Company $parent, string $from, string $to): void
    {
        DB::table('compta_group_snapshots')->where('parent_id', $parent->id)->where('period_from', $from)->where('period_to', $to)->delete();
    }

    public function isFrozen(Company $parent, string $to): bool
    {
        return DB::table('compta_group_snapshots')->where('parent_id', $parent->id)->where('period_to', $to)->exists();
    }

    // ------------------------------------------------------------------------------------------------ retraitements saisis

    public function adjustments(Company $parent, string $to): array
    {
        $names = Company::whereIn('id', GroupMember::where('parent_id', $parent->id)->pluck('member_id')->push($parent->id))->pluck('name', 'id');

        return DB::table('compta_group_adjustments')->where('parent_id', $parent->id)->where('period_to', $to)->orderBy('id')->get()->map(fn ($x) => [
            'id' => $x->id, 'kind' => $x->kind, 'label' => $x->label, 'amount' => (float) $x->amount, 'margin_pct' => $x->margin_pct === null ? null : (float) $x->margin_pct,
            'seller_id' => $x->seller_id, 'buyer_id' => $x->buyer_id, 'seller' => $names[$x->seller_id] ?? null, 'buyer' => $names[$x->buyer_id] ?? null,
            'lines' => json_decode($x->lines ?: '[]', true),
        ])->all();
    }

    /** Taux de marge commerciale d'une société sur la période : (ventes − coût d'achat des marchandises vendues) / ventes. */
    public function marginRate(Company $c, string $from, string $to): ?float
    {
        $ref = Referentials::of($c);
        $mov = $this->st->balances($c, $from, $to, true);
        $sum = fn (string $canonical) => array_sum(array_filter($mov, fn ($v, $k) => str_starts_with((string) $k, Referentials::code($ref, $canonical)), ARRAY_FILTER_USE_BOTH));
        $sales = -$sum('701');
        $cost = $sum('601') + $sum('6031');

        return $sales > 0 ? round(max(0, min(100, ($sales - $cost) / $sales * 100)), 2) : null;
    }

    public function saveAdjustment(Company $parent, string $to, array $d, ?int $userId): array
    {
        $this->assert(! $this->isFrozen($parent, $to), 'Les comptes consolidés de cette période sont arrêtés : rouvrez-les avant de modifier les retraitements.');
        $ids = GroupMember::where('parent_id', $parent->id)->pluck('member_id')->push($parent->id)->all();
        $row = ['parent_id' => $parent->id, 'period_to' => $to, 'kind' => $d['kind'], 'label' => trim($d['label'] ?? '') ?: null, 'user_id' => $userId, 'updated_at' => now()];
        if ($d['kind'] === 'entry') {
            $lines = array_values(array_filter(array_map(fn ($l) => [trim((string) ($l['account'] ?? '')), round((float) ($l['debit'] ?? 0), 2), round((float) ($l['credit'] ?? 0), 2)], $d['lines'] ?? []), fn ($l) => $l[0] !== '' && ($l[1] || $l[2])));
            $this->assert(count($lines) >= 2, 'Une écriture de consolidation comporte au moins deux lignes.');
            $this->assert(abs(array_sum(array_column($lines, 1)) - array_sum(array_column($lines, 2))) < 0.01, 'Écriture déséquilibrée : le total des débits doit égaler celui des crédits.');
            $row += ['lines' => json_encode($lines), 'amount' => array_sum(array_column($lines, 1))];
            $row['label'] ??= 'Écriture de consolidation';
        } else {
            $this->assert(in_array((int) ($d['seller_id'] ?? 0), $ids, true) && in_array((int) ($d['buyer_id'] ?? 0), $ids, true), 'Choisissez deux sociétés du groupe.');
            $this->assert((int) $d['seller_id'] !== (int) $d['buyer_id'], 'Le vendeur et l\'acheteur doivent être deux sociétés différentes.');
            $this->assert((float) ($d['amount'] ?? 0) > 0, 'Indiquez un montant.');
            $row += ['seller_id' => (int) $d['seller_id'], 'buyer_id' => (int) $d['buyer_id'], 'amount' => round((float) $d['amount'], 2)];
            $names = Company::whereIn('id', [$d['seller_id'], $d['buyer_id']])->pluck('name', 'id');
            if ($d['kind'] === 'stock_margin') {
                $mp = $d['margin_pct'] ?? null;
                if ($mp === null || $mp === '') {
                    $mp = $this->marginRate(Company::find($d['seller_id']), Carbon::parse($to)->subYear()->addDay()->toDateString(), $to);
                }
                $this->assert($mp !== null && (float) $mp > 0, 'Indiquez le taux de marge du vendeur (aucune vente de marchandises sur la période pour le calculer).');
                $row['margin_pct'] = round((float) $mp, 3);
                $row['label'] ??= 'Marge interne sur stocks : '.$names[$d['buyer_id']].' détient des marchandises de '.$names[$d['seller_id']];
            } else {
                $row['label'] ??= 'Dividendes versés par '.$names[$d['seller_id']].' à '.$names[$d['buyer_id']];
            }
        }
        if (! empty($d['id'])) {
            // retraitement existant : sa période d'origine ne doit pas être arrêtée non plus
            $prev = DB::table('compta_group_adjustments')->where('parent_id', $parent->id)->where('id', $d['id'])->first();
            $this->assert((bool) $prev, 'Retraitement introuvable.');
            $this->assert(! $this->isFrozen($parent, (string) $prev->period_to), 'Les comptes consolidés de cette période sont arrêtés : rouvrez-les avant de modifier les retraitements.');
            DB::table('compta_group_adjustments')->where('parent_id', $parent->id)->where('id', $d['id'])->update($row);
        } else {
            DB::table('compta_group_adjustments')->insert($row + ['created_at' => now()]);
        }

        return $this->adjustments($parent, $to);
    }

    public function deleteAdjustment(Company $parent, int $id): void
    {
        $x = DB::table('compta_group_adjustments')->where('parent_id', $parent->id)->where('id', $id)->first();
        $this->assert((bool) $x, 'Retraitement introuvable.');
        $this->assert(! $this->isFrozen($parent, $x->period_to), 'Les comptes consolidés de cette période sont arrêtés : rouvrez-les avant de modifier les retraitements.');
        DB::table('compta_group_adjustments')->where('id', $id)->delete();
    }

    /** Soldes consolidés à la fin de la période précédente, résultats de gestion reclassés en report à nouveau. */
    protected function openingOf(Company $parent, array $p): array
    {
        $pRef = Referentials::of($parent);
        $classes = Referentials::resultClasses($pRef);
        $out = [];
        $prior = 0.0;
        foreach ($p['cum'] as $code => $v) {
            if (in_array(((string) $code)[0], $classes, true)) {
                $prior += $v;
            } else {
                $out[(string) $code] = $v;
            }
        }
        $ran = Referentials::code($pRef, '121');
        $out[$ran] = round(($out[$ran] ?? 0) + $prior, 2);

        return $out;
    }

    /** Tableau des flux de trésorerie consolidé et tableau de variation des capitaux propres. */
    protected function flowsAndEquity(Company $parent, array $m, array $n, array $p, string $from, string $to, string $pt): array
    {
        $prevEnd = Carbon::parse($from)->subDay()->toDateString();
        $o = $pt === $prevEnd ? $p : $this->compute($parent, Carbon::parse($from)->subYear()->toDateString(), $prevEnd);
        $open = $this->openingOf($parent, $o);
        $cashflow = null;
        if (is_array($m['cash'] ?? null)) {
            // Entrées et sorties de périmètre : les actifs et passifs acquis ou cédés ne sont pas des flux ; seule la trésorerie nette (prix − trésorerie de la filiale) en est un
            $openM = $open;
            $closeM = $n['cum'];
            $gwCode = self::goodwillCode(Referentials::of($parent));
            foreach ($n['perimeter']['entry'] as $x) {
                foreach ($x['balances'] as $code => $v) {
                    $openM[$code] = ($openM[$code] ?? 0) + $v;
                }
                $openM[$gwCode] = ($openM[$gwCode] ?? 0) + $x['goodwill'];
            }
            $vncCode = Referentials::code(Referentials::of($parent), '81');
            foreach ($n['perimeter']['exit'] as $x) {
                foreach ($x['balances'] as $code => $v) {
                    $closeM[$code] = ($closeM[$code] ?? 0) + $v;
                }
                // la valeur consolidée des titres cédés n'est pas un investissement : elle passe dans l'incidence de périmètre
                $closeM[$vncCode] = ($closeM[$vncCode] ?? 0) - $x['carry'];
            }
            // Minoritaires et écarts de conversion des sociétés présentes au début et à la fin
            $exitedN = collect($n['rows'])->whereNotNull('exit_date')->pluck('company_id')->all();
            $both = array_diff(array_intersect(collect($n['rows'])->pluck('company_id')->all(), collect($o['rows'])->whereNull('exit_date')->pluck('company_id')->all()), $exitedN);
            $pseudo = fn (array $c, array $ids) => array_sum(array_map(fn ($id) => ($c['miBy'][$id] ?? 0) + ($c['tdBy'][$id] ?? 0), $ids));
            $cashflow = $this->st->cashFlowFrom($m, $openM, $closeM, $from, $to, ['FM' => round($pseudo($n, $both) - $pseudo($o, $both), 2)]);
            $treso = $m['cash']['treasury'];
            $tOpen = round(array_sum(array_filter($open, fn ($v, $k) => str_starts_with((string) $k, $treso), ARRAY_FILTER_USE_BOTH)), 2);
            $tClose = round(array_sum(array_filter($n['cum'], fn ($v, $k) => str_starts_with((string) $k, $treso), ARRAY_FILTER_USE_BOTH)), 2);
            $perim = round(($tClose - $tOpen) - $cashflow['variation'], 2);
            if (abs($perim) >= 0.005 || $n['perimeter']['entry'] || $n['perimeter']['exit']) {
                $rows = [];
                foreach ($cashflow['rows'] as $r) {
                    if (in_array($r['key'], ['ZC', 'ZG'], true)) {
                        $r['amount'] = round($r['amount'] + $perim, 2);
                    }
                    if ($r['key'] === 'ZC') {
                        $rows[] = ['ref' => '', 'key' => 'FJ', 'label' => 'Incidence des variations de périmètre (prix des titres net de la trésorerie acquise ou cédée)', 'amount' => $perim, 'total' => false];
                    }
                    if ($r['key'] === 'ZH') {
                        $r['amount'] = $tClose;
                    }
                    $rows[] = $r;
                }
                $cashflow['rows'] = $rows;
                $cashflow['variation'] = round($cashflow['variation'] + $perim, 2);
                $cashflow['perimeter'] = $perim;
                $cashflow['check'] = abs($cashflow['opening'] + $cashflow['variation'] - $tClose) < 0.01;
            }
            $cashflow['title'] = 'Tableau des flux de trésorerie consolidé';
        }
        $pRef = Referentials::of($parent);
        $eqP = self::equityPrefixes($pRef);
        $capP = $pRef === 'cgnc' ? ['111', '112'] : ['10'];
        $sumOf = fn (array $bal, array $prefixes) => round(-array_sum(array_filter($bal, fn ($v, $k) => collect($prefixes)->contains(fn ($x) => str_starts_with((string) $k, $x)), ARRAY_FILTER_USE_BOTH)), 2);
        $result = $this->st->incomeValues($m, $n['mov'])[$m['result_key']];
        $capO = $sumOf($open, $capP);
        $resO = round($sumOf($open, $eqP) - $capO - $o['miResult'], 2);
        $miO = round($o['mi'] + $o['miResult'], 2);
        $capC = $sumOf($n['cum'], $capP);
        $groupResult = round($result - $n['miResult'], 2);
        $resC = round($sumOf($n['cum'], $eqP) - $capC + $groupResult, 2);
        $miC = round($n['mi'] + $n['miResult'], 2);
        $row = fn (string $key, string $label, float $cap, float $res, float $td, float $mi, bool $total = false) => [
            'key' => $key, 'label' => $label, 'capital' => round($cap, 2), 'reserves' => round($res, 2), 'translation' => round($td, 2),
            'group' => round($cap + $res + $td, 2), 'minority' => round($mi, 2), 'total' => round($cap + $res + $td + $mi, 2), 'is_total' => $total,
        ];
        $tdMove = round($n['td'] - $o['td'], 2);
        $rows = [
            $row('open', 'Capitaux propres au '.Carbon::parse($prevEnd)->format('d/m/Y'), $capO, $resO, $o['td'], $miO, true),
            $row('result', 'Résultat de l\'exercice', 0, $groupResult, 0, $n['miResult']),
            $row('capital', 'Augmentations (réductions) de capital', $capC - $capO, 0, 0, 0),
            $row('translation', 'Variation des écarts de conversion', 0, 0, $tdMove, 0),
            $row('other', 'Distributions, variations de périmètre et autres mouvements', 0, $resC - $resO - $groupResult, 0, $miC - $miO - $n['miResult']),
            $row('close', 'Capitaux propres au '.Carbon::parse($to)->format('d/m/Y'), $capC, $resC, $n['td'], $miC, true),
        ];

        return [$cashflow, ['title' => 'Tableau de variation des capitaux propres consolidés', 'rows' => $rows]];
    }

    protected function build(Company $parent, string $from, string $to): array
    {
        $this->assert(GroupMember::where('parent_id', $parent->id)->exists(), 'Aucune filiale rattachée : ajoutez d\'abord les sociétés du groupe.');
        $n = $this->compute($parent, $from, $to);
        [$pf, $pt] = [Carbon::parse($from)->subYear()->toDateString(), Carbon::parse($to)->subYear()->toDateString()];
        $p = $this->compute($parent, $pf, $pt);
        $m = $n['m'];
        // Compte de résultat
        $vn = $this->st->incomeValues($m, $n['mov']);
        $vp = $this->st->incomeValues($m, $p['mov']);
        $labels = array_column($m['income_lines'], 1, 0);
        $sense = array_column($m['income_lines'], 3, 0);
        $income = [];
        foreach ($m['income_layout'] as $item) {
            if (is_array($item)) {
                if (! empty($item[4])) {
                    continue;
                }
                $k = (int) ($item[3] ?? 1);
                $income[] = ['ref' => ! empty($m['official_refs']) ? $item[0] : '', 'key' => $item[0], 'label' => $item[1], 'total' => true, 'n' => round($k * $vn[$item[0]], 2) + 0.0, 'n1' => round($k * $vp[$item[0]], 2) + 0.0];
            } else {
                $income[] = ['ref' => ! empty($m['official_refs']) ? $item : '', 'key' => $item, 'label' => $labels[$item], 'total' => false, 'sign' => $sense[$item] > 0 ? '+' : '-',
                    'n' => round($sense[$item] * $vn[$item], 2) + 0.0, 'n1' => round($sense[$item] * $vp[$item], 2) + 0.0];
            }
        }
        $result = $vn[$m['result_key']];
        $resultP = $vp[$m['result_key']];
        $income[] = ['ref' => '', 'key' => 'MIR', 'label' => 'dont part des intérêts minoritaires', 'total' => false, 'n' => $n['miResult'], 'n1' => $p['miResult']];
        $income[] = ['ref' => '', 'key' => 'GRR', 'label' => 'RÉSULTAT NET CONSOLIDÉ — PART DU GROUPE', 'total' => true, 'n' => round($result - $n['miResult'], 2), 'n1' => round($resultP - $p['miResult'], 2)];
        // Bilan
        [$a, $l] = $this->st->sheetFrom($m, $n['cum'], $n['mov']);
        [$pa, $pl] = $this->st->sheetFrom($m, $p['cum'], $p['mov']);
        $assetLabels = array_column($m['asset_lines'], 1, 0);
        $liabLabels = array_column($m['liability_lines'], 1, 0);
        $assets = [];
        foreach ($m['asset_order'] as $ref) {
            $assets[] = ['ref' => ! empty($m['official_refs']) ? $ref : '', 'key' => $ref, 'label' => $assetLabels[$ref] ?? $m['asset_totals'][$ref][0], 'total' => isset($m['asset_totals'][$ref]),
                'gross' => $a[$ref]['gross'], 'amort' => $a[$ref]['amort'], 'net' => $a[$ref]['net'], 'n1' => $pa[$ref]['net']];
        }
        $liab = [];
        $lastKey = end($m['liability_order']);
        $extraN = round($n['mi'] + $n['td'], 2);
        $extraP = round($p['mi'] + $p['td'], 2);
        $pseudo = fn () => array_merge(
            abs($n['td']) >= 0.005 || abs($p['td']) >= 0.005 ? [['ref' => '', 'key' => 'TD', 'label' => 'Écarts de conversion — part du groupe', 'total' => false, 'n' => round($n['td'], 2), 'n1' => round($p['td'], 2)]] : [],
            [['ref' => '', 'key' => 'MI', 'label' => 'Intérêts minoritaires (dont leur part du résultat)', 'total' => false, 'n' => round($n['mi'] + $n['miResult'], 2), 'n1' => round($p['mi'] + $p['miResult'], 2)]],
        );
        // Le résultat du bilan est présenté part du groupe : la part des minoritaires rejoint la ligne des intérêts minoritaires
        $resKey = $m['fallback']['result'];
        $contains = function (string $total) use (&$contains, $m, $resKey): bool {
            foreach ($m['liability_totals'][$total][1] ?? [] as $part) {
                if ($part === $resKey || (isset($m['liability_totals'][$part]) && $contains($part))) {
                    return true;
                }
            }

            return false;
        };
        $inserted = false;
        foreach ($m['liability_order'] as $ref) {
            $isTotal = isset($m['liability_totals'][$ref]);
            $nv = $l[$ref];
            $pv = $pl[$ref];
            if ($ref !== $lastKey && ($ref === $resKey || ($isTotal && $contains($ref)))) {
                $nv = round($nv - $n['miResult'], 2);
                $pv = round($pv - $p['miResult'], 2);
            }
            if ($ref === $lastKey) {
                $nv = round($nv + $extraN, 2);
                $pv = round($pv + $extraP, 2);
                if (! $inserted) {
                    array_push($liab, ...$pseudo());
                    $inserted = true;
                }
            }
            $liab[] = ['ref' => ! empty($m['official_refs']) ? $ref : '', 'key' => $ref, 'label' => ($liabLabels[$ref] ?? $m['liability_totals'][$ref][0]).($ref === $resKey ? ' — part du groupe' : ''), 'total' => $isTotal, 'n' => $nv, 'n1' => $pv];
            // Après le total des capitaux propres (premier sous-total du passif)
            if ($isTotal && ! $inserted && $ref !== $lastKey) {
                array_push($liab, ...$pseudo());
                $inserted = true;
            }
        }
        $ta = $a[end($m['asset_order'])]['net'];
        $tl = round($l[$lastKey] + $extraN, 2);
        $goodwill = round($n['goodwillNet'], 2);
        [$cashflow, $equityChanges] = $this->flowsAndEquity($parent, $m, $n, $p, $from, $to, $pt);
        $groupEquity = round(array_sum(array_map(fn ($code, $v) => collect(self::equityPrefixes(Referentials::of($parent)))->contains(fn ($p) => str_starts_with((string) $code, $p)) ? -$v : 0, array_keys($n['cum']), $n['cum'])) + $result - $n['miResult'] + $n['td'], 2);

        return [
            'from' => $from, 'to' => $to, 'prev_from' => $pf, 'prev_to' => $pt, 'parent' => $parent->name, 'currency' => $parent->currency, 'referential' => Referentials::LIST[Referentials::of($parent)]['short'],
            'perimeter' => $n['rows'], 'warnings' => $n['warnings'],
            'income' => ['title' => 'Compte de résultat consolidé', 'rows' => $income, 'result' => $result, 'result_n1' => $resultP],
            'sheet' => ['title' => 'Bilan consolidé', 'assets' => $assets, 'liabilities' => $liab, 'total_assets' => $ta, 'total_liabilities' => $tl, 'balanced' => abs($ta - $tl) < 0.01],
            'summary' => ['result' => $result, 'minority_result' => $n['miResult'], 'group_result' => round($result - $n['miResult'], 2), 'minority_interests' => round($n['mi'], 2), 'minority_interests_total' => round($n['mi'] + $n['miResult'], 2),
                'goodwill' => $goodwill, 'equity_method_result' => $n['meeResult'], 'group_equity' => $groupEquity,
                'intercompany_sales' => $n['sales'], 'intercompany_purchases' => $n['purchases'], 'intercompany_receivables' => $n['recv'], 'intercompany_payables' => $n['pay'],
                'translation_difference' => round($n['td'], 2), 'intercompany_dividends' => round($n['dividends'], 2), 'stock_margin' => round($n['stockMargin'], 2),
                'deferred_tax' => round($n['deferredTax'], 2), 'goodwill_charge' => round($n['goodwillCharge'], 2), 'deferred_tax_rate' => self::deferredTaxRate($parent)],
            'cashflow' => $cashflow, 'equity_changes' => $equityChanges,
            'adjustments' => array_map(fn ($x) => ['kind' => $x['kind'], 'label' => $x['label'], 'id' => $x['id'] ?? null, 'lines' => array_values(array_map(fn ($ln) => ['account' => (string) $ln[0], 'debit' => max(0, round($ln[1], 2)), 'credit' => max(0, round(-$ln[1], 2))], array_filter($x['lines'], fn ($ln) => abs($ln[1]) >= 0.005)))], $n['adjust']),
            'intercompany' => $n['ic'],
        ];
    }

    public function book(Company $parent, string $from, string $to): string
    {
        $s = $this->statements($parent, $from, $to);
        $n = fn ($v) => $v === null ? '' : round((float) $v, 2);
        $boldIdx = fn (array $rows) => array_keys(array_filter($rows, fn ($r) => ! empty($r['total'])));
        $sheets = [
            'Périmètre' => [['Société', 'Méthode', '% d\'intérêts', 'Monnaie', 'Cours', 'Référentiel', 'Capitaux propres', 'Résultat', 'Écart d\'acquisition', 'Réserves consolidées'],
                array_map(fn ($r) => [$r['name'], $r['method_label'], $r['interest_pct'], $r['currency'], $r['rate'], $r['referential'], $n($r['equity']), $n($r['result']), $n($r['goodwill'] ?? null), $n($r['group_reserves'] ?? null)], $s['perimeter'])],
            'Bilan actif' => [['Réf.', 'ACTIF', 'Brut', 'Amort. et dépréc.', 'Net N', 'Net N-1'], array_map(fn ($r) => [$r['ref'], $r['label'], $n($r['gross']), $n($r['amort']), $n($r['net']), $n($r['n1'])], $s['sheet']['assets']), $boldIdx($s['sheet']['assets'])],
            'Bilan passif' => [['Réf.', 'PASSIF', 'Net N', 'Net N-1'], array_map(fn ($r) => [$r['ref'], $r['label'], $n($r['n']), $n($r['n1'])], $s['sheet']['liabilities']), $boldIdx($s['sheet']['liabilities'])],
            'Compte de résultat' => [['Réf.', 'Compte de résultat consolidé', 'Exercice N', 'Exercice N-1'], array_map(fn ($r) => [$r['ref'], (($r['sign'] ?? '') === '-' ? '(−) ' : '').$r['label'], $n($r['n']), $n($r['n1'])], $s['income']['rows']), $boldIdx($s['income']['rows'])],
        ];
        $adj = [];
        foreach ($s['adjustments'] as $a) {
            $adj[] = [$a['label'], '', '', ''];
            foreach ($a['lines'] as $ln) {
                $adj[] = ['', $ln['account'], $n($ln['debit']), $n($ln['credit'])];
            }
        }
        $sheets['Retraitements'] = [['Retraitement', 'Compte', 'Débit', 'Crédit'], $adj];
        if ($s['cashflow']) {
            $sheets['Flux de trésorerie'] = [['Réf.', 'Tableau des flux de trésorerie consolidé', 'Montant'], array_map(fn ($r) => [$r['ref'], $r['label'], $n($r['amount'])], $s['cashflow']['rows']),
                array_keys(array_filter($s['cashflow']['rows'], fn ($r) => $r['total']))];
        }
        $eq = $s['equity_changes']['rows'];
        $sheets['Capitaux propres'] = [['Variation des capitaux propres', 'Capital', 'Réserves et résultats', 'Écarts de conversion', 'Part du groupe', 'Minoritaires', 'Total'],
            array_map(fn ($r) => [$r['label'], $n($r['capital']), $n($r['reserves']), $n($r['translation']), $n($r['group']), $n($r['minority']), $n($r['total'])], $eq),
            array_keys(array_filter($eq, fn ($r) => $r['is_total']))];
        $sheets['Synthèse'] = [['Élément', 'Montant'], array_merge(
            array_map(fn ($k, $label) => [$label, $k === 'deferred_tax_rate' ? $s['summary'][$k].' %' : $n($s['summary'][$k] ?? 0)], array_keys(self::SUMMARY_LABELS), self::SUMMARY_LABELS),
            array_map(fn ($w) => ['Remarque : '.$w, ''], $s['warnings']))];
        $path = tempnam(sys_get_temp_dir(), 'conso').'.xlsx';
        \App\Modules\ImportExport\Sheet::writeBook($path, $sheets);

        return $path;
    }

    /** Liasse consolidée en PDF : périmètre, synthèse, bilan, compte de résultat, retraitements, remarques. */
    public function pdf(Company $parent, string $from, string $to): string
    {
        $s = $this->statements($parent, $from, $to);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => $v === null || $v === '' ? '' : $e(number_format((float) $v, 0, ',', ' '));
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('dejavusans', '', 8);
        $period = 'du '.Carbon::parse($from)->format('d/m/Y').' au '.Carbon::parse($to)->format('d/m/Y');
        $head = fn (string $title) => '<table cellpadding="3"><tr><td width="60%"><b style="font-size:11pt">'.$e($s['parent']).'</b><br>Groupe consolidé · '.$e($s['referential']).' · '.$e($s['currency']).'</td>'
            .'<td width="40%" align="right"><b style="font-size:11pt;color:#1f6feb">'.$e($title).'</b><br>'.$period.($s['frozen'] ? '<br><span style="color:#15803d">Comptes arrêtés le '.Carbon::parse($s['frozen']['at'])->format('d/m/Y').'</span>' : '<br><span style="color:#b45309">Version provisoire</span>').'</td></tr></table><br>';
        $th = fn (array $cols) => '<tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold">'.implode('', array_map(fn ($c) => '<td width="'.$c[1].'"'.(! empty($c[2]) ? ' align="right"' : '').'>'.$e($c[0]).'</td>', $cols)).'</tr>';
        $row = fn (array $cells, array $cols, bool $bold = false) => '<tr'.($bold ? ' bgcolor="#eef2f8" style="font-weight:bold"' : '').'>'.implode('', array_map(fn ($v, $c) => '<td width="'.$c[1].'"'.(! empty($c[2]) ? ' align="right"' : '').'>'.$v.'</td>', $cells, $cols)).'</tr>';

        // Page 1 : périmètre et synthèse
        $pdf->AddPage();
        $cols = [['Société', '30%'], ['Méthode', '18%'], ['% intérêts', '9%', 1], ['Monnaie', '9%'], ['Capitaux propres', '17%', 1], ['Résultat', '17%', 1]];
        $html = $head('COMPTES CONSOLIDÉS').'<h3>Périmètre de consolidation</h3><table cellpadding="3" border="0.3">'.$th($cols);
        foreach ($s['perimeter'] as $r) {
            $html .= $row([$e($r['name']), $e($r['method_label']), $e($r['interest_pct']).' %', $e($r['currency']), $m($r['equity']), $m($r['result'])], $cols);
        }
        $html .= '</table><br><h3>Synthèse</h3><table cellpadding="3" border="0.3" width="70%">';
        $labels = self::SUMMARY_LABELS;
        foreach ($labels as $k => $label) {
            $html .= '<tr'.(in_array($k, ['group_result', 'group_equity'], true) ? ' style="font-weight:bold"' : '').'><td width="65%">'.$e($label).'</td><td width="35%" align="right">'.($k === 'deferred_tax_rate' ? $e($s['summary'][$k]).' %' : $m($s['summary'][$k] ?? 0)).'</td></tr>';
        }
        $html .= '</table>';
        foreach ($s['warnings'] as $w) {
            $html .= '<p style="color:#b45309">• '.$e($w).'</p>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        // Bilan
        $pdf->AddPage();
        $cols = [['Réf.', '6%'], ['ACTIF', '40%'], ['Brut', '13.5%', 1], ['Amort. / dépréc.', '13.5%', 1], ['Net N', '13.5%', 1], ['Net N-1', '13.5%', 1]];
        $html = $head('BILAN CONSOLIDÉ').'<table cellpadding="2.5" border="0.3">'.$th($cols);
        foreach ($s['sheet']['assets'] as $r) {
            $html .= $row([$e($r['ref']), $e($r['label']), $m($r['gross']), $m($r['amort']), $m($r['net']), $m($r['n1'])], $cols, $r['total']);
        }
        $cols = [['Réf.', '6%'], ['PASSIF', '67%'], ['Net N', '13.5%', 1], ['Net N-1', '13.5%', 1]];
        $html .= '</table><br><br><table cellpadding="2.5" border="0.3">'.$th($cols);
        foreach ($s['sheet']['liabilities'] as $r) {
            $html .= $row([$e($r['ref']), $e($r['label']), $m($r['n']), $m($r['n1'])], $cols, $r['total']);
        }
        $html .= '</table><p>'.($s['sheet']['balanced'] ? 'Bilan consolidé équilibré.' : '<span style="color:#b91c1c">Bilan consolidé déséquilibré.</span>').'</p>';
        $pdf->writeHTML($html, true, false, true, false, '');

        // Compte de résultat
        $pdf->AddPage();
        $html = $head('COMPTE DE RÉSULTAT CONSOLIDÉ').'<table cellpadding="2.5" border="0.3">'.$th($cols = [['Réf.', '6%'], ['Libellé', '64%'], ['Exercice N', '15%', 1], ['Exercice N-1', '15%', 1]]);
        foreach ($s['income']['rows'] as $r) {
            $html .= $row([$e($r['ref']), $e((($r['sign'] ?? '') === '-' ? '(−) ' : '').$r['label']), $m($r['n']), $m($r['n1'])], $cols, $r['total']);
        }
        $pdf->writeHTML($html.'</table>', true, false, true, false, '');

        // Flux de trésorerie
        if ($s['cashflow']) {
            $pdf->AddPage();
            $html = $head('FLUX DE TRÉSORERIE CONSOLIDÉS').'<table cellpadding="2.5" border="0.3">'.$th($cols = [['Réf.', '8%'], ['Libellé', '70%'], ['Montant', '22%', 1]]);
            foreach ($s['cashflow']['rows'] as $r) {
                $html .= $row([$e($r['ref']), $e($r['label']), $m($r['amount'])], $cols, $r['total']);
            }
            $pdf->writeHTML($html.'</table>', true, false, true, false, '');
        }
        // Variation des capitaux propres
        $pdf->AddPage('L');
        $cols = [['', '31%'], ['Capital', '11.5%', 1], ['Réserves et résultats', '11.5%', 1], ['Écarts de conversion', '11.5%', 1], ['Part du groupe', '11.5%', 1], ['Minoritaires', '11.5%', 1], ['Total', '11.5%', 1]];
        $html = $head('VARIATION DES CAPITAUX PROPRES CONSOLIDÉS').'<table cellpadding="3" border="0.3">'.$th($cols);
        foreach ($s['equity_changes']['rows'] as $r) {
            $html .= $row([$e($r['label']), $m($r['capital']), $m($r['reserves']), $m($r['translation']), $m($r['group']), $m($r['minority']), $m($r['total'])], $cols, $r['is_total']);
        }
        $pdf->writeHTML($html.'</table>', true, false, true, false, '');

        // Retraitements
        $pdf->AddPage('P');
        $cols = [['Compte', '20%'], ['Libellé', '50%'], ['Débit', '15%', 1], ['Crédit', '15%', 1]];
        $html = $head('RETRAITEMENTS DE CONSOLIDATION');
        foreach ($s['adjustments'] as $a) {
            $html .= '<table cellpadding="2.5" border="0.3"><tr bgcolor="#eef2f8" style="font-weight:bold"><td colspan="4">'.$e($a['label']).'</td></tr>';
            foreach ($a['lines'] as $ln) {
                $html .= $row([$e($ln['account']), $e(Referentials::name(Referentials::of($parent), $ln['account']) ?? (['26' => 'Titres de participation', '261' => 'Titres de participation', '2510' => 'Titres de participation', '215' => 'Écart d\'acquisition', '207' => 'Écart d\'acquisition', '2230' => 'Écart d\'acquisition', '4497' => 'Impôts différés actif', '4487' => 'Impôts différés actif', '3458' => 'Impôts différés actif', '2815' => 'Amortissements de l\'écart d\'acquisition', '2807' => 'Amortissements de l\'écart d\'acquisition', '28230' => 'Amortissements de l\'écart d\'acquisition'][$ln['account']] ?? '')), $ln['debit'] ? $m($ln['debit']) : '', $ln['credit'] ? $m($ln['credit']) : ''], $cols);
            }
            $html .= '</table><br>';
        }
        $pdf->writeHTML($html ?: 'Aucun retraitement.', true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}
