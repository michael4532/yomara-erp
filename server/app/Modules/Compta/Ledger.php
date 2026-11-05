<?php

namespace App\Modules\Compta;

use App\Models\Company;
use App\Modules\Compta\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * États comptables calculés à partir des lignes d'écritures.
 * Exercice = année civile.
 */
class Ledger
{
    /** Rubriques du compte de résultat (SYSCOHADA, présentation simplifiée). */
    public const RESULT_GROUPS = [
        'produits' => [
            '70' => 'Ventes (chiffre d\'affaires)', '71' => 'Subventions d\'exploitation', '72' => 'Production immobilisée',
            '73' => 'Variations des stocks de produits', '75' => 'Autres produits', '77' => 'Revenus financiers',
            '78' => 'Transferts de charges', '79' => 'Reprises de provisions', '82' => 'Produits des cessions (HAO)', '84' => 'Autres produits HAO',
            '86' => 'Reprises HAO', '88' => 'Subventions d\'équilibre',
        ],
        'charges' => [
            '60' => 'Achats et variations de stocks', '61' => 'Transports', '62' => 'Services extérieurs A', '63' => 'Services extérieurs B',
            '64' => 'Impôts et taxes', '65' => 'Autres charges', '66' => 'Charges de personnel', '67' => 'Frais financiers',
            '68' => 'Dotations aux amortissements', '69' => 'Dotations aux provisions', '81' => 'Valeurs comptables des cessions (HAO)',
            '83' => 'Charges HAO', '85' => 'Dotations HAO', '87' => 'Participation des travailleurs', '89' => 'Impôts sur le résultat',
        ],
    ];

    /** Lignes agrégées par compte sur une période (bornes incluses). */
    protected function totals(Company $company, ?string $from, ?string $to, ?string $codePrefix = null)
    {
        return DB::table('compta_entry_lines as l')
            ->join('compta_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $company->id)
            ->where('e.kind', '!=', 'closing') // états de gestion : avant écriture de clôture des comptes de charges et produits
            ->when($from, fn ($q) => $q->where('e.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('e.date', '<=', $to))
            ->when($codePrefix, fn ($q) => $q->where('a.code', 'like', $codePrefix.'%'))
            ->groupBy('a.id', 'a.code', 'a.name')
            ->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get()
            ->map(fn ($r) => [
                'account_id' => $r->id, 'code' => $r->code, 'name' => $r->name,
                'debit' => round((float) $r->debit, 2), 'credit' => round((float) $r->credit, 2),
                'balance' => round((float) $r->debit - (float) $r->credit, 2),
            ]);
    }

    /** Balance générale : mouvements de la période + soldes. */
    public function trialBalance(Company $company, string $from, string $to): array
    {
        $rows = $this->totals($company, $from, $to)->map(fn ($r) => $r + [
            'debit_balance' => max(0, $r['balance']), 'credit_balance' => max(0, -$r['balance']),
        ])->values();

        return [
            'from' => $from, 'to' => $to,
            'rows' => $rows,
            'totals' => [
                'debit' => round($rows->sum('debit'), 2), 'credit' => round($rows->sum('credit'), 2),
                'debit_balance' => round($rows->sum('debit_balance'), 2), 'credit_balance' => round($rows->sum('credit_balance'), 2),
            ],
        ];
    }

    /** Grand livre d'un compte avec solde progressif (report à nouveau avant la période). */
    public function generalLedger(Company $company, int $accountId, string $from, string $to): array
    {
        $account = Account::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($accountId);
        $opening = (float) DB::table('compta_entry_lines as l')->join('compta_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.company_id', $company->id)->where('l.account_id', $accountId)->where('e.date', '<', $from)
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as s')->value('s');

        $running = round($opening, 2);
        $rows = DB::table('compta_entry_lines as l')
            ->join('compta_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('compta_journals as j', 'j.id', '=', 'e.journal_id')
            ->leftJoin('partners as p', 'p.id', '=', 'l.partner_id')
            ->where('l.company_id', $company->id)->where('l.account_id', $accountId)
            ->whereBetween('e.date', [$from, $to])
            ->orderBy('e.date')->orderBy('e.id')
            ->get(['e.id as entry_id', 'e.date', 'e.number', 'j.code as journal', 'l.label', 'e.reference', 'p.name as partner', 'l.debit', 'l.credit'])
            ->map(function ($r) use (&$running) {
                $running = round($running + (float) $r->debit - (float) $r->credit, 2);

                return [
                    'entry_id' => $r->entry_id, 'date' => $r->date, 'number' => $r->number, 'journal' => $r->journal,
                    'label' => $r->label, 'reference' => $r->reference, 'partner' => $r->partner,
                    'debit' => round((float) $r->debit, 2), 'credit' => round((float) $r->credit, 2), 'balance' => $running,
                ];
            });

        return [
            'account' => $account->only(['id', 'code', 'name']),
            'from' => $from, 'to' => $to,
            'opening' => round($opening, 2),
            'rows' => $rows,
            'debit' => round($rows->sum('debit'), 2),
            'credit' => round($rows->sum('credit'), 2),
            'closing' => $running,
        ];
    }

    /** Compte de résultat de la période. */
    public function incomeStatement(Company $company, string $from, string $to): array
    {
        $ref = Referentials::of($company);
        $groups = Referentials::groups($ref);
        $classes = Referentials::resultClasses($ref);
        $rows = $this->totals($company, $from, $to)->filter(fn ($r) => in_array($r['code'][0], $classes, true));
        $section = function (string $side) use ($rows, $groups) {
            $out = [];
            $mine = $rows->filter(fn ($r) => Referentials::group($groups[$side], $r['code']) !== null && ! ($side === 'produits' && $r['code'][0] === '6') && ! ($side === 'charges' && $r['code'][0] === '7'))
                ->groupBy(fn ($r) => Referentials::group($groups[$side], $r['code']));
            foreach ($groups[$side] as $prefix => $label) {
                $sum = $mine->get((string) $prefix, collect())->sum('balance');
                $amount = round($side === 'produits' ? -$sum : $sum, 2);
                if (abs($amount) > 0.004) {
                    $out[] = ['code' => (string) $prefix, 'label' => $label, 'amount' => $amount];
                }
            }

            return $out;
        };
        $produits = $section('produits');
        $charges = $section('charges');
        $tp = round(array_sum(array_column($produits, 'amount')), 2);
        $tc = round(array_sum(array_column($charges, 'amount')), 2);

        return ['from' => $from, 'to' => $to, 'produits' => $produits, 'charges' => $charges,
            'total_produits' => $tp, 'total_charges' => $tc, 'resultat' => round($tp - $tc, 2)];
    }

    /**
     * Bilan à une date (présentation simplifiée) : actif / passif à partir des soldes.
     * Classes 4 et 5 : débiteurs à l'actif, créditeurs au passif. Le résultat de
     * l'exercice en cours est intégré aux capitaux propres.
     */
    public function balanceSheet(Company $company, string $at): array
    {
        $rows = $this->totals($company, null, $at);
        $yearStart = FiscalCalendar::startOf($company, $at);
        $result = $this->incomeStatement($company, $yearStart, $at)['resultat'];
        // Résultats des exercices antérieurs non affectés (classes 6-8 avant l'exercice)
        $ref = Referentials::of($company);
        $classes = Referentials::resultClasses($ref);
        $prior = -round($this->totals($company, null, date('Y-m-d', strtotime($yearStart.' -1 day')))
            ->filter(fn ($r) => in_array($r['code'][0], $classes, true))->sum('balance'), 2);

        $actif = ['immobilisations' => 0.0, 'stocks' => 0.0, 'creances' => 0.0, 'tresorerie' => 0.0];
        $passif = ['capitaux' => 0.0, 'dettes_financieres' => 0.0, 'dettes' => 0.0, 'tresorerie' => 0.0];
        $details = ['actif' => [], 'passif' => []];

        foreach ($rows as $r) {
            $c = $r['code'][0];
            $b = $r['balance'];
            if (abs($b) < 0.005 || in_array($c, $classes, true) || ($ref !== 'pcg' && $ref !== 'cgnc' && $c === '8')) {
                continue;
            }
            [$side, $key] = match (true) {
                $c === '2' => ['actif', 'immobilisations'],
                $ref === 'cgnc' && $c === '3' && ! str_starts_with($r['code'], '31') && ! str_starts_with($r['code'], '391') => $b > 0 ? ['actif', 'creances'] : ['passif', 'dettes'],
                $ref === 'cgnc' && $c === '1' && in_array(substr($r['code'], 0, 2), ['14', '15', '16', '17'], true) => ['passif', 'dettes_financieres'],
                $c === '3' => ['actif', 'stocks'],
                $c === '1' && in_array(substr($r['code'], 0, 2), ['16', '17', '18'], true) => ['passif', 'dettes_financieres'],
                $c === '1' => ['passif', 'capitaux'],
                $c === '4' => $b > 0 ? ['actif', 'creances'] : ['passif', 'dettes'],
                $c === '5' => $b > 0 ? ['actif', 'tresorerie'] : ['passif', 'tresorerie'],
                default => ['actif', 'creances'],
            };
            $amount = $side === 'actif' ? $b : -$b;
            ${$side}[$key] += $amount;
            $details[$side][] = ['code' => $r['code'], 'name' => $r['name'], 'group' => $key, 'amount' => round($amount, 2)];
        }
        $passif['capitaux'] += $result + $prior;

        $ta = round(array_sum($actif), 2);
        $tp = round(array_sum($passif), 2);

        return [
            'at' => $at,
            'actif' => array_map(fn ($v) => round($v, 2), $actif),
            'passif' => array_map(fn ($v) => round($v, 2), $passif),
            'resultat' => $result,
            'report' => $prior,
            'total_actif' => $ta,
            'total_passif' => $tp,
            'equilibre' => abs($ta - $tp) < 0.01,
            'details' => $details,
        ];
    }

    /** Déclaration de TVA d'une période (souvent un mois). */
    public function vat(Company $company, string $from, string $to): array
    {
        $map = Chart::mapping($company);
        $sum = fn (string $prefix) => $this->totals($company, $from, $to, $prefix)->sum('balance');
        $vp = Referentials::vat(Referentials::of($company));
        $collected = round(-$sum($vp['collected']), 2);   // créditeur
        $deductible = round($sum($vp['deductible']), 2);   // débiteur
        $net = round($collected - $deductible, 2);

        // Bases HT par taux, à partir des documents validés de la période
        $bases = DB::table('trade_document_lines as l')
            ->join('trade_documents as d', 'd.id', '=', 'l.document_id')
            ->where('d.company_id', $company->id)->where('d.status', 'validated')->whereNull('d.deleted_at')
            ->whereBetween('d.date', [$from, $to])
            ->whereIn('d.type', ['invoice', 'receipt', 'credit_note', 'supplier_invoice'])
            ->groupBy('d.direction', 'l.tax_rate')
            ->selectRaw("d.direction, l.tax_rate, SUM(CASE WHEN d.type = 'credit_note' THEN -l.subtotal ELSE l.subtotal END) as base, SUM(CASE WHEN d.type = 'credit_note' THEN -l.tax_amount ELSE l.tax_amount END) as tax")
            ->get()
            ->map(fn ($r) => ['direction' => $r->direction, 'rate' => (float) $r->tax_rate, 'base' => round((float) $r->base, 2), 'tax' => round((float) $r->tax, 2)]);

        return [
            'from' => $from, 'to' => $to,
            'collected' => $collected,
            'deductible' => $deductible,
            'due' => max(0, $net),
            'credit' => max(0, -$net),
            'sales_bases' => $bases->where('direction', 'sale')->values(),
            'purchase_bases' => $bases->where('direction', 'purchase')->values(),
            'accounts' => ['collected' => $map['vat_collected'], 'deductible' => $map['vat_deductible']],
        ];
    }

    /** Solde de trésorerie (classe 5) à date. */
    public function treasury(Company $company, ?string $at = null): float
    {
        return round($this->totals($company, null, $at, '5')->sum('balance'), 2);
    }
}
