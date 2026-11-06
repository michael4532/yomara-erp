<?php

namespace App\Modules\Paie;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Modules\Paie\Models\PayrollRun;
use App\Modules\Paie\Models\Payslip;
use Illuminate\Http\Request;

class PaieController extends Controller
{
    public function __construct(protected PayrollService $service) {}

    protected function company()
    {
        return app('tenant');
    }

    protected function fail(\DomainException $e)
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    // ---------- Paramètres ----------

    public function settings()
    {
        $c = $this->company();
        $country = PayrollCountries::countryOf($c);

        return [
            'settings' => PaieSettings::for($c), 'defaults' => PaieSettings::DEFAULTS,
            'country' => $country, 'engine' => $this->service->calculator($c) instanceof PayrollCalculator ? 'ci' : 'profile',
            'countries' => PayrollCountries::catalog(),
            'profile' => PayrollCountries::profile($c, $country),
        ];
    }

    /** Profil d'un pays (pour l'aperçu avant de le choisir). */
    public function profile(Request $request)
    {
        $code = strtoupper((string) $request->query('code', PayrollCountries::countryOf($this->company())));
        if ($code !== PayrollCountries::CUSTOM && ! isset(PayrollCountries::builtIn()[$code])) {
            return response()->json(['message' => 'Pays non pris en charge.'], 404);
        }

        return ['profile' => PayrollCountries::profile($this->company(), $code), 'reference' => PayrollCountries::builtIn()[$code] ?? null];
    }

    /** Choix du pays de paie et, si fourni, enregistrement du profil modifié. */
    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'country' => 'required|string|size:2',
            'profile' => 'nullable|array',
        ]);
        $code = strtoupper($data['country']);
        if ($code !== PayrollCountries::CUSTOM && ! isset(PayrollCountries::builtIn()[$code])) {
            return response()->json(['message' => 'Pays non pris en charge.'], 422);
        }
        $company = $this->company();
        $settings = $company->settings ?? [];
        $settings['paie']['country'] = $code;
        if (! empty($data['profile'])) {
            $profile = $data['profile'];
            unset($profile['customized'], $profile['code']);
            if ($err = PayrollCountries::validate($profile)) {
                return response()->json(['message' => $err], 422);
            }
            $settings['paie']['profiles'][$code] = $profile;
        }
        $company->update(['settings' => $settings]);
        ActivityLog::record('paie.settings.updated', $company);

        return $this->settings();
    }

    /** Revenir au profil de référence du pays (abandon des modifications de la société). */
    public function resetProfile($code)
    {
        $company = $this->company();
        $settings = $company->settings ?? [];
        unset($settings['paie']['profiles'][strtoupper($code)]);
        $company->update(['settings' => $settings]);

        return $this->settings();
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'smig' => 'sometimes|numeric|min:0',
            'days_basis' => 'sometimes|integer|min:20|max:31',
            'retraite_salarie' => 'sometimes|numeric|min:0|max:100', 'retraite_employeur' => 'sometimes|numeric|min:0|max:100',
            'plafond_retraite' => 'sometimes|numeric|min:0',
            'prestations_familiales' => 'sometimes|numeric|min:0|max:100', 'maternite' => 'sometimes|numeric|min:0|max:100',
            'accident_travail' => 'sometimes|numeric|min:0|max:100', 'plafond_autres' => 'sometimes|numeric|min:0',
            'cmu_salarie' => 'sometimes|numeric|min:0', 'cmu_employeur' => 'sometimes|numeric|min:0', 'cmu_max_enfants' => 'sometimes|integer|min:0|max:20',
            'its_bareme' => 'sometimes|array|min:1',
            'its_bareme.*.up_to' => 'nullable|numeric|min:0',
            'its_bareme.*.rate' => 'required|numeric|min:0|max:100',
            'ricf_par_demi_part' => 'sometimes|numeric|min:0', 'parts_max' => 'sometimes|numeric|min:1|max:10',
            'contribution_nationaux' => 'sometimes|numeric|min:0|max:100', 'contribution_expatries' => 'sometimes|numeric|min:0|max:100',
            'fdfp_apprentissage' => 'sometimes|numeric|min:0|max:100', 'fdfp_formation' => 'sometimes|numeric|min:0|max:100',
            'transport_exonere' => 'sometimes|numeric|min:0',
            'anciennete_auto' => 'sometimes|boolean', 'anciennete_max' => 'sometimes|numeric|min:0|max:100',
            'comptes' => 'sometimes|array',
            'comptes.*' => 'regex:/^[1-9][0-9]{1,9}$/',
        ]);
        if (isset($data['its_bareme'])) {
            $prev = -1;
            foreach ($data['its_bareme'] as $i => $t) {
                $last = $i === count($data['its_bareme']) - 1;
                if (($t['up_to'] ?? null) === null && ! $last) {
                    return response()->json(['message' => 'Seule la dernière tranche du barème peut être sans plafond.'], 422);
                }
                if (($t['up_to'] ?? null) !== null && $t['up_to'] <= $prev) {
                    return response()->json(['message' => 'Les tranches du barème doivent être croissantes.'], 422);
                }
                $prev = $t['up_to'] ?? INF;
            }
        }
        $company = $this->company();
        $settings = $company->settings ?? [];
        $settings['paie'] = array_replace((array) ($settings['paie'] ?? []), $data);
        $company->update(['settings' => $settings]);
        ActivityLog::record('paie.settings.updated', $company);

        return $this->settings();
    }

    // ---------- Lots de paie ----------

    public function runs()
    {
        return ['runs' => PayrollRun::orderByDesc('period')->get()];
    }

    public function run($id)
    {
        $run = PayrollRun::findOrFail($id);

        return ['run' => $run, 'payslips' => $run->payslips()->orderBy('number')->get()];
    }

    public function storeRun(Request $request)
    {
        $data = $request->validate(['period' => 'required|date_format:Y-m']);
        if (PayrollRun::where('period', $data['period'])->exists()) {
            return response()->json(['message' => 'La paie de ce mois existe déjà.'], 422);
        }
        $run = $this->service->createRun($this->company(), $data['period']);
        ActivityLog::record('paie.run.created', $run, ['period' => $run->period]);

        return response()->json($this->run($run->id), 201);
    }

    public function refreshRun($id)
    {
        $run = PayrollRun::findOrFail($id);
        if ($run->status !== 'draft') {
            return response()->json(['message' => 'Rouvrez la paie pour la recalculer.'], 422);
        }
        $this->service->refresh($this->company(), $run);

        return $this->run($id);
    }

    public function validateRun(Request $request, $id)
    {
        $run = PayrollRun::findOrFail($id);
        if ($run->status !== 'draft') {
            return response()->json(['message' => 'Cette paie est déjà validée.'], 422);
        }
        if (! $run->payslips()->exists()) {
            return response()->json(['message' => 'Aucun bulletin dans cette paie.'], 422);
        }
        try {
            $this->service->validate($this->company(), $run, $request->user()->id);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
        ActivityLog::record('paie.run.validated', $run, ['period' => $run->period]);

        return $this->run($id);
    }

    public function reopenRun($id)
    {
        try {
            $run = $this->service->reopen($this->company(), PayrollRun::findOrFail($id));
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
        ActivityLog::record('paie.run.reopened', $run, ['period' => $run->period]);

        return $this->run($id);
    }

    public function payRun(Request $request, $id)
    {
        $data = $request->validate([
            'method' => 'required|in:bank_transfer,cash,mobile_money,cheque',
            'date' => 'nullable|date',
        ]);
        try {
            $run = $this->service->pay($this->company(), PayrollRun::findOrFail($id), $data['method'], $data['date'] ?? now()->toDateString());
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
        ActivityLog::record('paie.run.paid', $run, ['period' => $run->period]);

        return $this->run($id);
    }

    /**
     * « Payer les salaires du mois » : aperçu (le lot du mois est préparé avec le salaire de chacun),
     * puis validation et paiement en une fois.
     */
    public function quickPreview(Request $request)
    {
        $data = $request->validate(['period' => 'required|date_format:Y-m']);
        $c = $this->company();
        $run = PayrollRun::where('period', $data['period'])->first();
        if (! $run) {
            $run = $this->service->createRun($c, $data['period']);
            ActivityLog::record('paie.run.created', $run, ['period' => $run->period]);
        } elseif ($run->status === 'draft') {
            $this->service->refresh($c, $run);
        }
        $run = $run->fresh();
        $slips = $run->payslips()->get()->sortBy(fn ($p) => $p->employee['full_name'] ?? '')->values()->map(fn ($p) => [
            'id' => $p->id, 'employee_id' => $p->employee_id, 'name' => $p->employee['full_name'] ?? '', 'job_title' => $p->employee['job_title'] ?? null,
            'department' => $p->employee['department'] ?? null, 'staff_type' => $p->employee['staff_type'] ?? 'employee',
            'declared' => ($p->result['declared'] ?? true) !== false, 'days' => (float) $p->days_worked,
            'gross' => (float) $p->gross, 'net' => (float) $p->net, 'cost' => (float) $p->cost,
            'payment_method' => $p->employee['payment_method'] ?? null,
        ]);

        return ['run' => ['id' => $run->id, 'period' => $run->period, 'label' => $run->label, 'status' => $run->status, 'payment_date' => $run->payment_date, 'totals' => $run->totals], 'payslips' => $slips];
    }

    public function quickPay(Request $request)
    {
        $data = $request->validate([
            'period' => 'required|date_format:Y-m',
            'method' => 'required|in:bank_transfer,cash,mobile_money,cheque',
            'date' => 'nullable|date',
        ]);
        $c = $this->company();
        $run = PayrollRun::where('period', $data['period'])->first();
        if (! $run) {
            return response()->json(['message' => 'Préparez d\'abord la paie du mois.'], 422);
        }
        if ($run->status === 'paid') {
            return response()->json(['message' => 'Les salaires de ce mois sont déjà payés.'], 422);
        }
        try {
            if ($run->status === 'draft') {
                if (! $run->payslips()->exists()) {
                    return response()->json(['message' => 'Aucun salarié à payer ce mois-ci.'], 422);
                }
                $this->service->validate($c, $run, $request->user()->id);
                ActivityLog::record('paie.run.validated', $run, ['period' => $run->period]);
            }
            $run = $this->service->pay($c, $run->fresh(), $data['method'], $data['date'] ?? now()->toDateString());
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
        ActivityLog::record('paie.run.paid', $run, ['period' => $run->period]);

        return $this->run($run->id);
    }

    public function deleteRun($id)
    {
        try {
            $this->service->delete($this->company(), PayrollRun::findOrFail($id));
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return ['ok' => true];
    }

    // ---------- Bulletins ----------

    public function payslip($id)
    {
        $slip = Payslip::with('run')->findOrFail($id);

        return $slip;
    }

    public function updatePayslip(Request $request, $id)
    {
        $slip = Payslip::with('run')->findOrFail($id);
        if ($slip->run->status !== 'draft') {
            return response()->json(['message' => 'Rouvrez la paie pour modifier ce bulletin.'], 422);
        }
        $data = $request->validate([
            'days_worked' => 'sometimes|numeric|min:0|max:31',
            'items' => 'sometimes|array',
            'items.*.label' => 'required|string|max:120',
            'items.*.amount' => 'required|numeric|min:0',
            'items.*.kind' => 'required|in:gain,deduction,reimbursement',
            'items.*.taxable' => 'nullable|boolean',
            'items.*.social' => 'nullable|boolean',
            'items.*.source' => 'nullable|string|max:30',
        ]);
        if (array_key_exists('items', $data)) {
            // Lignes générées (heures sup. du pointage, solde de tout compte, notes de frais) : la version serveur est conservée telle quelle.
            // Sans cela, la ligne renvoyée par l'application perdait son origine et était ajoutée une seconde fois au recalcul (double paiement).
            $system = array_values(array_filter((array) $slip->items, fn ($i) => ! empty($i['source'])));
            $sig = fn ($i) => mb_strtolower(trim((string) $i['label'])).'|'.round((float) $i['amount'], 2).'|'.$i['kind'];
            $systemSigs = array_map($sig, $system);
            $manual = array_values(array_filter($data['items'], fn ($i) => empty($i['source']) && ! in_array($sig($i), $systemSigs, true)));
            $data['items'] = array_merge(array_map(fn ($i) => array_diff_key($i, ['source' => 1]), $manual), $system);
        }
        $slip->fill($data);
        $this->service->computeSlip($this->company(), $slip);
        $slip->save();
        $this->service->updateTotals($slip->run);

        return $slip->fresh()->load('run');
    }

    // ---------- Déclarations, export, simulateur ----------

    public function declaration($id)
    {
        return $this->service->declaration($this->company(), PayrollRun::findOrFail($id));
    }

    public function export($id)
    {
        $run = PayrollRun::findOrFail($id);
        $cols = [
            'Matricule' => fn ($p) => $p->employee['matricule'], 'Nom' => fn ($p) => $p->employee['full_name'],
            'N° CNPS' => fn ($p) => $p->employee['cnps_number'], 'Fonction' => fn ($p) => $p->employee['job_title'],
            'Jours' => fn ($p) => $p->days_worked, 'Parts' => fn ($p) => $p->result['totals']['parts'],
        ];
        foreach (['gross' => 'Brut', 'taxable' => 'Brut imposable', 'cnps_salarie' => 'CNPS salarié', 'cmu_salarie' => 'CMU salarié',
            'its_brut' => 'ITS brut', 'ricf' => 'RICF', 'its' => 'ITS', 'deductions' => 'Retenues', 'net' => 'Net à payer',
            'retraite_employeur' => 'CNPS retraite employeur', 'pf' => 'Prestations familiales', 'maternite' => 'Maternité', 'at' => 'Accidents du travail',
            'cmu_employeur' => 'CMU employeur', 'contribution' => 'Contribution employeur', 'fdfp_ta' => 'FDFP apprentissage', 'fdfp_fpc' => 'FDFP formation',
            'cost' => 'Coût employeur'] as $k => $label) {
            $cols[$label] = fn ($p) => $p->result['totals'][$k] ?? 0;
        }
        $out = "\xEF\xBB\xBF".implode(';', array_keys($cols))."\r\n";
        foreach ($run->payslips()->orderBy('number')->get() as $p) {
            $out .= implode(';', array_map(function ($f) use ($p) {
                $v = $f($p);

                return is_numeric($v) ? str_replace('.', ',', (string) $v) : '"'.str_replace('"', '""', (string) $v).'"';
            }, $cols))."\r\n";
        }

        return response($out, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="livre-de-paie-'.$run->period.'.csv"',
        ]);
    }

    public function simulate(Request $request)
    {
        $in = $request->validate([
            'base_salary' => 'nullable|numeric|min:0',
            'target_net' => 'nullable|numeric|min:1',
            'transport' => 'nullable|numeric|min:0',
            'days_worked' => 'nullable|numeric|min:0|max:31',
            'seniority_years' => 'nullable|integer|min:0|max:60',
            'parts' => 'nullable|numeric|min:1|max:5',
            'marital_status' => 'nullable|in:single,married,divorced,widowed',
            'children_count' => 'nullable|integer|min:0|max:30',
            'spouse_dependent' => 'nullable|boolean',
            'is_expatriate' => 'nullable|boolean',
            'items' => 'nullable|array',
        ]);
        if (empty($in['parts']) && $this->service->calculator($this->company()) instanceof PayrollCalculator) {
            $ms = $in['marital_status'] ?? 'single';
            $n = (int) ($in['children_count'] ?? 0);
            $in['parts'] = min(5, ($ms === 'married' || ($ms === 'widowed' && $n > 0) ? 2 : ($n > 0 ? 1.5 : 1)) + 0.5 * $n);
        }
        if (! empty($in['target_net'])) {
            $target = (float) $in['target_net'];
            unset($in['target_net'], $in['base_salary']);

            return $this->service->grossFromNet($this->company(), $in, $target);
        }

        return ['base_salary' => (float) ($in['base_salary'] ?? 0)] + $this->service->calculator($this->company())->compute($in);
    }
}
