<?php

namespace App\Modules\Compta;

use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * États financiers selon le référentiel de la société : SYSCOHADA révisé (système normal, SMT), SYCEBNL, PCG (France),
 * CGNC (Maroc). Bilan, compte de résultat avec soldes intermédiaires, tableau des flux de trésorerie ; exercice N et N-1.
 * Les tables SYSCOHADA ci-dessous servent de modèle ; les autres référentiels ont leurs propres tables (StatementModels).
 */
class Statements
{
    /** Compte de résultat : [réf, libellé, préfixes, sens] ; sens 1 = produit (crédit), -1 = charge (débit). */
    public const INCOME_LINES = [
        ['TA', 'Ventes de marchandises', ['701'], 1],
        ['RA', 'Achats de marchandises', ['601'], -1],
        ['RB', 'Variation de stocks de marchandises', ['6031'], -1],
        ['TB', 'Ventes de produits fabriqués', ['702', '703', '704'], 1],
        ['TC', 'Travaux, services vendus', ['705', '706'], 1],
        ['TD', 'Produits accessoires', ['707', '708', '709'], 1],
        ['TE', 'Production stockée (ou déstockage)', ['73'], 1],
        ['TF', 'Production immobilisée', ['72'], 1],
        ['TG', 'Subventions d\'exploitation', ['71'], 1],
        ['TH', 'Autres produits', ['75', '7'], 1],
        ['TI', 'Transferts de charges d\'exploitation', ['781'], 1],
        ['RC', 'Achats de matières premières et fournitures liées', ['602'], -1],
        ['RD', 'Variation de stocks de matières premières', ['6032'], -1],
        ['RE', 'Autres achats', ['604', '605', '608', '60'], -1],
        ['RF', 'Variation de stocks d\'autres approvisionnements', ['6033'], -1],
        ['RG', 'Transports', ['61'], -1],
        ['RH', 'Services extérieurs', ['62', '63'], -1],
        ['RI', 'Impôts et taxes', ['64'], -1],
        ['RJ', 'Autres charges', ['65', '6'], -1],
        ['RK', 'Charges de personnel', ['66'], -1],
        ['TJ', 'Reprises d\'amortissements, provisions et dépréciations', ['791', '798', '799', '79'], 1],
        ['RL', 'Dotations aux amortissements, provisions et dépréciations', ['68', '69'], -1],
        ['TK', 'Revenus financiers et assimilés', ['77'], 1],
        ['TL', 'Reprises de provisions et dépréciations financières', ['797'], 1],
        ['TM', 'Transferts de charges financières', ['787'], 1],
        ['RM', 'Frais financiers et charges assimilées', ['67'], -1],
        ['RN', 'Dotations aux provisions et dépréciations financières', ['697'], -1],
        ['TN', 'Produits des cessions d\'immobilisations', ['82'], 1],
        ['TO', 'Autres produits HAO', ['84', '86', '88', '8'], 1],
        ['RO', 'Valeurs comptables des cessions d\'immobilisations', ['81'], -1],
        ['RP', 'Autres charges HAO', ['83', '85'], -1],
        ['RQ', 'Participation des travailleurs', ['87'], -1],
        ['RS', 'Impôts sur le résultat', ['89'], -1],
    ];

    /** Ordre de présentation et soldes intermédiaires de gestion. */
    public const INCOME_LAYOUT = [
        'TA', 'RA', 'RB', ['XA', 'MARGE COMMERCIALE', ['TA', 'RA', 'RB']],
        'TB', 'TC', 'TD', ['XB', 'CHIFFRE D\'AFFAIRES', ['TA', 'TB', 'TC', 'TD']],
        'TE', 'TF', 'TG', 'TH', 'TI', 'RC', 'RD', 'RE', 'RF', 'RG', 'RH', 'RI', 'RJ',
        ['XC', 'VALEUR AJOUTÉE', ['TA', 'RA', 'RB', 'TB', 'TC', 'TD', 'TE', 'TF', 'TG', 'TH', 'TI', 'RC', 'RD', 'RE', 'RF', 'RG', 'RH', 'RI', 'RJ']],
        'RK', ['XD', 'EXCÉDENT BRUT D\'EXPLOITATION', ['XC', 'RK']],
        'TJ', 'RL', ['XE', 'RÉSULTAT D\'EXPLOITATION', ['XD', 'TJ', 'RL']],
        'TK', 'TL', 'TM', 'RM', 'RN', ['XF', 'RÉSULTAT FINANCIER', ['TK', 'TL', 'TM', 'RM', 'RN']],
        ['XG', 'RÉSULTAT DES ACTIVITÉS ORDINAIRES', ['XE', 'XF']],
        'TN', 'TO', 'RO', 'RP', ['XH', 'RÉSULTAT HORS ACTIVITÉS ORDINAIRES', ['TN', 'TO', 'RO', 'RP']],
        'RQ', 'RS', ['XI', 'RÉSULTAT NET', ['XG', 'XH', 'RQ', 'RS']],
    ];

    /** Bilan : [réf, libellé, préfixes, règle] ; règle : debit / credit = selon le sens du solde, all = solde signé. */
    public const ASSET_LINES = [
        ['AD', 'Immobilisations incorporelles', ['21'], 'gross'],
        ['AI', 'Immobilisations corporelles', ['22', '23', '24'], 'gross'],
        ['AP', 'Avances et acomptes versés sur immobilisations', ['25'], 'gross'],
        ['AQ', 'Immobilisations financières', ['26', '27'], 'gross'],
        ['BA', 'Actif circulant HAO', ['485', '488'], 'debit'],
        ['BB', 'Stocks et encours', ['3'], 'gross'],
        ['BH', 'Fournisseurs, avances versées', ['409'], 'debit'],
        ['BI', 'Clients', ['41'], 'debit'],
        ['BJ', 'Autres créances', ['4'], 'debit'],
        ['BQ', 'Titres de placement', ['50'], 'debit'],
        ['BR', 'Valeurs à encaisser', ['51'], 'debit'],
        ['BS', 'Banques, chèques postaux, caisse et assimilés', ['5'], 'debit'],
        ['BU', 'Écart de conversion-Actif', ['478'], 'debit'],
    ];

    public const LIABILITY_LINES = [
        ['CA', 'Capital', ['101', '102', '103', '104'], 'all'],
        ['CB', 'Apporteurs capital non appelé (-)', ['109'], 'all'],
        ['CD', 'Primes liées au capital social', ['105'], 'all'],
        ['CE', 'Écarts de réévaluation', ['106'], 'all'],
        ['CF', 'Réserves indisponibles', ['111', '112'], 'all'],
        ['CG', 'Réserves libres', ['113', '118', '11'], 'all'],
        ['CH', 'Report à nouveau (+ ou -)', ['12'], 'all'],
        ['CJ', 'Résultat net de l\'exercice (bénéfice + ou perte -)', ['13'], 'all'],
        ['CL', 'Subventions d\'investissement', ['14'], 'all'],
        ['CM', 'Provisions réglementées', ['15'], 'all'],
        ['DA', 'Emprunts et dettes financières diverses', ['16', '18'], 'all'],
        ['DB', 'Dettes de location-acquisition', ['17'], 'all'],
        ['DC', 'Provisions pour risques et charges', ['19'], 'all'],
        ['DH', 'Dettes circulantes HAO', ['481', '482', '484'], 'credit'],
        ['DI', 'Clients, avances reçues', ['419'], 'credit'],
        ['DJ', 'Fournisseurs d\'exploitation', ['40'], 'credit'],
        ['DK', 'Dettes fiscales et sociales', ['42', '43', '44'], 'credit'],
        ['DM', 'Autres dettes', ['4'], 'credit'],
        ['DN', 'Provisions pour risques à court terme', ['499'], 'credit'],
        ['DQ', 'Banques, crédits d\'escompte', ['565'], 'credit'],
        ['DR', 'Banques, établissements financiers et crédits de trésorerie', ['5'], 'credit'],
        ['DV', 'Écart de conversion-Passif', ['479'], 'credit'],
    ];

    public const ASSET_TOTALS = [
        'AZ' => ['TOTAL ACTIF IMMOBILISÉ', ['AD', 'AI', 'AP', 'AQ']],
        'BK' => ['TOTAL ACTIF CIRCULANT', ['BA', 'BB', 'BH', 'BI', 'BJ']],
        'BT' => ['TOTAL TRÉSORERIE-ACTIF', ['BQ', 'BR', 'BS']],
        'BZ' => ['TOTAL GÉNÉRAL', ['AZ', 'BK', 'BT', 'BU']],
    ];

    public const LIABILITY_TOTALS = [
        'CP' => ['TOTAL CAPITAUX PROPRES ET RESSOURCES ASSIMILÉES', ['CA', 'CB', 'CD', 'CE', 'CF', 'CG', 'CH', 'CJ', 'CL', 'CM']],
        'DD' => ['TOTAL DETTES FINANCIÈRES ET RESSOURCES ASSIMILÉES', ['DA', 'DB', 'DC']],
        'DF' => ['TOTAL RESSOURCES STABLES', ['CP', 'DD']],
        'DP' => ['TOTAL PASSIF CIRCULANT', ['DH', 'DI', 'DJ', 'DK', 'DM', 'DN']],
        'DT' => ['TOTAL TRÉSORERIE-PASSIF', ['DQ', 'DR']],
        'DZ' => ['TOTAL GÉNÉRAL', ['DF', 'DP', 'DT', 'DV']],
    ];

    /** Modèle d'états du référentiel. */
    public static function def(string $ref): array
    {
        if (isset(StatementModels::MODELS[$ref])) {
            return StatementModels::MODELS[$ref] + ['ref' => $ref];
        }

        return [
            'ref' => 'syscohada', 'title_income' => 'Compte de résultat', 'title_sheet' => 'Bilan', 'title_cash' => 'Tableau des flux de trésorerie',
            'income_lines' => self::INCOME_LINES, 'income_layout' => self::INCOME_LAYOUT, 'asset_lines' => self::ASSET_LINES, 'liability_lines' => self::LIABILITY_LINES,
            'asset_totals' => self::ASSET_TOTALS, 'liability_totals' => self::LIABILITY_TOTALS,
            'asset_order' => ['AD', 'AI', 'AP', 'AQ', 'AZ', 'BA', 'BB', 'BH', 'BI', 'BJ', 'BK', 'BQ', 'BR', 'BS', 'BT', 'BU', 'BZ'],
            'liability_order' => ['CA', 'CB', 'CD', 'CE', 'CF', 'CG', 'CH', 'CJ', 'CL', 'CM', 'CP', 'DA', 'DB', 'DC', 'DD', 'DF', 'DH', 'DI', 'DJ', 'DK', 'DM', 'DN', 'DP', 'DQ', 'DR', 'DT', 'DV', 'DZ'],
            'fallback' => ['liability' => 'CG', 'asset2' => 'AI', 'asset3' => 'BB', 'asset_debit' => 'BJ', 'liability_credit' => 'DM', 'report' => 'CH', 'result' => 'CJ'],
            'depreciation' => [['39', 'BB'], ['491', 'BI']], 'class8_split' => true, 'result_key' => 'XI',
            'kpis' => ['revenue' => 'XB', 'margin' => 'XA', 'value_added' => 'XC', 'ebitda' => 'XD', 'operating' => 'XE', 'result' => 'XI'],
            'cash' => StatementModels::CASH_SYSCOHADA, 'official_refs' => true,
        ];
    }

    protected function model(Company $c): array
    {
        return self::def(Referentials::of($c));
    }

    /** Soldes cumulés (débit - crédit) par compte jusqu'à une date incluse, ou mouvements d'une période. */
    public function balances(Company $c, ?string $from, ?string $to, bool $beforeClosing = false, ?string $closingFrom = null): array
    {
        return DB::table('compta_entry_lines as l')->join('compta_entries as e', 'e.id', '=', 'l.entry_id')->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $c->id)->when($beforeClosing, fn ($q) => $q->where('e.kind', '!=', 'closing'))
            // écritures de clôture des exercices antérieurs seulement (celle de la période est ignorée)
            ->when($closingFrom, fn ($q) => $q->where(fn ($w) => $w->where('e.kind', '!=', 'closing')->orWhere('e.date', '<', $closingFrom)))->when($from, fn ($q) => $q->where('e.date', '>=', $from))->when($to, fn ($q) => $q->where('e.date', '<=', $to))
            ->groupBy('a.code')->selectRaw('a.code, SUM(l.debit - l.credit) as s')->pluck('s', 'code')->map(fn ($v) => round((float) $v, 2))->all();
    }

    /** Ligne de rattachement d'un compte : préfixe le plus long parmi les lignes candidates. */
    protected static function match(string $code, array $lines): ?string
    {
        $best = null;
        $len = 0;
        foreach ($lines as [$ref, , $prefixes]) {
            foreach ($prefixes as $p) {
                if (str_starts_with($code, $p) && strlen($p) > $len) {
                    [$best, $len] = [$ref, strlen($p)];
                }
            }
        }

        return $best;
    }

    // ------------------------------------------------------------------ compte de résultat

    public function incomeValues(array $m, array $mov): array
    {
        $lines = $m['income_lines'];
        $v = array_fill_keys(array_column($lines, 0), 0.0);
        $classes = Referentials::resultClasses($m['ref']);
        foreach ($mov as $code => $s) {
            $code = (string) $code;
            if (! in_array($code[0], $classes, true) || abs($s) < 0.005) {
                continue;
            }
            $cands = ! empty($m['class8_split']) && $code[0] === '8'
                ? array_filter($lines, fn ($l) => $l[0][0] === 'T' ? in_array($l[0], ['TN', 'TO'], true) : in_array($l[0], ['RO', 'RP', 'RQ', 'RS'], true))
                : $lines;
            $ref = self::match($code, $cands);
            if ($ref) {
                $v[$ref] += -$s; // produit positif, charge négative (montant signé)
            }
        }
        foreach ($m['income_layout'] as $item) {
            if (is_array($item)) {
                [$ref, , $parts] = $item;
                $v[$ref] = round(array_sum(array_map(fn ($p) => $v[$p] ?? 0, $parts)), 2);
            }
        }

        return array_map(fn ($x) => round($x, 2), $v);
    }

    public function income(Company $c, string $from, string $to): array
    {
        $m = $this->model($c);
        $n = $this->incomeValues($m, $this->balances($c, $from, $to, true));
        [$pf, $pt] = [Carbon::parse($from)->subYear()->toDateString(), Carbon::parse($to)->subYear()->toDateString()];
        $p = $this->incomeValues($m, $this->balances($c, $pf, $pt, true));
        $labels = array_column($m['income_lines'], 1, 0);
        $sense = array_column($m['income_lines'], 3, 0);
        $official = ! empty($m['official_refs']);
        $rows = [];
        foreach ($m['income_layout'] as $item) {
            if (is_array($item)) {
                if (! empty($item[4])) {
                    continue; // agrégat calculé pour les indicateurs, non présenté
                }
                $k = (int) ($item[3] ?? 1);
                $rows[] = ['ref' => $official ? $item[0] : '', 'key' => $item[0], 'label' => $item[1], 'total' => true, 'n' => round($k * $n[$item[0]], 2) + 0.0, 'n1' => round($k * $p[$item[0]], 2) + 0.0];
            } else {
                // charges présentées en positif avec le signe « - » dans la colonne libellé
                $rows[] = ['ref' => $official ? $item : '', 'key' => $item, 'label' => $labels[$item], 'total' => false, 'sign' => $sense[$item] > 0 ? '+' : '-',
                    'n' => round($sense[$item] * $n[$item], 2) + 0.0, 'n1' => round($sense[$item] * $p[$item], 2) + 0.0]; // + 0.0 : pas de « -0 »
            }
        }
        $kpis = [];
        foreach ($m['kpis'] as $name => $key) {
            $kpis[$name] = $key === null ? null : ['n' => $n[$key] ?? 0.0, 'n1' => $p[$key] ?? 0.0];
        }
        $rk = $m['result_key'];

        return ['title' => $m['title_income'], 'referential' => $m['ref'], 'from' => $from, 'to' => $to, 'prev_from' => $pf, 'prev_to' => $pt, 'rows' => $rows,
            'result' => $n[$rk], 'result_n1' => $p[$rk], 'kpis' => $kpis];
    }

    // ------------------------------------------------------------------ bilan

    protected function sheetValues(Company $c, array $m, string $at): array
    {
        $yearStart = FiscalCalendar::startOf($c, $at);
        $resPrefixes = collect($m['liability_lines'])->firstWhere(0, $m['fallback']['result'])[2] ?? [];
        $opening = $this->balances($c, null, Carbon::parse($yearStart)->subDay()->toDateString());
        $pending = -array_sum(array_filter($opening, fn ($v, $k) => collect($resPrefixes)->contains(fn ($p) => str_starts_with((string) $k, $p)), ARRAY_FILTER_USE_BOTH));

        return $this->sheetFrom($m, $this->balances($c, null, $at), $this->balances($c, $yearStart, $at), $pending);
    }

    /**
     * Bilan à partir de soldes : $bal cumulés à la date, $mov mouvements de l'exercice, $pending résultat d'exercices
     * antérieurs clôturés et non affectés (présenté en report à nouveau).
     */
    public function sheetFrom(array $m, array $bal, array $mov, float $pending = 0.0): array
    {
        $assets = array_fill_keys(array_column($m['asset_lines'], 0), ['gross' => 0.0, 'amort' => 0.0]);
        $liab = array_fill_keys(array_column($m['liability_lines'], 0), 0.0);
        $fb = $m['fallback'];
        $classes = Referentials::resultClasses($m['ref']);
        $resultYear = 0.0;
        $prior = 0.0;
        foreach ($bal as $code => $s) {
            $code = (string) $code;
            if (abs($s) < 0.005) {
                continue;
            }
            if (in_array($code[0], $classes, true)) {
                $prior += -($s - ($mov[$code] ?? 0));
                $resultYear += -($mov[$code] ?? 0);

                continue;
            }
            // Amortissements (28) et dépréciations (29) : en déduction de la rubrique d'immobilisations
            if (preg_match('/^(28|29)(\d)/', $code, $mm)) {
                $ref = self::match('2'.$mm[2], $m['asset_lines']) ?? $fb['asset2'];
                $assets[$ref]['amort'] += -$s;

                continue;
            }
            // Dépréciations des stocks et des créances
            $dep = null;
            $len = 0;
            foreach ($m['depreciation'] as [$prefix, $target]) {
                if (str_starts_with($code, $prefix) && strlen($prefix) > $len) {
                    [$dep, $len] = [$target, strlen($prefix)];
                }
            }
            if ($dep) {
                $assets[$dep]['amort'] += -$s;

                continue;
            }
            $debit = $s > 0;
            $c1 = $code[0];
            if ($c1 === '1') {
                $ref = self::match($code, $m['liability_lines']);
                $liab[$ref ?? $fb['liability']] += -$s;
            } elseif ($c1 === '2' || $c1 === '3') {
                $ref = self::match($code, $m['asset_lines']);
                $assets[$ref ?? ($c1 === '2' ? $fb['asset2'] : $fb['asset3'])]['gross'] += $s;
            } elseif ($c1 === '4' || $c1 === '5') {
                if ($debit) {
                    $ref = self::match($code, array_filter($m['asset_lines'], fn ($l) => $l[3] === 'debit'));
                    $assets[$ref ?? $fb['asset_debit']]['gross'] += $s;
                } else {
                    $ref = self::match($code, array_filter($m['liability_lines'], fn ($l) => $l[3] === 'credit'));
                    $liab[$ref ?? $fb['liability_credit']] += -$s;
                }
            }
        }
        $liab[$fb['report']] += $prior;
        $liab[$fb['result']] += $resultYear;
        // Résultat d'un exercice antérieur clôturé mais pas encore affecté : présenté en report à nouveau, pas en résultat de l'exercice
        if ($fb['report'] !== $fb['result'] && abs($pending) > 0.004) {
            $liab[$fb['result']] -= $pending;
            $liab[$fb['report']] += $pending;
        }
        $a = [];
        foreach ($assets as $ref => $x) {
            $a[$ref] = ['gross' => round($x['gross'], 2), 'amort' => round($x['amort'], 2), 'net' => round($x['gross'] - $x['amort'], 2)];
        }
        foreach ($m['asset_totals'] as $ref => [, $parts]) {
            $a[$ref] = ['gross' => round(array_sum(array_map(fn ($p) => $a[$p]['gross'], $parts)), 2), 'amort' => round(array_sum(array_map(fn ($p) => $a[$p]['amort'], $parts)), 2)];
            $a[$ref]['net'] = round($a[$ref]['gross'] - $a[$ref]['amort'], 2);
        }
        $l = array_map(fn ($x) => round($x, 2), $liab);
        foreach ($m['liability_totals'] as $ref => [, $parts]) {
            $l[$ref] = round(array_sum(array_map(fn ($p) => $l[$p], $parts)), 2);
        }

        return [$a, $l];
    }

    public function sheet(Company $c, string $at): array
    {
        $m = $this->model($c);
        [$a, $l] = $this->sheetValues($c, $m, $at);
        $prevAt = Carbon::parse(FiscalCalendar::startOf($c, $at))->subDay()->toDateString();
        [$pa, $pl] = $this->sheetValues($c, $m, $prevAt);
        $assetLabels = array_column($m['asset_lines'], 1, 0);
        $liabLabels = array_column($m['liability_lines'], 1, 0);
        $official = ! empty($m['official_refs']);
        $assets = [];
        foreach ($m['asset_order'] as $ref) {
            $assets[] = ['ref' => $official ? $ref : '', 'key' => $ref, 'label' => $assetLabels[$ref] ?? $m['asset_totals'][$ref][0], 'total' => isset($m['asset_totals'][$ref]),
                'gross' => $a[$ref]['gross'], 'amort' => $a[$ref]['amort'], 'net' => $a[$ref]['net'], 'n1' => $pa[$ref]['net']];
        }
        $liab = [];
        foreach ($m['liability_order'] as $ref) {
            $liab[] = ['ref' => $official ? $ref : '', 'key' => $ref, 'label' => $liabLabels[$ref] ?? $m['liability_totals'][$ref][0], 'total' => isset($m['liability_totals'][$ref]), 'n' => $l[$ref], 'n1' => $pl[$ref]];
        }
        $ta = $a[end($m['asset_order'])]['net'];
        $tl = $l[end($m['liability_order'])];

        return ['title' => $m['title_sheet'], 'referential' => $m['ref'], 'at' => $at, 'prev_at' => $prevAt, 'assets' => $assets, 'liabilities' => $liab,
            'total_assets' => $ta, 'total_liabilities' => $tl, 'balanced' => abs($ta - $tl) < 0.01];
    }

    // ------------------------------------------------------------------ tableau des flux de trésorerie

    public function cashFlow(Company $c, string $from, string $to): array
    {
        $m = $this->model($c);
        if (($m['cash'] ?? null) === 'smt') {
            return $this->receiptsAndPayments($c, $m, $from, $to);
        }
        $before = Carbon::parse($from)->subDay()->toDateString();

        return $this->cashFlowFrom($m, $this->balances($c, null, $before, true), $this->balances($c, null, $to, true), $from, $to);
    }

    /** Tableau des flux (méthode indirecte) à partir des soldes d'ouverture et de clôture ; $extra : flux ajoutés par rubrique (consolidation). */
    public function cashFlowFrom(array $m, array $open, array $close, string $from, string $to, array $extra = []): array
    {
        $cfg = $m['cash'];
        $codes = array_unique(array_merge(array_keys($open), array_keys($close)));
        $f = array_fill_keys(['FA', 'FB', 'FC', 'FD', 'FE', 'FF', 'FG', 'FH', 'FI', 'FK', 'FL', 'FM', 'FO', 'FP'], 0.0);
        $result = 0.0;
        $treso = ['open' => 0.0, 'close' => 0.0];
        $classes = Referentials::resultClasses($m['ref']);
        $cat = function (string $code) use ($cfg) {
            $best = null;
            $len = -1;
            foreach ($cfg['groups'] as $key => $prefixes) {
                foreach ($prefixes as $p) {
                    if (str_starts_with($code, $p) && strlen($p) > $len) {
                        [$best, $len] = [$key, strlen($p)];
                    }
                }
            }

            return $best;
        };
        foreach ($codes as $code) {
            $code = (string) $code;
            $o = $open[$code] ?? 0.0;
            $k = $close[$code] ?? 0.0;
            $effect = -($k - $o); // effet sur la trésorerie (une hausse d'actif consomme de la trésorerie)
            if (str_starts_with($code, $cfg['treasury'])) {
                $treso['open'] += $o;
                $treso['close'] += $k;

                continue;
            }
            if (abs($effect) < 0.005) {
                continue;
            }
            $g = $cat($code);
            if ($g === 'FI' || $g === 'FG_NBV') {
                $f[$g === 'FI' ? 'FI' : 'FG'] += $effect; // prix de cession encaissé / valeur des immobilisations cédées
            } elseif (in_array($code[0], $classes, true)) {
                $result += $effect;
            } elseif ($g === 'TIERS') {
                $f[max($o, $k) > 0 && min($o, $k) >= 0 ? 'FD' : 'FE'] += $effect;
            } else {
                $f[$g ?? 'FP'] += $effect;
            }
        }
        $f['FA'] += $result; // CAFG = résultat net + dotations nettes, hors résultat de cession
        foreach ($extra as $k => $v) {
            $f[$k] += $v;
        }
        $f = array_map(fn ($x) => round($x, 2), $f);
        $zb = round($f['FA'] + $f['FB'] + $f['FC'] + $f['FD'] + $f['FE'], 2);
        $zc = round($f['FF'] + $f['FG'] + $f['FH'] + $f['FI'], 2);
        $zd = round($f['FK'] + $f['FL'] + $f['FM'], 2);
        $ze = round($f['FO'] + $f['FP'], 2);
        $zf = round($zd + $ze, 2);
        $zg = round($zb + $zc + $zf, 2);
        $za = round($treso['open'], 2);
        $L = $cfg['labels'] ?? [];
        $rows = [
            ['ZA', 'Trésorerie nette au début de la période', $za, true],
            ['', 'Flux de trésorerie provenant des activités opérationnelles', null, true],
            ['FA', $L['FA'] ?? 'Capacité d\'autofinancement globale (CAFG)', $f['FA'], false],
            ['FB', $L['FB'] ?? 'Variation de l\'actif et du passif circulant HAO', $f['FB'], false],
            ['FC', 'Variation des stocks', $f['FC'], false],
            ['FD', 'Variation des créances', $f['FD'], false],
            ['FE', 'Variation du passif circulant', $f['FE'], false],
            ['ZB', 'Flux de trésorerie provenant des activités opérationnelles', $zb, true],
            ['', 'Flux de trésorerie provenant des activités d\'investissement', null, true],
            ['FF', 'Décaissements liés aux acquisitions d\'immobilisations incorporelles', $f['FF'], false],
            ['FG', 'Décaissements liés aux acquisitions d\'immobilisations corporelles', $f['FG'], false],
            ['FH', 'Décaissements liés aux acquisitions d\'immobilisations financières', $f['FH'], false],
            ['FI', 'Encaissements liés aux cessions d\'immobilisations', $f['FI'], false],
            ['ZC', 'Flux de trésorerie provenant des activités d\'investissement', $zc, true],
            ['', 'Flux de trésorerie provenant du financement par les capitaux propres', null, true],
            ['FK', $L['FK'] ?? 'Augmentations de capital et apports', $f['FK'], false],
            ['FL', 'Subventions d\'investissement reçues', $f['FL'], false],
            ['FM', 'Prélèvements, dividendes et autres mouvements des capitaux propres', $f['FM'], false],
            ['ZD', 'Flux de trésorerie provenant des capitaux propres', $zd, true],
            ['', 'Flux de trésorerie provenant du financement par les capitaux étrangers', null, true],
            ['FO', 'Emprunts (nets des remboursements)', $f['FO'], false],
            ['FP', 'Autres dettes financières', $f['FP'], false],
            ['ZE', 'Flux de trésorerie provenant des capitaux étrangers', $ze, true],
            ['ZF', 'Flux de trésorerie provenant des activités de financement', $zf, true],
            ['ZG', 'VARIATION DE LA TRÉSORERIE NETTE DE LA PÉRIODE', $zg, true],
            ['ZH', 'Trésorerie nette à la fin de la période', round($za + $zg, 2), true],
        ];
        $official = ! empty($m['official_refs']);

        return ['title' => $m['title_cash'], 'referential' => $m['ref'], 'from' => $from, 'to' => $to,
            'rows' => array_map(fn ($r) => ['ref' => $official ? $r[0] : '', 'key' => $r[0], 'label' => $r[1], 'amount' => $r[2], 'total' => $r[3]], $rows),
            'opening' => $za, 'closing' => round($treso['close'], 2), 'variation' => $zg, 'check' => abs($za + $zg - round($treso['close'], 2)) < 0.01];
    }

    /**
     * Système minimal de trésorerie : état des recettes et des dépenses de la période, ventilées selon la nature
     * de la contrepartie de chaque mouvement de trésorerie (classe 5).
     */
    protected function receiptsAndPayments(Company $c, array $m, string $from, string $to): array
    {
        $before = Carbon::parse($from)->subDay()->toDateString();
        $open = round(array_sum(array_filter($this->balances($c, null, $before), fn ($v, $k) => str_starts_with((string) $k, '5'), ARRAY_FILTER_USE_BOTH)), 2);
        $lines = DB::table('compta_entry_lines as l')->join('compta_entries as e', 'e.id', '=', 'l.entry_id')->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $c->id)->whereBetween('e.date', [$from, $to])->get(['l.entry_id', 'a.code', 'l.debit', 'l.credit'])->groupBy('entry_id');
        $groups = [
            'in' => ['R1' => ['Encaissements sur ventes de marchandises', ['701']], 'R2' => ['Encaissements sur prestations et produits', ['70']], 'R3' => ['Encaissements des clients (créances)', ['41']],
                'R4' => ['Apports de l\'exploitant et emprunts reçus', ['10', '16', '46']], 'R5' => ['Subventions et autres recettes', ['7', '8', '4', '1', '2', '3']]],
            'out' => ['D1' => ['Achats de marchandises et matières', ['601', '602', '603']], 'D2' => ['Autres achats et fournitures', ['60']], 'D3' => ['Paiement des fournisseurs (dettes)', ['40']],
                'D4' => ['Loyers, transports et services extérieurs', ['61', '62', '63']], 'D5' => ['Impôts et taxes', ['64', '44']], 'D6' => ['Salaires et charges sociales', ['66', '42', '43']],
                'D7' => ['Investissements', ['2']], 'D8' => ['Remboursements d\'emprunts, prélèvements de l\'exploitant', ['16', '10', '46']], 'D9' => ['Autres dépenses', ['6', '8', '4', '1', '3', '7']]],
        ];
        $sum = array_fill_keys(array_merge(array_keys($groups['in']), array_keys($groups['out'])), 0.0);
        foreach ($lines as $entry) {
            $cash = 0.0;
            foreach ($entry as $x) {
                if (str_starts_with((string) $x->code, '5')) {
                    $cash += (float) $x->debit - (float) $x->credit;
                }
            }
            if (abs($cash) < 0.005) {
                continue; // virements internes ou écriture sans trésorerie
            }
            // Contrepartie principale : la ligne hors trésorerie au montant le plus fort
            $other = collect($entry)->reject(fn ($x) => str_starts_with((string) $x->code, '5'))->sortByDesc(fn ($x) => abs((float) $x->debit - (float) $x->credit))->first();
            $side = $cash > 0 ? 'in' : 'out';
            $key = array_key_last($groups[$side]);
            if ($other) {
                $best = -1;
                foreach ($groups[$side] as $k => [, $prefixes]) {
                    foreach ($prefixes as $p) {
                        if (str_starts_with((string) $other->code, $p) && strlen($p) > $best) {
                            [$key, $best] = [$k, strlen($p)];
                        }
                    }
                }
            }
            $sum[$key] += abs($cash);
        }
        $tin = round(array_sum(array_intersect_key($sum, $groups['in'])), 2);
        $tout = round(array_sum(array_intersect_key($sum, $groups['out'])), 2);
        $rows = [['ZA', 'Trésorerie au début de la période', $open, true], ['', 'Recettes', null, true]];
        foreach ($groups['in'] as $k => [$label]) {
            $rows[] = [$k, $label, round($sum[$k], 2), false];
        }
        $rows[] = ['XR', 'TOTAL DES RECETTES', $tin, true];
        $rows[] = ['', 'Dépenses', null, true];
        foreach ($groups['out'] as $k => [$label]) {
            $rows[] = [$k, $label, round($sum[$k], 2), false];
        }
        $rows[] = ['XD', 'TOTAL DES DÉPENSES', $tout, true];
        $rows[] = ['XS', 'SOLDE DE LA PÉRIODE (RECETTES − DÉPENSES)', round($tin - $tout, 2), true];
        $rows[] = ['ZH', 'Trésorerie à la fin de la période', round($open + $tin - $tout, 2), true];
        $close = round(array_sum(array_filter($this->balances($c, null, $to), fn ($v, $k) => str_starts_with((string) $k, '5'), ARRAY_FILTER_USE_BOTH)), 2);

        return ['title' => $m['title_cash'], 'referential' => $m['ref'], 'from' => $from, 'to' => $to,
            'rows' => array_map(fn ($r) => ['ref' => '', 'key' => $r[0], 'label' => $r[1], 'amount' => $r[2], 'total' => $r[3]], $rows),
            'opening' => $open, 'closing' => $close, 'variation' => round($tin - $tout, 2), 'check' => abs($open + $tin - $tout - $close) < 0.01];
    }

    /** Lignes à plat pour l'export Excel. */
    public function exportRows(Company $c, string $kind, string $from, string $to): array
    {
        return match ($kind) {
            'income' => (function () use ($c, $from, $to) {
                $i = $this->income($c, $from, $to);

                return [['Réf.', $i['title'], 'Exercice N', 'Exercice N-1'], array_map(fn ($r) => [$r['ref'], ($r['sign'] ?? '') === '-' ? '(-) '.$r['label'] : $r['label'], $r['n'], $r['n1']], $i['rows'])];
            })(),
            'sheet' => (function () use ($c, $to) {
                $s = $this->sheet($c, $to);
                $rows = [['', 'ACTIF', '', '', '', '']];
                foreach ($s['assets'] as $r) {
                    $rows[] = [$r['ref'], $r['label'], $r['gross'], $r['amort'], $r['net'], $r['n1']];
                }
                $rows[] = ['', '', '', '', '', ''];
                $rows[] = ['', 'PASSIF', '', '', '', ''];
                foreach ($s['liabilities'] as $r) {
                    $rows[] = [$r['ref'], $r['label'], '', '', $r['n'], $r['n1']];
                }

                return [['Réf.', $s['title'].' au '.Carbon::parse($to)->format('d/m/Y'), 'Brut', 'Amort. et dépréc.', 'Net N', 'Net N-1'], $rows];
            })(),
            'cashflow' => (function () use ($c, $from, $to) {
                $f = $this->cashFlow($c, $from, $to);

                return [['Réf.', $f['title'], 'Montant'], array_map(fn ($r) => [$r['ref'], $r['label'], $r['amount'] ?? ''], $f['rows'])];
            })(),
            default => throw new \DomainException('État inconnu.'),
        };
    }
}
