<?php

namespace App\Modules\Paie;

/**
 * Calcul d'un bulletin de paie Côte d'Ivoire. Fonction pure : mêmes entrées → même résultat,
 * aucun accès à la base (testable, rejouable).
 *
 * $input :
 *   base_salary, days_worked (sur days_basis), transport, seniority_years,
 *   parts (fiscales), is_expatriate, spouse_dependent, children_count,
 *   declared (déclaré à la CNPS, défaut oui), cmu (adhérent CMU, défaut oui),
 *   insurance_employee / insurance_employer (assurance santé mensuelle), insurance_name,
 *   items: [{label, amount, kind: gain|deduction|reimbursement, taxable: bool, social: bool}]
 *
 * Non déclaré (stagiaire, prestataire…) : payé au montant convenu, sans cotisations ni ITS ni charges patronales.
 */
class PayrollCalculator
{
    public function __construct(protected array $s) {}

    public function compute(array $in): array
    {
        $s = $this->s;
        $r = fn ($v) => (float) round($v, 0); // franc CFA : pas de centimes

        $basis = max(1, (int) $s['days_basis']);
        $days = max(0, min($basis, (float) ($in['days_worked'] ?? $basis)));
        $ratio = $days / $basis;

        $lines = [];
        $gross = 0.0;
        $taxable = 0.0;
        $social = 0.0;

        $add = function (string $code, string $label, float $amount, bool $isTaxable, bool $isSocial, ?string $base = null, ?float $rate = null) use (&$lines, &$gross, &$taxable, &$social) {
            if (abs($amount) < 0.5) {
                return;
            }
            $lines[] = ['code' => $code, 'label' => $label, 'kind' => 'gain', 'base' => $base, 'rate' => $rate, 'amount' => $amount, 'taxable' => $isTaxable, 'social' => $isSocial];
            $gross += $amount;
            if ($isTaxable) {
                $taxable += $amount;
            }
            if ($isSocial) {
                $social += $amount;
            }
        };

        $baseSalary = (float) ($in['base_salary'] ?? 0);
        $basePaid = $r($baseSalary * $ratio);
        $add('base', 'Salaire de base', $basePaid, true, true, $days < $basis ? "$days j / $basis" : null);

        // Prime d'ancienneté
        $years = (int) ($in['seniority_years'] ?? 0);
        if (! empty($s['anciennete_auto']) && $years >= 2) {
            $rate = (float) min((float) $s['anciennete_max'], $years);
            $add('anciennete', "Prime d'ancienneté ($years ans)", $r($basePaid * $rate / 100), true, true, null, $rate);
        }

        // Éléments variables / primes
        $deductions = [];
        $reimbursements = [];
        foreach ((array) ($in['items'] ?? []) as $it) {
            $amount = $r((float) ($it['amount'] ?? 0));
            if (($it['kind'] ?? 'gain') === 'reimbursement') {
                // Remboursement de frais (notes de frais) : hors salaire, ni imposable ni cotisable, ajouté au net
                if ($amount > 0) {
                    $reimbursements[] = ['code' => 'frais', 'label' => (string) ($it['label'] ?? 'Remboursement de frais'), 'kind' => 'reimbursement', 'amount' => $amount];
                }

                continue;
            }
            if (($it['kind'] ?? 'gain') === 'deduction') {
                if ($amount > 0) {
                    $deductions[] = ['code' => 'retenue', 'label' => (string) ($it['label'] ?? 'Retenue'), 'kind' => 'deduction', 'amount' => $amount];
                }

                continue;
            }
            $add('prime', (string) ($it['label'] ?? 'Prime'), $amount, (bool) ($it['taxable'] ?? true), (bool) ($it['social'] ?? true));
        }

        // Indemnité de transport : exonérée jusqu'au plafond, imposable au-delà
        $transport = $r((float) ($in['transport'] ?? 0) * $ratio);
        if ($transport > 0) {
            $exempt = min($transport, (float) $s['transport_exonere']);
            $add('transport', 'Indemnité de transport', $exempt, false, false);
            if ($transport > $exempt) {
                $add('transport_imp', 'Transport (part imposable)', $transport - $exempt, true, true);
            }
        }

        $gross = $r($gross);
        $taxable = $r(max(0, $taxable));
        $social = $r(max(0, $social));

        $declared = (bool) ($in['declared'] ?? true);
        $insEmp = $r(max(0, (float) ($in['insurance_employee'] ?? 0)));
        $insCo = $r(max(0, (float) ($in['insurance_employer'] ?? 0)));
        $insLabel = 'Assurance santé'.(! empty($in['insurance_name']) ? ' ('.$in['insurance_name'].')' : '');
        if (! $declared) {
            $deductionTotal = array_sum(array_column($deductions, 'amount'));
            $reimbursementTotal = array_sum(array_column($reimbursements, 'amount'));
            $withholdings = $insEmp > 0 ? [['code' => 'assurance', 'label' => $insLabel, 'base' => null, 'rate' => null, 'amount' => $insEmp]] : [];
            $employer = $insCo > 0 ? [['code' => 'assurance', 'label' => $insLabel.' part patronale', 'base' => null, 'rate' => null, 'amount' => $insCo, 'group' => 'insurance']] : [];
            $net = $gross - $insEmp - $deductionTotal + $reimbursementTotal;
            $zero = array_fill_keys(['base_retraite', 'base_autres', 'cnps_salarie', 'cmu_salarie', 'cmu_persons', 'its_brut', 'ricf', 'its', 'retraite_employeur', 'pf', 'maternite', 'at', 'cmu_employeur', 'contribution', 'fdfp_ta', 'fdfp_fpc', 'employer_social', 'employer_tax'], 0);

            return [
                'days_worked' => $days, 'declared' => false, 'gains' => $lines, 'withholdings' => $withholdings, 'deductions' => $deductions, 'reimbursements' => $reimbursements, 'employer' => $employer,
                'totals' => $zero + [
                    'gross' => $gross, 'taxable' => 0, 'social_base' => 0, 'parts' => 0, 'withholdings' => $insEmp, 'deductions' => $deductionTotal, 'reimbursements' => $reimbursementTotal,
                    'net' => $net, 'insurance_employee' => $insEmp, 'insurance_employer' => $insCo, 'employer_total' => $insCo, 'cost' => $gross + $insCo,
                ],
            ];
        }

        // ---- Cotisations sociales ----
        $baseRetraite = min($social, (float) $s['plafond_retraite']);
        $baseAutres = min($social, (float) $s['plafond_autres']);
        $cnpsSal = $r($baseRetraite * $s['retraite_salarie'] / 100);
        $cnpsRetraiteEmp = $r($baseRetraite * $s['retraite_employeur'] / 100);
        $pf = $r($baseAutres * $s['prestations_familiales'] / 100);
        $mat = $r($baseAutres * $s['maternite'] / 100);
        $at = $r($baseAutres * $s['accident_travail'] / 100);

        $cmuPersons = ($in['cmu'] ?? true) ? 1 + (! empty($in['spouse_dependent']) ? 1 : 0) + min((int) ($in['children_count'] ?? 0), (int) $s['cmu_max_enfants']) : 0;
        $cmuSal = $r($cmuPersons * $s['cmu_salarie']);
        $cmuEmp = $r($cmuPersons * $s['cmu_employeur']);

        // ---- ITS ----
        $itsGross = $r($this->bareme($taxable));
        $parts = max(1, min((float) $s['parts_max'], (float) ($in['parts'] ?? 1)));
        $ricf = $r(($parts - 1) * 2 * (float) $s['ricf_par_demi_part']);
        $its = max(0.0, $itsGross - $ricf);

        // ---- Impôts patronaux ----
        $contribRate = ! empty($in['is_expatriate']) ? (float) $s['contribution_expatries'] : (float) $s['contribution_nationaux'];
        $contrib = $r($taxable * $contribRate / 100);
        $fdfpA = $r($taxable * $s['fdfp_apprentissage'] / 100);
        $fdfpF = $r($taxable * $s['fdfp_formation'] / 100);

        $withholdings = [
            ['code' => 'cnps', 'label' => 'CNPS retraite', 'base' => $baseRetraite, 'rate' => (float) $s['retraite_salarie'], 'amount' => $cnpsSal],
            ['code' => 'cmu', 'label' => "CMU ($cmuPersons pers.)", 'base' => null, 'rate' => null, 'amount' => $cmuSal],
            ['code' => 'its', 'label' => 'ITS', 'base' => $taxable, 'rate' => null, 'amount' => $its, 'detail' => ['brut' => $itsGross, 'ricf' => $ricf, 'parts' => $parts]],
        ];
        if ($cmuPersons === 0) {
            $withholdings = array_values(array_filter($withholdings, fn ($w) => $w['code'] !== 'cmu'));
        }
        if ($insEmp > 0) {
            $withholdings[] = ['code' => 'assurance', 'label' => $insLabel, 'base' => null, 'rate' => null, 'amount' => $insEmp];
        }
        $deductionTotal = array_sum(array_column($deductions, 'amount'));
        $withholdingTotal = $cnpsSal + $cmuSal + $its + $insEmp;
        $reimbursementTotal = array_sum(array_column($reimbursements, 'amount'));
        $net = $gross - $withholdingTotal - $deductionTotal + $reimbursementTotal;

        $employer = [
            ['code' => 'retraite', 'label' => 'CNPS retraite', 'base' => $baseRetraite, 'rate' => (float) $s['retraite_employeur'], 'amount' => $cnpsRetraiteEmp, 'group' => 'social'],
            ['code' => 'pf', 'label' => 'Prestations familiales', 'base' => $baseAutres, 'rate' => (float) $s['prestations_familiales'], 'amount' => $pf, 'group' => 'social'],
            ['code' => 'maternite', 'label' => 'Assurance maternité', 'base' => $baseAutres, 'rate' => (float) $s['maternite'], 'amount' => $mat, 'group' => 'social'],
            ['code' => 'at', 'label' => 'Accidents du travail', 'base' => $baseAutres, 'rate' => (float) $s['accident_travail'], 'amount' => $at, 'group' => 'social'],
            ['code' => 'cmu', 'label' => 'CMU part patronale', 'base' => null, 'rate' => null, 'amount' => $cmuEmp, 'group' => 'social'],
            ['code' => 'contribution', 'label' => 'Contribution employeur ('.(! empty($in['is_expatriate']) ? 'expatrié' : 'national').')', 'base' => $taxable, 'rate' => $contribRate, 'amount' => $contrib, 'group' => 'tax'],
            ['code' => 'fdfp_ta', 'label' => 'FDFP taxe d\'apprentissage', 'base' => $taxable, 'rate' => (float) $s['fdfp_apprentissage'], 'amount' => $fdfpA, 'group' => 'tax'],
            ['code' => 'fdfp_fpc', 'label' => 'FDFP formation continue', 'base' => $taxable, 'rate' => (float) $s['fdfp_formation'], 'amount' => $fdfpF, 'group' => 'tax'],
        ];
        if ($insCo > 0) {
            $employer[] = ['code' => 'assurance', 'label' => $insLabel.' part patronale', 'base' => null, 'rate' => null, 'amount' => $insCo, 'group' => 'insurance'];
        }
        $employerSocial = $cnpsRetraiteEmp + $pf + $mat + $at + $cmuEmp;
        $employerTax = $contrib + $fdfpA + $fdfpF;

        return [
            'days_worked' => $days,
            'declared' => true,
            'gains' => $lines,
            'withholdings' => $withholdings,
            'deductions' => $deductions,
            'reimbursements' => $reimbursements,
            'employer' => $employer,
            'totals' => [
                'gross' => $gross,
                'taxable' => $taxable,
                'social_base' => $social,
                'base_retraite' => $baseRetraite,
                'base_autres' => $baseAutres,
                'cnps_salarie' => $cnpsSal,
                'cmu_salarie' => $cmuSal,
                'cmu_persons' => $cmuPersons,
                'its_brut' => $itsGross,
                'ricf' => $ricf,
                'its' => $its,
                'parts' => $parts,
                'withholdings' => $withholdingTotal,
                'deductions' => $deductionTotal,
                'reimbursements' => $reimbursementTotal,
                'net' => $net,
                'retraite_employeur' => $cnpsRetraiteEmp,
                'pf' => $pf,
                'maternite' => $mat,
                'at' => $at,
                'cmu_employeur' => $cmuEmp,
                'contribution' => $contrib,
                'fdfp_ta' => $fdfpA,
                'fdfp_fpc' => $fdfpF,
                'employer_social' => $employerSocial,
                'employer_tax' => $employerTax,
                'insurance_employee' => $insEmp,
                'insurance_employer' => $insCo,
                'employer_total' => $employerSocial + $employerTax + $insCo,
                'cost' => $gross + $employerSocial + $employerTax + $insCo,
            ],
        ];
    }

    /** Barème progressif par tranches. */
    public function bareme(float $base): float
    {
        $tax = 0.0;
        $floor = 0.0;
        foreach ($this->s['its_bareme'] as $t) {
            $ceil = $t['up_to'] === null ? INF : (float) $t['up_to'];
            if ($base > $floor) {
                $tax += (min($base, $ceil) - $floor) * (float) $t['rate'] / 100;
            }
            $floor = $ceil;
            if ($base <= $ceil) {
                break;
            }
        }

        return $tax;
    }
}
