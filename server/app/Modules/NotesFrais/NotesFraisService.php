<?php

namespace App\Modules\NotesFrais;

use App\Models\Company;
use App\Modules\Compta\Chart;
use App\Modules\Compta\Posting;
use App\Modules\NotesFrais\Models\Expense;
use App\Modules\NotesFrais\Models\ExpenseCategory;
use App\Modules\Paie\Models\PayrollRun;
use App\Modules\Paie\PayrollService;
use App\Modules\Rh\Models\Employee;
use App\Modules\Tresorerie\Models\Operation;
use App\Modules\Tresorerie\Models\TreasuryAccount;
use App\Services\EntityWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotesFraisService
{
    public const DEFAULTS = ['receipt_from' => 0, 'payable_account' => '4286'];

    public function settings(Company $company): array
    {
        return array_merge(['payable_account' => Chart::code($company, '4286')] + self::DEFAULTS, (array) data_get($company->settings, 'notes_frais', []));
    }

    public function ensureDefaults(): void
    {
        if (ExpenseCategory::withTrashed()->exists()) {
            return;
        }
        foreach (ExpenseCategory::DEFAULTS as $i => [$name, $gl, $max, $receipt]) {
            $c = new ExpenseCategory(['name' => $name, 'gl_code' => Chart::local($gl), 'max_amount' => $max, 'receipt_required' => $receipt, 'sort' => $i]);
            $c->id = (string) Str::uuid();
            $c->save();
        }
    }

    /** Dépense approuvée : charge (6x) au débit, dette envers le salarié (4286) au crédit. */
    public function postApproval(Expense $e): void
    {
        $company = Company::find($e->company_id);
        $cat = ExpenseCategory::withTrashed()->find($e->category_id);
        $emp = Employee::withTrashed()->find($e->employee_id);
        $label = 'Note de frais '.($emp?->full_name ?? '').' — '.$e->description;
        app(Posting::class)->postExternal($company, 'OD', $e->date->toDateString(), $label, 'NF-'.substr($e->id, 0, 8), [
            [$cat?->gl_code ?? Chart::code($company, '638'), $e->amount, $label],
            [$this->settings($company)['payable_account'], -$e->amount, $label],
        ], 'nf_expense', $e->id, 'approval');
    }

    public function unpostApproval(Expense $e): void
    {
        app(Posting::class)->cancelExternal(Company::find($e->company_id), 'nf_expense', $e->id, 'approval', 'Annulation note de frais : '.$e->description, false);
    }

    /**
     * Remboursement d'un salarié : par la trésorerie (opération sur le compte choisi), en espèces / virement sans module
     * trésorerie (écriture directe), ou sur sa paie du mois (ligne hors salaire ajoutée au bulletin en brouillon).
     *
     * @param  array{via:string, account_id?:string, method?:string, date?:string, run_id?:int}  $o
     */
    public function pay(Company $company, string $employeeId, array $ids, array $o): array
    {
        $emp = Employee::findOrFail($employeeId);
        $expenses = Expense::where('employee_id', $employeeId)->whereIn('id', $ids)->get();
        if ($expenses->isEmpty() || $expenses->count() !== count(array_unique($ids))) {
            throw new \DomainException('Dépenses introuvables pour ce salarié.');
        }
        if ($expenses->contains(fn ($e) => $e->status !== 'approved')) {
            throw new \DomainException('Seules les dépenses approuvées se remboursent.');
        }
        $total = round($expenses->sum('amount'), 2);
        $date = $o['date'] ?? now()->toDateString();
        $label = 'Remboursement notes de frais — '.$emp->full_name;
        $payable = $this->settings($company)['payable_account'];

        return DB::transaction(function () use ($company, $emp, $expenses, $total, $date, $label, $payable, $o) {
            // Remboursement lancé deux fois en même temps : le second attend le premier et trouve les dépenses déjà remboursées
            if (Expense::whereIn('id', $expenses->pluck('id'))->where('status', 'approved')->lockForUpdate()->count() !== $expenses->count()) {
                throw new \DomainException('Seules les dépenses approuvées se remboursent.');
            }
            $ref = null;
            switch ($o['via']) {
                case 'treasury':
                    if (! $company->hasModule('tresorerie')) {
                        throw new \DomainException('Module Banque & trésorerie non activé.');
                    }
                    $acc = TreasuryAccount::findOrFail($o['account_id'] ?? null);
                    $ref = (string) Str::uuid();
                    $r = app(EntityWriter::class)->upsert(Operation::class, $ref, [
                        'account_id' => $acc->id, 'date' => $date, 'amount' => -$total, 'label' => $label, 'category' => 'expense_report',
                        'reference' => 'NF '.$expenses->count().' dépense(s)',
                    ]);
                    if ($r['status'] !== EntityWriter::APPLIED) {
                        throw new \DomainException($r['message'] ?? 'Opération de trésorerie refusée.');
                    }
                    break;
                case 'cash':
                    $map = Chart::mapping($company);
                    $method = $o['method'] ?? 'cash';
                    $gl = $map[$method === 'bank_transfer' ? 'bank' : ($method === 'mobile_money' ? 'mobile_money' : 'cash')] ?? '571';
                    $journal = ['cash' => 'CA', 'bank_transfer' => 'BQ', 'mobile_money' => 'MM'][$method] ?? 'CA';
                    $ref = (string) Str::uuid();
                    app(Posting::class)->postExternal($company, $journal, $date, $label, 'NF', [[$payable, $total, $label], [$gl, -$total, $label]], 'nf_payment', $ref, 'payment');
                    break;
                case 'payroll':
                    if (! $company->hasModule('paie')) {
                        throw new \DomainException('Module Paie non activé.');
                    }
                    $run = PayrollRun::findOrFail($o['run_id'] ?? null);
                    if ($run->status !== 'draft') {
                        throw new \DomainException('Choisissez une paie en brouillon.');
                    }
                    $slip = $run->payslips()->where('employee_id', $emp->id)->first();
                    if (! $slip) {
                        throw new \DomainException($emp->full_name.' n\'a pas de bulletin dans cette paie.');
                    }
                    $items = (array) $slip->items;
                    $items[] = ['label' => 'Remboursement notes de frais ('.$expenses->count().')', 'amount' => $total, 'kind' => 'reimbursement', 'taxable' => false, 'social' => false, 'source' => 'notes_frais', 'expense_ids' => $expenses->pluck('id')->all()];
                    $slip->items = $items;
                    app(PayrollService::class)->computeSlip($company, $slip);
                    $slip->save();
                    app(PayrollService::class)->updateTotals($run);
                    $ref = 'payslip:'.$slip->id;
                    $date = null;
                    break;
                default:
                    throw new \DomainException('Mode de remboursement inconnu.');
            }
            foreach ($expenses as $e) {
                $e->forceFill(['status' => 'paid', 'paid_via' => $o['via'], 'payment_ref' => $ref, 'paid_at' => $date ?? now()->toDateString()])->save();
            }
            if ($emp->user_id) {
                \App\Models\AppNotification::send($company->id, [$emp->user_id], 'notes_frais', 'Notes de frais remboursées',
                    number_format($total, 0, ',', ' ').' — '.($o['via'] === 'payroll' ? 'ajouté à votre prochain bulletin de paie' : 'versé le '.\Carbon\Carbon::parse($date)->format('d/m/Y')), 'notes_frais', $expenses->first()->id);
            }

            return ['total' => $total, 'count' => $expenses->count(), 'ref' => $ref];
        });
    }

    /** Paie rouverte ou ligne retirée : les dépenses remboursées sur un bulletin redeviennent « approuvées ». */
    public function releasePayslipItems(int $slipId): void
    {
        Expense::where('payment_ref', 'payslip:'.$slipId)->get()->each(fn ($e) => $e->forceFill(['status' => 'approved', 'paid_via' => null, 'payment_ref' => null, 'paid_at' => null])->save());
    }
}
