<?php

namespace App\Modules\Paie;

use App\Models\Company;
use App\Modules\Compta\Chart;
use App\Modules\Compta\Posting;
use App\Modules\Paie\Models\PayrollRun;
use App\Modules\Paie\Models\Payslip;
use App\Modules\Rh\Models\Employee;
use App\Modules\Rh\Models\Leave;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /** Clés de totaux additionnées au niveau du lot. */
    public const SUM_KEYS = [
        'gross', 'taxable', 'social_base', 'base_retraite', 'base_autres', 'cnps_salarie', 'cmu_salarie', 'its_brut', 'ricf', 'its',
        'withholdings', 'deductions', 'reimbursements', 'net', 'retraite_employeur', 'pf', 'maternite', 'at', 'cmu_employeur', 'contribution',
        'fdfp_ta', 'fdfp_fpc', 'employer_social', 'employer_tax', 'employer_total', 'cost', 'insurance_employee', 'insurance_employer',
    ];

    public static function periodLabel(string $period): string
    {
        [$y, $m] = array_map('intval', explode('-', $period));

        return 'Paie '.self::MONTHS[$m - 1].' '.$y;
    }

    /** Moteur de la société : ivoirien dédié (CI) ou générique piloté par le profil du pays. */
    public function calculator(Company $company): PayrollCalculator|CountryPayrollCalculator
    {
        $country = PayrollCountries::countryOf($company);
        if ($country === 'CI' && ! data_get($company->settings, 'paie.profiles.CI')) {
            return new PayrollCalculator(PaieSettings::for($company));
        }

        return new CountryPayrollCalculator(PayrollCountries::profile($company, $country));
    }

    /** Crée le lot du mois avec un bulletin par salarié présent sur la période. */
    public function createRun(Company $company, string $period): PayrollRun
    {
        return DB::transaction(function () use ($company, $period) {
            $run = PayrollRun::create(['period' => $period, 'label' => self::periodLabel($period), 'status' => 'draft']);
            $this->refresh($company, $run);

            return $run->fresh();
        });
    }

    /**
     * Recalcule un lot brouillon : ajoute les nouveaux salariés, retire ceux qui ne sont plus présents,
     * reprend les fiches à jour. Les éléments variables et jours saisis sont conservés.
     */
    public function refresh(Company $company, PayrollRun $run): void
    {
        [$start, $end] = $this->bounds($run->period);
        $employees = Employee::where(fn ($q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $end))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start))
            ->where(fn ($q) => $q->where('active', true)->orWhereNotNull('end_date'))
            ->get();

        $existing = $run->payslips()->get()->keyBy('employee_id');
        $keep = [];
        foreach ($employees as $e) {
            $slip = $existing[$e->id] ?? null;
            if (! $slip) {
                $slip = new Payslip(['run_id' => $run->id, 'employee_id' => $e->id]);
                $slip->items = array_values(array_map(fn ($i) => [
                    'label' => $i['label'] ?? 'Prime', 'amount' => (float) ($i['amount'] ?? 0), 'kind' => 'gain',
                    'taxable' => (bool) ($i['taxable'] ?? true), 'social' => (bool) ($i['social'] ?? true),
                ], (array) $e->fixed_items));
                $slip->days_worked = $this->defaultDays($company, $e, $start, $end);
            }
            $slip->number = 'BP-'.$run->period.'-'.$e->matricule;
            $slip->employee = $this->snapshot($e, $end);
            // Solde de tout compte validé pour ce mois de sortie
            $slip->items = array_merge(array_values(array_filter((array) $slip->items, fn ($i) => ($i['source'] ?? null) !== 'stc')), \App\Modules\Rh\SettlementService::payslipItems($e, $run->period));
            // Heures supplémentaires du pointage reprises automatiquement (remplacées à chaque recalcul)
            if ($company->hasModule('pointage') && data_get($company->settings, 'paie.auto_overtime', true) !== false && class_exists(\App\Modules\Pointage\PointageService::class)) {
                [$ot] = app(\App\Modules\Pointage\PointageService::class)->overtimeItems($company, (string) $e->id, $run->period, (float) $e->base_salary, (float) ($e->weekly_hours ?? 0) ?: null);
                $slip->items = array_merge(array_values(array_filter((array) $slip->items, fn ($i) => ($i['source'] ?? null) !== 'pointage')), $ot);
            }
            $this->computeSlip($company, $slip);
            $slip->save();
            $keep[] = $e->id;
        }
        $run->payslips()->whereNotIn('employee_id', $keep)->get()->each->delete();
        $this->updateTotals($run);
    }

    public function computeSlip(Company $company, Payslip $slip): void
    {
        $emp = $slip->employee;
        $result = $this->calculator($company)->compute([
            'base_salary' => $emp['base_salary'],
            'transport' => $emp['transport_allowance'],
            'days_worked' => $slip->days_worked,
            'seniority_years' => $emp['seniority_years'],
            'parts' => ($emp['parts_override'] ?? null) ?: $this->partsFor($company, $emp),
            'marital_status' => $emp['marital_status'] ?? null,
            'is_expatriate' => $emp['is_expatriate'],
            'spouse_dependent' => $emp['spouse_dependent'],
            'children_count' => $emp['children_count'],
            'declared' => $emp['cnps_declared'] ?? true,
            'cmu' => $emp['cmu_enrolled'] ?? true,
            'insurance_employee' => ! empty($emp['has_insurance']) ? (float) ($emp['insurance_employee'] ?? 0) : 0,
            'insurance_employer' => ! empty($emp['has_insurance']) ? (float) ($emp['insurance_employer'] ?? 0) : 0,
            'insurance_name' => $emp['insurance_name'] ?? null,
            'items' => $slip->items ?? [],
            'month' => (int) substr((string) ($slip->run?->period ?? PayrollRun::whereKey($slip->run_id)->value('period') ?? ''), 5, 2),
        ]);
        $slip->result = $result;
        $slip->gross = $result['totals']['gross'];
        $slip->net = $result['totals']['net'];
        $slip->cost = $result['totals']['cost'];
    }

    /** Parts fiscales : règles ivoiriennes pour le moteur CI, sinon calculées par le profil du pays. */
    protected function partsFor(Company $company, array $emp): ?float
    {
        return $this->calculator($company) instanceof PayrollCalculator ? (float) $emp['tax_parts'] : null;
    }

    public function updateTotals(PayrollRun $run): void
    {
        $totals = array_fill_keys(self::SUM_KEYS, 0.0);
        $count = 0;
        foreach ($run->payslips()->get() as $slip) {
            $count++;
            foreach (self::SUM_KEYS as $k) {
                $totals[$k] += (float) ($slip->result['totals'][$k] ?? 0);
            }
        }
        $run->update(['totals' => $totals + ['headcount' => $count]]);
    }

    // ---------- Validation / paiement ----------

    public function validate(Company $company, PayrollRun $run, int $userId): PayrollRun
    {
        return DB::transaction(function () use ($company, $run, $userId) {
            if (PayrollRun::whereKey($run->id)->lockForUpdate()->value('status') !== 'draft') {
                throw new \DomainException('Cette paie est déjà validée.');
            }
            $this->updateTotals($run);
            $run->update(['status' => 'validated', 'validated_by' => $userId, 'validated_at' => now()]);
            $this->postRun($company, $run->fresh());

            return $run->fresh();
        });
    }

    public function postRun(Company $company, PayrollRun $run): void
    {
        $t = $run->totals;
        $acc = PaieSettings::for($company)['comptes'];
        [, $end] = $this->bounds($run->period);
        $ci = $this->calculator($company) instanceof PayrollCalculator;

        app(Posting::class)->postExternal($company, 'SA', $end->toDateString(), $run->label, 'PAIE-'.$run->period, [
            [$acc['salaires'], $t['gross'], 'Salaires bruts'],
            [$acc['charges_sociales'], $t['employer_social'], $ci ? 'Charges sociales patronales (CNPS, CMU)' : 'Charges sociales patronales'],
            [$acc['impots_employeur'], $t['employer_tax'], $ci ? 'Contribution employeur et FDFP' : 'Taxes patronales sur salaires'],
            [$acc['cnps'], -($t['cnps_salarie'] + $t['cmu_salarie'] + $t['employer_social']), $ci ? 'CNPS et CMU à payer' : 'Organismes sociaux à payer'],
            [$acc['its'], -$t['its'], $ci ? 'ITS retenu à la source' : 'Impôts sur salaires retenus à la source'],
            [$acc['impots_dus'], -$t['employer_tax'], $ci ? 'Contribution employeur et FDFP à payer' : 'Taxes patronales à payer'],
            [$acc['avances'], -$t['deductions'], 'Retenues (avances, acomptes)'],
            [$acc['notes_frais'] ?? \App\Modules\Compta\Chart::code($company, '4286'), $t['reimbursements'] ?? 0, 'Notes de frais remboursées sur la paie'],
            [$acc['charges_sociales'], $t['insurance_employer'] ?? 0, 'Assurance santé part patronale'],
            [$acc['assurance'], -(($t['insurance_employee'] ?? 0) + ($t['insurance_employer'] ?? 0)), 'Assurance santé à payer'],
            [$acc['net_a_payer'], -$t['net'], 'Salaires nets à payer'],
        ], 'payroll_run', (string) $run->id, 'payroll');
    }

    public function reopen(Company $company, PayrollRun $run): PayrollRun
    {
        if ($run->status === 'paid') {
            throw new \DomainException('Une paie déjà payée ne peut plus être rouverte.');
        }
        DB::transaction(function () use ($company, $run) {
            app(Posting::class)->cancelExternal($company, 'payroll_run', (string) $run->id, 'payroll', 'Réouverture '.$run->label, false);
            $run->update(['status' => 'draft', 'validated_by' => null, 'validated_at' => null]);
        });

        return $run->fresh();
    }

    public function pay(Company $company, PayrollRun $run, string $method, string $date): PayrollRun
    {
        if ($run->status !== 'validated') {
            throw new \DomainException('Validez la paie avant de la payer.');
        }
        DB::transaction(function () use ($company, $run, $method, $date) {
            // Deux clics « Payer » simultanés : le second attend le premier puis voit la paie déjà payée (pas de double décaissement)
            if (PayrollRun::whereKey($run->id)->lockForUpdate()->value('status') !== 'validated') {
                throw new \DomainException('Validez la paie avant de la payer.');
            }
            $run->update(['status' => 'paid', 'payment_method' => $method, 'payment_date' => $date, 'paid_at' => now()]);
            $map = Chart::mapping($company);
            $treasury = ['bank_transfer' => $map['bank'], 'cheque' => $map['bank'], 'cash' => $map['cash'], 'mobile_money' => $map['mobile_money']][$method];
            $journal = Chart::PAYMENT_JOURNALS[$method] ?? 'BQ';
            if ($method === 'cheque') {
                $journal = 'BQ';
            }
            $acc = PaieSettings::for($company)['comptes'];
            // Avec la trésorerie : le versement des salaires sort du compte (caisse, banque, Mobile Money) du moyen de paiement
            if ($company->hasModule('tresorerie') && $acc['net_a_payer'] === \App\Modules\Compta\Chart::code($company, '422') && class_exists(\App\Modules\Tresorerie\Models\Operation::class)) {
                $account = app(\App\Modules\Tresorerie\TreasuryService::class)->defaults()[$method] ?? null;
                if ($account) {
                    $h = md5('payroll-run-'.$run->company_id.'-'.$run->id);
                    $id = substr($h, 0, 8).'-'.substr($h, 8, 4).'-4'.substr($h, 13, 3).'-a'.substr($h, 17, 3).'-'.substr($h, 20, 12);
                    $r = app(\App\Services\EntityWriter::class)->upsert(\App\Modules\Tresorerie\Models\Operation::class, $id, [
                        'account_id' => $account, 'date' => $date, 'amount' => -$run->totals['net'], 'label' => 'Salaires nets — '.mb_strtolower($run->label),
                        'category' => 'salaries', 'reference' => 'PAIE-'.$run->period,
                    ]);
                    if ($r['status'] === \App\Services\EntityWriter::APPLIED) {
                        return;
                    }
                }
            }
            app(Posting::class)->postExternal($company, $journal, $date, 'Règlement '.mb_strtolower($run->label), 'PAIE-'.$run->period, [
                [$acc['net_a_payer'], $run->totals['net'], 'Salaires nets payés'],
                [$treasury, -$run->totals['net'], 'Salaires nets payés'],
            ], 'payroll_run', (string) $run->id, 'payroll_payment');
        });

        return $run->fresh();
    }

    public function delete(Company $company, PayrollRun $run): void
    {
        if ($run->status !== 'draft') {
            throw new \DomainException('Seule une paie en brouillon peut être supprimée (rouvrez-la d\'abord).');
        }
        DB::transaction(function () use ($run) {
            $run->payslips()->get()->each->delete();
            $run->delete();
        });
    }

    // ---------- Déclarations ----------

    public function declaration(Company $company, PayrollRun $run): array
    {
        if ($this->calculator($company) instanceof CountryPayrollCalculator) {
            return $this->genericDeclaration($company, $run);
        }
        $d = $this->ciDeclaration($company, $run);
        $d['blocks'] = [
            ['key' => 'cnps', 'title' => 'CNPS — déclaration mensuelle des salaires et cotisations'] + $d['cnps'],
            ['key' => 'dgi', 'title' => 'Impôts (DGI) — ITS et contribution employeur'] + $d['dgi'],
            ['key' => 'fdfp', 'title' => 'FDFP — taxes de formation'] + $d['fdfp'],
        ];
        $d['employees_title'] = 'Détail CNPS par salarié';
        $d['employees'] = collect($d['cnps']['employees'])->map(fn ($e) => ['matricule' => $e['matricule'], 'name' => $e['name'], 'number' => $e['cnps_number'], 'days' => $e['days'], 'gross' => $e['gross'], 'amount' => $e['cnps']])->all();
        $d['footnote'] = 'À vérifier avant dépôt sur les portails CNPS (e-CNPS) et DGI (e-impots).';

        return $d;
    }

    /**
     * Déclaration pour les pays du moteur générique : cotisations sociales par rubrique (parts salariale et patronale),
     * impôts retenus à la source et taxes patronales, détail par salarié.
     */
    public function genericDeclaration(Company $company, PayrollRun $run): array
    {
        $profile = PayrollCountries::profile($company);
        $slips = $run->payslips()->orderBy('id')->get()->filter(fn ($p) => ($p->result['declared'] ?? true) !== false)->values();
        $social = $tax = $employerTax = [];
        $acc = function (array &$bucket, string $key, string $label, array $l) {
            $bucket[$key] ??= ['label' => $label, 'base' => 0.0, 'rate' => $l['rate'] ?? null, 'amount' => 0.0];
            $bucket[$key]['base'] += (float) ($l['base'] ?? 0);
            $bucket[$key]['amount'] += (float) $l['amount'];
        };
        foreach ($slips as $p) {
            foreach ((array) ($p->result['withholdings'] ?? []) as $l) {
                if (($l['group'] ?? '') === 'social') {
                    $acc($social, $l['code'].'_s', preg_replace('/ \(\d+ pers\.\)$/', '', $l['label']).' — part salariale', $l);
                } elseif (($l['group'] ?? '') === 'tax') {
                    $acc($tax, $l['code'], $l['label'], $l);
                }
            }
            foreach ((array) ($p->result['employer'] ?? []) as $l) {
                if (($l['group'] ?? '') === 'social') {
                    $acc($social, $l['code'].'_e', preg_replace('/ \(\d+ pers\.\)$/', '', $l['label']).' — part patronale', $l);
                } elseif (($l['group'] ?? '') === 'tax') {
                    $acc($employerTax, $l['code'], $l['label'], $l);
                }
            }
        }
        ksort($social);
        $block = fn (string $key, string $title, array $rows) => ['key' => $key, 'title' => $title, 'rows' => array_values(array_map(fn ($r) => ['base' => $r['base'] ?: null] + $r, $rows)), 'total' => array_sum(array_column($rows, 'amount'))];
        $itLabel = $profile['income_tax']['label'] ?? 'Impôt sur salaire';

        return [
            'period' => $run->period,
            'label' => $run->label,
            'country' => $profile['code'] ?? null,
            'headcount' => $slips->count(),
            'blocks' => [
                $block('social', ($profile['social_body'] ?? 'Sécurité sociale').' — cotisations du mois', $social),
                $block('tax', 'Impôts — '.$itLabel.' et prélèvements retenus à la source', $tax),
                $block('employer_tax', 'Taxes patronales sur salaires', $employerTax),
            ],
            'employees_title' => 'Détail par salarié',
            'employees' => $slips->map(fn ($p) => [
                'matricule' => $p->employee['matricule'], 'name' => $p->employee['full_name'], 'number' => $p->employee['cnps_number'] ?? null,
                'days' => $p->days_worked, 'gross' => $p->result['totals']['social_base'] ?? 0,
                'amount' => (float) collect($p->result['withholdings'])->where('group', 'social')->sum('amount') + (float) collect($p->result['employer'])->where('group', 'social')->sum('amount'),
            ])->values()->all(),
            'footnote' => 'Montants calculés selon le profil de paie '.($profile['name'] ?? '').' de la société. À vérifier avant dépôt auprès des organismes.',
        ];
    }

    protected function ciDeclaration(Company $company, PayrollRun $run): array
    {
        $t = $run->totals;
        $s = PaieSettings::for($company);
        // Déclaration CNPS / ITS : seuls les salariés déclarés (stagiaires et prestataires non déclarés exclus)
        $slips = $run->payslips()->orderBy('id')->get()->filter(fn ($p) => ($p->result['declared'] ?? true) !== false)->values();
        $expat = $slips->filter(fn ($p) => ! empty($p->employee['is_expatriate']));
        $nat = $slips->reject(fn ($p) => ! empty($p->employee['is_expatriate']));
        $sum = fn ($col, $k) => (float) $col->sum(fn ($p) => $p->result['totals'][$k] ?? 0);

        return [
            'period' => $run->period,
            'label' => $run->label,
            'headcount' => $slips->count(),
            'cnps' => [
                'rows' => [
                    ['label' => 'Retraite — part salariale', 'base' => $t['base_retraite'], 'rate' => $s['retraite_salarie'], 'amount' => $t['cnps_salarie']],
                    ['label' => 'Retraite — part patronale', 'base' => $t['base_retraite'], 'rate' => $s['retraite_employeur'], 'amount' => $t['retraite_employeur']],
                    ['label' => 'Prestations familiales', 'base' => $t['base_autres'], 'rate' => $s['prestations_familiales'], 'amount' => $t['pf']],
                    ['label' => 'Assurance maternité', 'base' => $t['base_autres'], 'rate' => $s['maternite'], 'amount' => $t['maternite']],
                    ['label' => 'Accidents du travail', 'base' => $t['base_autres'], 'rate' => $s['accident_travail'], 'amount' => $t['at']],
                    ['label' => 'CMU — part salariale', 'base' => null, 'rate' => null, 'amount' => $t['cmu_salarie']],
                    ['label' => 'CMU — part patronale', 'base' => null, 'rate' => null, 'amount' => $t['cmu_employeur']],
                ],
                'total' => $t['cnps_salarie'] + $t['retraite_employeur'] + $t['pf'] + $t['maternite'] + $t['at'] + $t['cmu_salarie'] + $t['cmu_employeur'],
                'employees' => $slips->map(fn ($p) => [
                    'matricule' => $p->employee['matricule'], 'name' => $p->employee['full_name'], 'cnps_number' => $p->employee['cnps_number'],
                    'days' => $p->days_worked, 'gross' => $p->result['totals']['social_base'],
                    'base_retraite' => $p->result['totals']['base_retraite'], 'cnps' => $p->result['totals']['cnps_salarie'] + $p->result['totals']['retraite_employeur'],
                ])->values(),
            ],
            'dgi' => [
                'rows' => [
                    ['label' => 'Impôt sur les traitements et salaires (ITS)', 'base' => $t['taxable'], 'rate' => null, 'amount' => $t['its']],
                    ['label' => 'Contribution employeur — personnel national', 'base' => $sum($nat, 'taxable'), 'rate' => $s['contribution_nationaux'], 'amount' => $sum($nat, 'contribution')],
                    ['label' => 'Contribution employeur — personnel expatrié', 'base' => $sum($expat, 'taxable'), 'rate' => $s['contribution_expatries'], 'amount' => $sum($expat, 'contribution')],
                ],
                'total' => $t['its'] + $t['contribution'],
            ],
            'fdfp' => [
                'rows' => [
                    ['label' => 'Taxe d\'apprentissage', 'base' => $t['taxable'], 'rate' => $s['fdfp_apprentissage'], 'amount' => $t['fdfp_ta']],
                    ['label' => 'Taxe additionnelle à la formation continue', 'base' => $t['taxable'], 'rate' => $s['fdfp_formation'], 'amount' => $t['fdfp_fpc']],
                ],
                'total' => $t['fdfp_ta'] + $t['fdfp_fpc'],
            ],
        ];
    }

    /** Recherche du salaire de base donnant un net cible (« brut à partir du net »). */
    public function grossFromNet(Company $company, array $input, float $targetNet): array
    {
        $calc = $this->calculator($company);
        $lo = 0.0;
        $hi = max(100000.0, $targetNet * 3);
        while ($calc->compute(['base_salary' => $hi] + $input)['totals']['net'] < $targetNet && $hi < 1e10) {
            $hi *= 2;
        }
        for ($i = 0; $i < 60 && $hi - $lo > 0.5; $i++) {
            $mid = ($lo + $hi) / 2;
            $calc->compute(['base_salary' => $mid] + $input)['totals']['net'] < $targetNet ? $lo = $mid : $hi = $mid;
        }
        $base = ceil($hi);

        return ['base_salary' => $base] + $calc->compute(['base_salary' => $base] + $input);
    }

    // ---------- Utilitaires ----------

    /** @return array{0: Carbon, 1: Carbon} */
    public function bounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    /** Jours payés par défaut : prorata d'entrée/sortie dans le mois, moins les absences non payées approuvées. */
    public function defaultDays(Company $company, Employee $e, Carbon $start, Carbon $end): float
    {
        $basis = (int) PaieSettings::for($company)['days_basis'];
        $from = $e->hire_date && $e->hire_date->gt($start) ? $e->hire_date->copy() : $start->copy();
        $to = $e->end_date && $e->end_date->lt($end) ? $e->end_date->copy() : $end->copy();
        $present = max(0, $from->diffInDays($to) + 1);
        $days = $present >= $start->daysInMonth ? $basis : min($basis, $present);

        $unpaid = Leave::where('employee_id', $e->id)->where('status', 'approved')->where('type', 'unpaid')
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->get();
        foreach ($unpaid as $l) {
            $a = $l->start_date->gt($from) ? $l->start_date : $from;
            $b = $l->end_date->lt($to) ? $l->end_date : $to;
            if ($b->gte($a)) {
                $days -= $a->diffInDays($b) + 1;
            }
        }

        return (float) max(0, $days);
    }

    protected function snapshot(Employee $e, Carbon $end): array
    {
        return [
            'id' => $e->id, 'matricule' => $e->matricule, 'full_name' => $e->full_name, 'first_name' => $e->first_name, 'last_name' => $e->last_name,
            'job_title' => $e->job_title, 'department' => $e->department, 'category' => $e->category, 'contract_type' => $e->contract_type,
            'hire_date' => $e->hire_date?->toDateString(), 'cnps_number' => $e->cnps_number,
            'base_salary' => (float) $e->base_salary, 'transport_allowance' => (float) $e->transport_allowance,
            'marital_status' => $e->marital_status, 'children_count' => (int) $e->children_count, 'spouse_dependent' => (bool) $e->spouse_dependent,
            'tax_parts' => $e->tax_parts, 'parts_override' => $e->parts_override ? (float) $e->parts_override : null, 'is_expatriate' => (bool) $e->is_expatriate,
            'seniority_years' => $e->hire_date ? (int) floor($e->hire_date->diffInYears($end)) : 0,
            'payment_method' => $e->payment_method, 'payment_account' => $e->payment_account,
            'weekly_hours' => (float) ($e->weekly_hours ?? 0) ?: null,
            'staff_type' => $e->staff_type ?? 'employee', 'cnps_declared' => (bool) ($e->cnps_declared ?? true), 'cmu_enrolled' => (bool) ($e->cmu_enrolled ?? true),
            'has_insurance' => (bool) ($e->has_insurance ?? false), 'insurance_name' => $e->insurance_name,
            'insurance_employee' => (float) ($e->insurance_employee ?? 0), 'insurance_employer' => (float) ($e->insurance_employer ?? 0),
        ];
    }
}
