<?php

namespace App\Modules\Paie;

/**
 * Moteur de paie générique piloté par un profil pays (UEMOA, CEMAC, profil libre…).
 * Fonction pure : le profil décrit les cotisations, l'impôt sur salaires, les taxes salariales
 * et patronales ; aucune règle d'un pays n'est codée en dur.
 *
 * Profil (voir countries/*.json) :
 *   smig, days_basis, transport_exempt, seniority {start_years, first_rate, per_year, max}
 *   contributions [{code, label, employee_rate, employer_rate, ceiling, floor}]      — % de la base cotisable (plafond mensuel)
 *   fixed [{code, label, employee_amount, employer_amount, per: employee|person, max_children}]
 *   income_tax {label, period: monthly|annual, deduct_social, professional {rate, cap}, fixed_abatement,
 *               dependant_abatement {amount, max}, brackets [{up_to, rate}], family {method: none|quotient|reduction|per_half_part, parts {...}, reductions [...], amount}}
 *               (montants cap / fixed_abatement / tranches exprimés dans la période du barème)
 *   employee_taxes [{code, label, base: gross|taxable|tax_base|income_tax|net, rate | table [{up_to, amount}] | brackets, period, ceiling, months}]
 *   employer_taxes [{code, label, base: gross|taxable|social, rate, expat_rate, ceiling}]
 *
 * Mêmes entrées et même format de sortie que PayrollCalculator (Côte d'Ivoire).
 */
class CountryPayrollCalculator
{
    public function __construct(protected array $p) {}

    public function profile(): array
    {
        return $this->p;
    }

    public function compute(array $in): array
    {
        $p = $this->p;
        $r = fn ($v) => (float) round($v, 0);

        $basis = max(1, (int) ($p['days_basis'] ?? 30));
        $days = max(0, min($basis, (float) ($in['days_worked'] ?? $basis)));
        $ratio = $days / $basis;

        $lines = [];
        $gross = $taxable = $social = 0.0;
        $add = function (string $code, string $label, float $amount, bool $isTaxable, bool $isSocial, ?string $base = null, ?float $rate = null) use (&$lines, &$gross, &$taxable, &$social) {
            if (abs($amount) < 0.5) {
                return;
            }
            $lines[] = ['code' => $code, 'label' => $label, 'kind' => 'gain', 'base' => $base, 'rate' => $rate, 'amount' => $amount, 'taxable' => $isTaxable, 'social' => $isSocial];
            $gross += $amount;
            $isTaxable && $taxable += $amount;
            $isSocial && $social += $amount;
        };

        $basePaid = $r((float) ($in['base_salary'] ?? 0) * $ratio);
        $add('base', 'Salaire de base', $basePaid, true, true, $days < $basis ? "$days j / $basis" : null);

        // Prime d'ancienneté (convention collective)
        $years = (int) ($in['seniority_years'] ?? 0);
        $sen = $p['seniority'] ?? null;
        if ($sen && ($in['seniority_auto'] ?? true) && $years >= (int) ($sen['start_years'] ?? 2)) {
            $start = (int) ($sen['start_years'] ?? 2);
            $rate = min((float) ($sen['max'] ?? 25), (float) ($sen['first_rate'] ?? $start) + ($years - $start) * (float) ($sen['per_year'] ?? 1));
            $add('anciennete', "Prime d'ancienneté ($years ans)", $r($basePaid * $rate / 100), true, true, null, $rate);
        }

        $deductions = $reimbursements = [];
        foreach ((array) ($in['items'] ?? []) as $it) {
            $amount = $r((float) ($it['amount'] ?? 0));
            $kind = $it['kind'] ?? 'gain';
            if ($kind === 'reimbursement') {
                $amount > 0 && $reimbursements[] = ['code' => 'frais', 'label' => (string) ($it['label'] ?? 'Remboursement de frais'), 'kind' => 'reimbursement', 'amount' => $amount];

                continue;
            }
            if ($kind === 'deduction') {
                $amount > 0 && $deductions[] = ['code' => 'retenue', 'label' => (string) ($it['label'] ?? 'Retenue'), 'kind' => 'deduction', 'amount' => $amount];

                continue;
            }
            $add('prime', (string) ($it['label'] ?? 'Prime'), $amount, (bool) ($it['taxable'] ?? true), (bool) ($it['social'] ?? true));
        }

        $transport = $r((float) ($in['transport'] ?? 0) * $ratio);
        if ($transport > 0) {
            $limit = $p['transport_exempt'] ?? null;
            $exempt = $limit === null ? $transport : min($transport, (float) $limit);
            $add('transport', 'Indemnité de transport', $exempt, false, false);
            $transport > $exempt && $add('transport_imp', 'Transport (part imposable)', $transport - $exempt, true, true);
        }

        $gross = $r($gross);
        $taxable = $r(max(0, $taxable));
        $social = $r(max(0, $social));
        $deductionTotal = array_sum(array_column($deductions, 'amount'));
        $reimbursementTotal = array_sum(array_column($reimbursements, 'amount'));

        $insEmp = $r(max(0, (float) ($in['insurance_employee'] ?? 0)));
        $insCo = $r(max(0, (float) ($in['insurance_employer'] ?? 0)));
        $insLabel = 'Assurance santé'.(! empty($in['insurance_name']) ? ' ('.$in['insurance_name'].')' : '');
        $insW = $insEmp > 0 ? [['code' => 'assurance', 'label' => $insLabel, 'base' => null, 'rate' => null, 'amount' => $insEmp, 'group' => 'insurance']] : [];
        $insE = $insCo > 0 ? [['code' => 'assurance', 'label' => $insLabel.' part patronale', 'base' => null, 'rate' => null, 'amount' => $insCo, 'group' => 'insurance']] : [];

        if (! ($in['declared'] ?? true)) {
            // Non déclaré : payé au montant convenu, sans cotisations ni impôt
            return [
                'days_worked' => $days, 'declared' => false, 'country' => $p['code'] ?? null,
                'gains' => $lines, 'withholdings' => $insW, 'deductions' => $deductions, 'reimbursements' => $reimbursements, 'employer' => $insE,
                'totals' => $this->totals([
                    'gross' => $gross, 'withholdings' => $insEmp, 'deductions' => $deductionTotal, 'reimbursements' => $reimbursementTotal,
                    'net' => $gross - $insEmp - $deductionTotal + $reimbursementTotal, 'insurance_employee' => $insEmp, 'insurance_employer' => $insCo,
                    'employer_total' => $insCo, 'cost' => $gross + $insCo,
                ]),
            ];
        }

        $withholdings = [];
        $employer = [];
        $empSocial = $coSocial = 0.0;

        // ---- Cotisations sociales en % (plafond mensuel, rapporté au prorata si demandé) ----
        foreach ((array) ($p['contributions'] ?? []) as $c) {
            $base = $social;
            if (isset($c['floor']) && $c['floor'] !== null && $base > 0) {
                $base = max($base, (float) $c['floor']);
            }
            if (isset($c['ceiling']) && $c['ceiling'] !== null) {
                $base = min($base, (float) $c['ceiling']);
            }
            $e = $r($base * (float) ($c['employee_rate'] ?? 0) / 100);
            $co = $r($base * (float) ($c['employer_rate'] ?? 0) / 100);
            if ($e > 0) {
                $withholdings[] = ['code' => $c['code'], 'label' => $c['label'], 'base' => $base, 'rate' => (float) $c['employee_rate'], 'amount' => $e, 'group' => 'social'];
                $empSocial += $e;
            }
            if ($co > 0) {
                $employer[] = ['code' => $c['code'], 'label' => $c['label'], 'base' => $base, 'rate' => (float) $c['employer_rate'], 'amount' => $co, 'group' => 'social'];
                $coSocial += $co;
            }
        }

        // ---- Cotisations forfaitaires (CMU, AMU…) ----
        foreach ((array) ($p['fixed'] ?? []) as $f) {
            if (($f['code'] ?? '') === 'cmu' && ! ($in['cmu'] ?? true)) {
                continue;
            }
            $n = ($f['per'] ?? 'employee') === 'person'
                ? 1 + (! empty($in['spouse_dependent']) ? 1 : 0) + min((int) ($in['children_count'] ?? 0), (int) ($f['max_children'] ?? 99))
                : 1;
            $label = $f['label'].($n > 1 ? " ($n pers.)" : '');
            $e = $r($n * (float) ($f['employee_amount'] ?? 0));
            $co = $r($n * (float) ($f['employer_amount'] ?? 0));
            if ($e > 0) {
                $withholdings[] = ['code' => $f['code'], 'label' => $label, 'base' => null, 'rate' => null, 'amount' => $e, 'group' => 'social'];
                $empSocial += $e;
            }
            if ($co > 0) {
                $employer[] = ['code' => $f['code'], 'label' => $label, 'base' => null, 'rate' => null, 'amount' => $co, 'group' => 'social'];
                $coSocial += $co;
            }
        }

        // ---- Impôt sur les salaires ----
        $it = (array) ($p['income_tax'] ?? []);
        $parts = $this->parts($in, (array) ($it['family']['parts'] ?? []));
        [$incomeTax, $taxBase, $taxDetail] = $this->incomeTax($it, $taxable, $empSocial, $parts, $in);
        $incomeTax = $r($incomeTax);
        $taxBase = $r($taxBase);
        if ($incomeTax > 0 || ! empty($it['brackets'])) {
            $withholdings[] = ['code' => $it['code'] ?? 'impot', 'label' => $it['label'] ?? 'Impôt sur salaire', 'base' => $taxBase, 'rate' => null, 'amount' => $incomeTax, 'group' => 'tax', 'detail' => $taxDetail];
        }

        // ---- Autres prélèvements salariaux ----
        $otherTax = 0.0;
        foreach ((array) ($p['employee_taxes'] ?? []) as $t) {
            $amount = $r($this->levy($t, ['gross' => $gross, 'taxable' => $taxable, 'tax_base' => $taxBase, 'income_tax' => $incomeTax, 'social' => $social, 'net' => $gross - $empSocial - $incomeTax, 'net_social' => max(0, $taxable - $empSocial)], $in));
            if ($amount > 0) {
                $withholdings[] = ['code' => $t['code'], 'label' => $t['label'], 'base' => null, 'rate' => isset($t['rate']) ? (float) $t['rate'] : null, 'amount' => $amount, 'group' => 'tax'];
                $otherTax += $amount;
            }
        }

        // ---- Taxes patronales ----
        $coTax = 0.0;
        foreach ((array) ($p['employer_taxes'] ?? []) as $t) {
            $rate = ! empty($in['is_expatriate']) && isset($t['expat_rate']) ? (float) $t['expat_rate'] : (float) ($t['rate'] ?? 0);
            $base = match ($t['base'] ?? 'gross') {
                'taxable' => $taxable, 'social' => $social, default => $gross,
            };
            if (isset($t['ceiling']) && $t['ceiling'] !== null) {
                $base = min($base, (float) $t['ceiling']);
            }
            $amount = isset($t['amount']) ? (float) $t['amount'] : $r($base * $rate / 100);
            if ($amount > 0) {
                $employer[] = ['code' => $t['code'], 'label' => $t['label'], 'base' => isset($t['amount']) ? null : $base, 'rate' => isset($t['amount']) ? null : $rate, 'amount' => $amount, 'group' => 'tax'];
                $coTax += $amount;
            }
        }

        $withholdings = array_merge($withholdings, $insW);
        $employer = array_merge($employer, $insE);
        $withholdingTotal = $empSocial + $incomeTax + $otherTax + $insEmp;

        return [
            'days_worked' => $days, 'declared' => true, 'country' => $p['code'] ?? null,
            'gains' => $lines, 'withholdings' => $withholdings, 'deductions' => $deductions, 'reimbursements' => $reimbursements, 'employer' => $employer,
            'totals' => $this->totals([
                'gross' => $gross, 'taxable' => $taxable, 'social_base' => $social, 'tax_base' => $taxBase, 'parts' => $parts,
                // Clés communes avec la paie ivoirienne (écritures comptables, livre de paie)
                'cnps_salarie' => $empSocial, 'its_brut' => $taxDetail['brut'] ?? $incomeTax, 'ricf' => $taxDetail['reduction'] ?? 0, 'its' => $incomeTax + $otherTax,
                'income_tax' => $incomeTax, 'other_taxes' => $otherTax,
                'withholdings' => $withholdingTotal, 'deductions' => $deductionTotal, 'reimbursements' => $reimbursementTotal,
                'net' => $gross - $withholdingTotal - $deductionTotal + $reimbursementTotal,
                'employer_social' => $coSocial, 'employer_tax' => $coTax, 'insurance_employee' => $insEmp, 'insurance_employer' => $insCo,
                'employer_total' => $coSocial + $coTax + $insCo, 'cost' => $gross + $coSocial + $coTax + $insCo,
            ]),
        ];
    }

    /** Totaux complets (les clés absentes valent 0) pour rester compatible avec le livre de paie. */
    protected function totals(array $t): array
    {
        $keys = ['gross', 'taxable', 'social_base', 'tax_base', 'parts', 'base_retraite', 'base_autres', 'cnps_salarie', 'cmu_salarie', 'cmu_persons', 'its_brut', 'ricf', 'its',
            'income_tax', 'other_taxes', 'withholdings', 'deductions', 'reimbursements', 'net', 'retraite_employeur', 'pf', 'maternite', 'at', 'cmu_employeur', 'contribution',
            'fdfp_ta', 'fdfp_fpc', 'employer_social', 'employer_tax', 'insurance_employee', 'insurance_employer', 'employer_total', 'cost'];

        return array_map(fn ($v) => is_float($v) || is_int($v) ? (float) $v : $v, $t + array_fill_keys($keys, 0.0));
    }

    /** Nombre de parts : celui de la fiche, sinon calculé selon les règles du pays. */
    public function parts(array $in, array $rules): float
    {
        $max = (float) ($rules['max'] ?? 5);
        if (! empty($in['parts'])) {
            return max(1, min($max, (float) $in['parts']));
        }
        $married = ($in['marital_status'] ?? 'single') === 'married';
        $children = (int) ($in['children_count'] ?? 0);
        $n = $married ? (float) ($rules['married'] ?? 2) : (float) ($rules['single'] ?? 1);
        if (! $married && $children > 0 && isset($rules['single_parent'])) {
            $n = (float) $rules['single_parent'];
        }
        $n += $children * (float) ($rules['per_child'] ?? 0.5);

        return max(1, min($max, $n));
    }

    /** @return array{0: float, 1: float, 2: array} impôt mensuel, base mensuelle, détail */
    protected function incomeTax(array $it, float $taxable, float $empSocial, float $parts, array $in): array
    {
        if (empty($it['brackets'])) {
            return [0.0, 0.0, []];
        }
        $annual = ($it['period'] ?? 'monthly') === 'annual';
        $k = $annual ? 12 : 1;
        // Base dans la période du barème
        if (isset($it['exempt_up_to']) && $it['exempt_up_to'] !== null && $taxable <= (float) $it['exempt_up_to']) {
            return [0.0, 0.0, ['exempt' => true]];
        }
        $base = $taxable * $k;
        // Abattement pour frais professionnels calculé sur le brut (Cameroun) ou après cotisations (par défaut)
        $abatOnGross = ($it['professional']['base'] ?? null) === 'gross';
        $abat = 0.0;
        if ($abatOnGross && ! empty($it['professional']['rate'])) {
            $abat = $base * (float) $it['professional']['rate'] / 100;
            if (isset($it['professional']['cap']) && $it['professional']['cap'] !== null) {
                $abat = min($abat, (float) $it['professional']['cap']);
            }
        }
        if (! empty($it['deduct_social'])) {
            $base -= $empSocial * $k;
        }
        if ($abatOnGross) {
            $base -= $abat;
        } elseif (! empty($it['professional']['rate'])) {
            $abat = $base * (float) $it['professional']['rate'] / 100;
            if (isset($it['professional']['cap']) && $it['professional']['cap'] !== null) {
                $abat = min($abat, (float) $it['professional']['cap']);
            }
            $base -= $abat;
        }
        $base -= (float) ($it['fixed_abatement'] ?? 0);
        // Abattement par personne à charge (enfants et conjoint sans revenus)
        if (! empty($it['dependant_abatement']['amount'])) {
            $dep = min((int) ($it['dependant_abatement']['max'] ?? 99), (int) ($in['children_count'] ?? 0) + (! empty($in['spouse_dependent']) ? 1 : 0));
            $base -= $dep * (float) $it['dependant_abatement']['amount'];
        }
        $base = max(0, $base);
        if (! empty($it['round_base'])) {
            $base = floor($base / (float) $it['round_base']) * (float) $it['round_base'];
        }

        $fam = (array) ($it['family'] ?? []);
        $method = $fam['method'] ?? 'none';
        $brut = $method === 'quotient'
            ? $parts * self::bareme($it['brackets'], $base / $parts)
            : self::bareme($it['brackets'], $base);
        $reduction = 0.0;
        if ($method === 'quotient') {
            // Plafonnement éventuel de l'avantage par demi-part
            if (! empty($fam['max_benefit_per_half_part'])) {
                $without = self::bareme($it['brackets'], $base / (float) ($fam['parts']['single'] ?? 1)) * (float) ($fam['parts']['single'] ?? 1);
                $cap = ($parts - (float) ($fam['parts']['single'] ?? 1)) * 2 * (float) $fam['max_benefit_per_half_part'];
                $brut = max($brut, $without - $cap);
            }
        } elseif ($method === 'per_half_part') {
            $reduction = max(0, $parts - 1) * 2 * (float) ($fam['amount'] ?? 0);
        } elseif ($method === 'reduction') {
            // Réduction selon le nombre de parts (ou de personnes à charge) : % de l'impôt borné, ou montant
            $key = ($fam['by'] ?? 'parts') === 'dependants'
                ? (! empty($in['spouse_dependent']) ? 1 : 0) + (int) ($in['children_count'] ?? 0)
                : $parts;
            $row = null;
            if (isset($fam['per_child_rate']) || isset($fam['married_rate'])) {
                // Réduction en % : conjoint + par enfant (ex. Mali : marié 10 %, +2,5 % par enfant, 10 enfants au plus)
                $rate = (($in['marital_status'] ?? '') === 'married' ? (float) ($fam['married_rate'] ?? 0) : 0)
                    + min((int) ($in['children_count'] ?? 0), (int) ($fam['max_children'] ?? 99)) * (float) ($fam['per_child_rate'] ?? 0);
                $row = ['rate' => $rate];
            }
            foreach ($row ? [] : (array) ($fam['reductions'] ?? []) as $red) {
                if ((float) ($red['from'] ?? 0) <= $key + 1e-9) {
                    $row = $red;
                }
            }
            if ($row) {
                $reduction = isset($row['amount']) ? (float) $row['amount'] : $brut * (float) ($row['rate'] ?? 0) / 100;
                if (isset($row['min'])) {
                    $reduction = max($reduction, (float) $row['min']);
                }
                if (isset($row['max'])) {
                    $reduction = min($reduction, (float) $row['max']);
                }
            }
        }
        $tax = max(0, $brut - $reduction);
        if (isset($it['min_tax']) && $base > 0) {
            $tax = max($tax, (float) $it['min_tax']);
        }

        return [$tax / $k, $base / $k, ['brut' => round($brut / $k), 'reduction' => round(min($brut, $reduction) / $k), 'parts' => $parts, 'abattement' => round($abat / $k), 'period' => $annual ? 'annual' : 'monthly']];
    }

    /** Prélèvement salarial : % d'une base, % de l'impôt, ou montant selon un barème (mensuel ou annuel). */
    protected function levy(array $t, array $bases, array $in): float
    {
        if (! empty($t['only_expat']) && empty($in['is_expatriate'])) {
            return 0;
        }
        if (! empty($t['only_national']) && ! empty($in['is_expatriate'])) {
            return 0;
        }
        // Prélèvement de certains mois seulement (redevance audiovisuelle…)
        if (! empty($t['months']) && ! in_array((int) ($in['month'] ?? 0), array_map('intval', (array) $t['months']), true)) {
            return 0;
        }
        $annual = ($t['period'] ?? 'monthly') === 'annual';
        $base = (float) ($bases[$t['base'] ?? 'gross'] ?? 0);
        if (! empty($t['table'])) {
            $b = $annual ? $base * 12 : $base;
            $amount = 0.0;
            foreach ($t['table'] as $row) {
                $amount = (float) $row['amount'];
                if ($row['up_to'] === null || $b <= (float) $row['up_to']) {
                    break;
                }
            }

            return $annual ? $amount / 12 : $amount;
        }
        if (! empty($t['brackets'])) {
            $b = $annual ? $base * 12 : $base;

            return self::bareme($t['brackets'], $b) / ($annual ? 12 : 1);
        }
        if (isset($t['ceiling']) && $t['ceiling'] !== null) {
            $base = min($base, (float) $t['ceiling']);
        }

        return isset($t['amount']) ? (float) $t['amount'] : $base * (float) ($t['rate'] ?? 0) / 100;
    }

    /** Barème progressif par tranches cumulées. */
    public static function bareme(array $brackets, float $base): float
    {
        $tax = 0.0;
        $floor = 0.0;
        foreach ($brackets as $t) {
            $ceil = ($t['up_to'] ?? null) === null ? INF : (float) $t['up_to'];
            if ($base > $floor) {
                // Tranche forfaitaire (ex. Congo : 1 200 F pour la première tranche) ou proportionnelle
                $tax += isset($t['fixed']) ? (float) $t['fixed'] : (min($base, $ceil) - $floor) * (float) ($t['rate'] ?? 0) / 100;
            }
            $floor = $ceil;
            if ($base <= $ceil) {
                break;
            }
        }

        return $tax;
    }
}
