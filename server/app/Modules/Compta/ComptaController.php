<?php

namespace App\Modules\Compta;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Modules\Compta\Models\Account;
use App\Modules\Compta\Models\Entry;
use App\Modules\Compta\Models\Journal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ComptaController extends Controller
{
    public function __construct(protected Posting $posting, protected Ledger $ledger) {}

    protected function company()
    {
        $c = app('tenant');
        Chart::ensure($c);

        return $c;
    }

    protected function period(Request $r): array
    {
        $from = $r->query('from', FiscalCalendar::startOf(app('tenant'), now()->toDateString()));
        $to = $r->query('to', now()->toDateString());

        return [$from, $to];
    }

    // ---------- Référentiel ----------

    public function setup()
    {
        $c = $this->company();

        return [
            'accounts' => Account::orderBy('code')->get(),
            'journals' => Journal::orderBy('id')->get(),
            'mapping' => Chart::mapping($c),
            'mapping_labels' => [
                'customers' => 'Clients', 'suppliers' => 'Fournisseurs', 'sales_goods' => 'Ventes de marchandises',
                'sales_services' => 'Ventes de services', 'purchases_goods' => 'Achats de marchandises', 'purchases_services' => 'Achats de services et frais',
                'vat_collected' => 'TVA collectée', 'vat_deductible' => 'TVA déductible', 'cash' => 'Caisse (espèces)', 'bank' => 'Banque (virements)',
                'card' => 'Carte bancaire', 'cheque' => 'Chèques à encaisser', 'mobile_money' => 'Mobile Money', 'other' => 'Autres moyens',
                'loyalty' => 'Paiements en points fidélité', 'gift_card' => 'Cartes cadeaux (avances clients)',
            ],
            'lock_date' => $this->posting->lockDate($c),
            'purchase_review' => PurchaseReview::enabled($c),
            'purchase_kinds' => collect(Chart::purchaseKinds($c))->map(fn ($k, $key) => ['key' => $key, 'label' => $k[0], 'account' => $k[1], 'group' => $k[2]])->values(),
            'referential' => ['key' => Referentials::of($c)] + Referentials::LIST[Referentials::of($c)],
            'referentials' => Referentials::options(),
            'can_change_referential' => ! Entry::exists(),
            'fiscal_start_month' => FiscalCalendar::startMonth($c),
            'can_change_fiscal_start' => ! \App\Modules\Compta\Models\FiscalYear::where('status', 'closed')->exists(),
            'to_review' => PurchaseReview::enabled($c) ? \App\Modules\Achats\Models\PurchaseDocument::where('type', 'supplier_invoice')->where('status', 'validated')->whereIn('acc_status', ['to_review', 'approved'])->count() : 0,
        ];
    }

    public function storeAccount(Request $request)
    {
        $c = $this->company();
        $data = $request->validate([
            'code' => ['required', 'regex:/^[1-9][0-9]{1,9}$/', Rule::unique('compta_accounts', 'code')->where('company_id', $c->id)],
            'name' => 'required|string|max:255',
        ], ['code.regex' => 'Le numéro de compte doit commencer par la classe (1 à 9) et ne contenir que des chiffres.']);

        return response()->json(['data' => Account::create($data + ['company_id' => $c->id])], 201);
    }

    public function updateAccount(Request $request, int $id)
    {
        $acc = Account::findOrFail($id);
        $acc->update($request->validate(['name' => 'sometimes|string|max:255', 'active' => 'sometimes|boolean']));

        return ['data' => $acc];
    }

    public function updateSettings(Request $request)
    {
        $c = $this->company();
        $data = $request->validate([
            'lock_date' => 'nullable|date',
            'purchase_review' => 'sometimes|boolean',
            'mapping' => 'sometimes|array',
            'mapping.*' => ['string', Rule::exists('compta_accounts', 'code')->where('company_id', $c->id)],
            'referential' => ['sometimes', Rule::in(array_keys(Referentials::LIST))],
            'fiscal_start_month' => 'sometimes|integer|min:1|max:12',
        ], ['mapping.*.exists' => 'Compte inconnu : créez-le d\'abord dans le plan comptable.']);
        if (isset($data['referential']) && $data['referential'] !== Referentials::of($c)) {
            try {
                Referentials::switchTo($c, $data['referential']);
            } catch (\DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            ActivityLog::record('compta.referential', $c, ['referential' => $data['referential']]);
            $c = app('tenant')->fresh();
            app()->instance('tenant', $c);
            unset($data['mapping']);
        }

        $settings = $c->settings ?? [];
        if (isset($data['fiscal_start_month']) && (int) $data['fiscal_start_month'] !== FiscalCalendar::startMonth($c)) {
            if (\App\Modules\Compta\Models\FiscalYear::where('status', 'closed')->exists()) {
                return response()->json(['message' => 'Un exercice est déjà clôturé : le début d\'exercice ne peut plus être changé.'], 422);
            }
            data_set($settings, 'compta.fiscal_start_month', (int) $data['fiscal_start_month']);
        }
        if (array_key_exists('lock_date', $data)) {
            data_set($settings, 'compta.lock_date', $data['lock_date'] ? substr($data['lock_date'], 0, 10) : null);
        }
        if (array_key_exists('purchase_review', $data)) {
            data_set($settings, 'compta.purchase_review', (bool) $data['purchase_review']);
        }
        if (isset($data['mapping'])) {
            $allowed = array_intersect_key($data['mapping'], Chart::DEFAULT_MAPPING);
            data_set($settings, 'compta.accounts', array_merge((array) data_get($settings, 'compta.accounts', []), $allowed));
        }
        $c->update(['settings' => $settings]);
        ActivityLog::record('compta.settings', $c, $data);

        return $this->setup();
    }

    public function rebuild()
    {
        return ['data' => $this->posting->rebuild($this->company())];
    }

    // ---------- Écritures ----------

    public function entries(Request $request)
    {
        $this->company();
        [$from, $to] = $this->period($request);

        $q = Entry::with(['lines.account:id,code,name', 'journal:id,code,name'])
            ->whereBetween('date', [$from, $to])
            ->when($request->query('journal'), fn ($q, $j) => $q->whereHas('journal', fn ($w) => $w->where('code', $j)))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('label', 'like', "%$s%")->orWhere('number', 'like', "%$s%")->orWhere('reference', 'like', "%$s%")))
            ->orderByDesc('date')->orderByDesc('id');

        return $q->paginate(min((int) $request->query('per_page', 50), 200));
    }

    public function storeEntry(Request $request)
    {
        $c = $this->company();
        $data = $request->validate([
            'journal_id' => ['required', Rule::exists('compta_journals', 'id')->where('company_id', $c->id)],
            'date' => 'required|date',
            'label' => 'required|string|max:255',
            'reference' => 'nullable|string|max:100',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => ['required', Rule::exists('compta_accounts', 'id')->where('company_id', $c->id)],
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.label' => 'nullable|string|max:255',
            'lines.*.partner_id' => 'nullable|uuid',
        ]);

        try {
            $entry = $this->posting->manual($c, $data['journal_id'], $data['date'], $data['label'], $data['reference'] ?? null, $data['lines'], $request->user()->id);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        ActivityLog::record('compta.entry.created', $entry);

        return response()->json(['data' => $entry->load(['lines.account:id,code,name', 'journal:id,code,name'])], 201);
    }

    public function reverseEntry(int $id)
    {
        $this->company();
        $entry = Entry::with('lines')->findOrFail($id);
        try {
            $rev = $this->posting->reverse($entry);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        ActivityLog::record('compta.entry.reversed', $entry);

        return ['data' => $rev->load(['lines.account:id,code,name', 'journal:id,code,name'])];
    }

    // ---------- États ----------

    public function balance(Request $r)
    {
        [$from, $to] = $this->period($r);

        return ['data' => $this->ledger->trialBalance($this->company(), $from, $to)];
    }

    public function ledger(Request $r, int $account)
    {
        [$from, $to] = $this->period($r);

        return ['data' => $this->ledger->generalLedger($this->company(), $account, $from, $to)];
    }

    public function income(Request $r)
    {
        [$from, $to] = $this->period($r);

        return ['data' => $this->ledger->incomeStatement($this->company(), $from, $to)];
    }

    public function sheet(Request $r)
    {
        return ['data' => $this->ledger->balanceSheet($this->company(), $r->query('at', now()->toDateString()))];
    }

    public function vat(Request $r)
    {
        $from = $r->query('from', now()->startOfMonth()->toDateString());
        $to = $r->query('to', now()->endOfMonth()->toDateString());

        return ['data' => $this->ledger->vat($this->company(), $from, $to)];
    }

    /** Export des écritures pour l'expert-comptable (CSV ; compatible Excel). */
    public function export(Request $r)
    {
        $this->company();
        [$from, $to] = $this->period($r);
        $rows = Entry::with(['lines.account:id,code,name', 'journal:id,code'])
            ->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
        app(Tiers::class)->ensureCodes(app('tenant'));
        $partners = \Illuminate\Support\Facades\DB::table('partners')->whereIn('id', $rows->flatMap->lines->pluck('partner_id')->filter()->unique())->get(['id', 'name', 'aux_code'])->keyBy('id');

        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Journal', 'Date', 'N° pièce', 'Référence', 'Compte', 'Intitulé du compte', 'Compte tiers', 'Tiers', 'Libellé', 'Débit', 'Crédit'], ';');
        foreach ($rows as $e) {
            foreach ($e->lines as $l) {
                fputcsv($out, \App\Support\SafeCell::csvRow([
                    $e->journal->code, $e->date->format('d/m/Y'), $e->number, $e->reference, $l->account->code, $l->account->name,
                    $partners[$l->partner_id]->aux_code ?? '', $partners[$l->partner_id]->name ?? '', $l->label, number_format($l->debit, 2, ',', ''), number_format($l->credit, 2, ',', ''),
                ]), ';');
            }
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ecritures_'.$from.'_'.$to.'.csv"',
        ]);
    }

    // ---------- Factures fournisseurs : vérification et comptabilisation ----------

    public function purchases(Request $r, PurchaseReview $review)
    {
        $c = $this->company();
        $status = $r->query('status', 'to_review');
        $q = \App\Modules\Achats\Models\PurchaseDocument::with('lines')->where('type', 'supplier_invoice')->where('status', 'validated')
            ->when($status === 'pending', fn ($w) => $w->whereNull('acc_status'))
            ->when($status !== 'all' && $status !== 'pending', fn ($w) => $w->where('acc_status', $status))
            ->when($r->query('q'), fn ($w, $s) => $w->where(fn ($x) => $x->where('number', 'like', "%$s%")->orWhere('partner_name', 'like', "%$s%")->orWhere('reference', 'like', "%$s%")))
            ->orderByDesc('date')->orderByDesc('number')->limit(300)->get();
        $rules = $review->rules($c);
        $counts = \App\Modules\Achats\Models\PurchaseDocument::where('type', 'supplier_invoice')->where('status', 'validated')->whereNotNull('acc_status')
            ->groupBy('acc_status')->selectRaw('acc_status, COUNT(*) as n')->pluck('n', 'acc_status');

        return ['data' => $q->map(fn ($d) => $review->toApi($c, $d, $rules))->values(), 'counts' => $counts, 'enabled' => PurchaseReview::enabled($c), 'statuses' => PurchaseReview::STATUSES];
    }

    public function decidePurchase(Request $r, string $id, PurchaseReview $review)
    {
        $c = $this->company();
        $v = $r->validate(['decision' => 'required|in:approve,refuse,post', 'note' => 'nullable|string|max:2000', 'lines' => 'nullable|array', 'remember' => 'nullable|boolean']);
        $doc = \App\Modules\Achats\Models\PurchaseDocument::findOrFail($id);
        try {
            $doc = $review->decide($c, $doc, $v['decision'], $v['note'] ?? null, (array) ($v['lines'] ?? []), $r->boolean('remember', true), $r->user()->id);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        ActivityLog::record('compta.purchase.'.$v['decision'], $doc, ['note' => $v['note'] ?? null]);

        return ['data' => $review->toApi($c, $doc->load('lines'))];
    }

    /** Comptabilise d'un coup toutes les factures approuvées. */
    public function postApproved(PurchaseReview $review)
    {
        $c = $this->company();
        $n = 0;
        $errors = [];
        foreach (\App\Modules\Achats\Models\PurchaseDocument::where('type', 'supplier_invoice')->where('status', 'validated')->where('acc_status', 'approved')->orderBy('date')->get() as $doc) {
            try {
                $review->decide($c, $doc, 'post', null, [], false, auth()->id());
                $n++;
            } catch (\DomainException $e) {
                $errors[] = ($doc->number ?? $doc->id).' : '.$e->getMessage();
            }
        }

        return ['data' => ['posted' => $n, 'errors' => $errors]];
    }

    public function resubmitPurchase(Request $r, string $id, PurchaseReview $review)
    {
        $c = $this->company();
        $v = $r->validate(['reply' => 'required|string|max:2000']);
        try {
            $doc = $review->resubmit($c, \App\Modules\Achats\Models\PurchaseDocument::findOrFail($id), $v['reply']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => $review->toApi($c, $doc->load('lines'))];
    }

    /** Comptes mémorisés (article, fournisseur, catégorie → compte d'achat). */
    public function rules(PurchaseReview $review)
    {
        $c = $this->company();
        $rows = \Illuminate\Support\Facades\DB::table('compta_account_rules')->where('company_id', $c->id)->orderBy('scope')->get();
        $names = [
            'product' => \Illuminate\Support\Facades\DB::table('products')->whereIn('id', $rows->where('scope', 'product')->pluck('ref_id'))->pluck('name', 'id'),
            'supplier' => \Illuminate\Support\Facades\DB::table('partners')->whereIn('id', $rows->where('scope', 'supplier')->pluck('ref_id'))->pluck('name', 'id'),
            'category' => \Illuminate\Support\Facades\DB::table('product_categories')->whereIn('id', $rows->where('scope', 'category')->pluck('ref_id'))->pluck('name', 'id'),
        ];

        return ['data' => $rows->map(fn ($x) => ['id' => $x->id, 'scope' => $x->scope, 'ref_id' => $x->ref_id, 'name' => $names[$x->scope][$x->ref_id] ?? '—',
            'account_code' => $x->account_code, 'account_label' => PurchaseReview::kindLabel($x->account_code)])->values(),
            'categories' => \Illuminate\Support\Facades\DB::table('product_categories')->where('company_id', $c->id)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])];
    }

    public function saveRule(Request $r, PurchaseReview $review)
    {
        $c = $this->company();
        $v = $r->validate(['scope' => 'required|in:product,supplier,category', 'ref_id' => 'required|string|max:40', 'account_code' => ['nullable', 'regex:/^[26][0-9]{1,9}$/']],
            ['account_code.regex' => 'Choisissez un compte de charge (6) ou d\'immobilisation (2).']);
        $review->saveRule($c, $v['scope'], $v['ref_id'], $v['account_code'] ?? null);

        return $this->rules($review);
    }

    // ---------- Comptes de tiers ----------

    public function tiers(Request $r, Tiers $tiers)
    {
        [$from, $to] = $this->period($r);
        $side = $r->query('side') === 'suppliers' ? 'suppliers' : 'customers';

        return ['data' => $tiers->balance($this->company(), $side, $from, $to, $r->query('q'))];
    }

    public function tiersLedger(Request $r, string $partner, Tiers $tiers)
    {
        [$from, $to] = $this->period($r);
        $side = $r->query('side') === 'suppliers' ? 'suppliers' : 'customers';
        try {
            return ['data' => $tiers->ledger($this->company(), $side, $partner, $from, $to)];
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    // ---------- États financiers SYSCOHADA ----------

    public function statement(Request $r, string $kind, Statements $st)
    {
        $c = $this->company();
        [$from, $to] = $this->period($r);

        return ['data' => match ($kind) {
            'income' => $st->income($c, $from, $to),
            'sheet' => $st->sheet($c, $to),
            'cashflow' => $st->cashFlow($c, $from, $to),
        }];
    }

    /** Export Excel d'un état (bilan, compte de résultat, flux de trésorerie, balance des tiers). */
    public function exportStatement(Request $r, string $kind, Statements $st, Tiers $tiers)
    {
        $c = $this->company();
        [$from, $to] = $this->period($r);
        if ($kind === 'tiers') {
            $side = $r->query('side') === 'suppliers' ? 'suppliers' : 'customers';
            $b = $tiers->balance($c, $side, $from, $to);
            [$headers, $rows] = [['Compte tiers', 'Nom', 'Solde d\'ouverture', 'Débit', 'Crédit', 'Solde'], array_map(fn ($x) => [$x['aux_code'], $x['name'], $x['opening'], $x['debit'], $x['credit'], $x['balance']], $b['rows'])];
        } else {
            [$headers, $rows] = $st->exportRows($c, $kind, $from, $to);
        }
        $path = tempnam(sys_get_temp_dir(), 'etat').'.xlsx';
        \App\Modules\ImportExport\Sheet::write($path, $headers, $rows);
        $names = ['income' => 'compte_de_resultat', 'sheet' => 'bilan', 'cashflow' => 'flux_de_tresorerie', 'tiers' => 'balance_tiers'];

        return response()->download($path, ($names[$kind] ?? 'etat').'_'.$from.'_'.$to.'.xlsx')->deleteFileAfterSend();
    }

    // ---------- Tableau de bord ----------

    public function dashboard(Request $r, Statements $st, Tiers $tiers)
    {
        $c = $this->company();
        [$from, $to] = $this->period($r);
        $k = $st->income($c, $from, $to)['kpis'];
        $treasury = \Illuminate\Support\Facades\DB::table('compta_entry_lines as l')->join('compta_entries as e', 'e.id', '=', 'l.entry_id')->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $c->id)->where('e.date', '<=', $to)->where('a.code', 'like', '5%')->groupBy('a.code', 'a.name')
            ->selectRaw('a.code, a.name, SUM(l.debit - l.credit) as s')->get()->map(fn ($x) => ['code' => $x->code, 'name' => $x->name, 'balance' => round((float) $x->s, 2)])
            ->filter(fn ($x) => abs($x['balance']) > 0.004)->values();
        // 12 derniers mois : produits et charges
        $start = \Carbon\Carbon::parse($to)->startOfMonth()->subMonths(11);
        $monthly = \Illuminate\Support\Facades\DB::table('compta_entry_lines as l')->join('compta_entries as e', 'e.id', '=', 'l.entry_id')->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $c->id)->where('e.kind', '!=', 'closing')->whereBetween('e.date', [$start->toDateString(), $to])->where(fn ($q) => $q->where('a.code', 'like', '6%')->orWhere('a.code', 'like', '7%'))
            ->get(['e.date', 'a.code', 'l.debit', 'l.credit'])->groupBy(fn ($x) => substr((string) $x->date, 0, 7));
        $months = [];
        for ($m = $start->copy(); $m->lte(\Carbon\Carbon::parse($to)); $m->addMonth()) {
            $rows = $monthly->get($m->format('Y-m'), collect());
            $months[] = ['month' => $m->format('Y-m'), 'income' => round($rows->filter(fn ($x) => $x->code[0] === '7')->sum(fn ($x) => $x->credit - $x->debit), 2),
                'expenses' => round($rows->filter(fn ($x) => $x->code[0] === '6')->sum(fn ($x) => $x->debit - $x->credit), 2)];
        }
        $charges = \Illuminate\Support\Facades\DB::table('compta_entry_lines as l')->join('compta_entries as e', 'e.id', '=', 'l.entry_id')->join('compta_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $c->id)->where('e.kind', '!=', 'closing')->whereBetween('e.date', [$from, $to])->where('a.code', 'like', '6%')->groupBy('a.code', 'a.name')
            ->selectRaw('a.code, a.name, SUM(l.debit - l.credit) as s')->orderByDesc('s')->limit(8)->get()->map(fn ($x) => ['code' => $x->code, 'name' => $x->name, 'amount' => round((float) $x->s, 2)]);
        $cust = $tiers->balance($c, 'customers', $from, $to);
        $supp = $tiers->balance($c, 'suppliers', $from, $to);
        $review = PurchaseReview::enabled($c) ? \App\Modules\Achats\Models\PurchaseDocument::where('type', 'supplier_invoice')->where('status', 'validated')->whereNotNull('acc_status')
            ->groupBy('acc_status')->selectRaw('acc_status, COUNT(*) as n')->pluck('n', 'acc_status') : collect();
        $vat = $this->ledger->vat($c, \Carbon\Carbon::parse($to)->startOfMonth()->toDateString(), \Carbon\Carbon::parse($to)->endOfMonth()->toDateString());
        $lastEntry = Entry::max('date');

        return ['data' => [
            'from' => $from, 'to' => $to,
            'referential' => ['key' => Referentials::of($c)] + Referentials::LIST[Referentials::of($c)],
            'kpis' => ['revenue' => $k['revenue']['n'] ?? 0, 'revenue_n1' => $k['revenue']['n1'] ?? 0, 'margin' => $k['margin']['n'] ?? null, 'value_added' => $k['value_added']['n'] ?? null,
                'ebitda' => $k['ebitda']['n'] ?? null, 'operating' => $k['operating']['n'] ?? 0, 'result' => $k['result']['n'] ?? 0, 'result_n1' => $k['result']['n1'] ?? 0, 'treasury' => round($treasury->sum('balance'), 2),
                'receivables' => $cust['totals']['due'], 'payables' => $supp['totals']['due'], 'vat_due' => $vat['due'], 'vat_credit' => $vat['credit']],
            'treasury' => $treasury, 'months' => $months, 'charges' => $charges,
            'debtors' => collect($cust['rows'])->sortByDesc('due')->take(5)->values(), 'creditors' => collect($supp['rows'])->sortByDesc('due')->take(5)->values(),
            'review' => ['enabled' => PurchaseReview::enabled($c), 'to_review' => (int) ($review['to_review'] ?? 0), 'approved' => (int) ($review['approved'] ?? 0), 'refused' => (int) ($review['refused'] ?? 0)],
            'entries' => Entry::whereBetween('date', [$from, $to])->count(), 'last_entry' => $lastEntry, 'lock_date' => $this->posting->lockDate($c),
        ]];
    }

    // ---------- Fin d'exercice : clôture, impôt, affectation, liasse ----------

    protected function closing(): Closing
    {
        return app(Closing::class);
    }

    protected function runDomain(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function years()
    {
        $c = $this->company();

        return ['data' => $this->closing()->years($c), 'fiscal_settings' => Closing::fiscalSettings($c), 'lock_date' => $this->posting->lockDate($c)];
    }

    public function yearDetail(int $year)
    {
        $c = $this->company();
        $cl = $this->closing();
        $fy = $cl->year($c, $year);

        [$ps, $pe] = FiscalCalendar::period($c, $year);

        return ['data' => ['year' => $year, 'label' => FiscalCalendar::label($c, $year), 'start' => $ps, 'end' => $pe, 'status' => $fy->status, 'closed_at' => $fy->closed_at, 'result' => $fy->result, 'checks' => $cl->checks($c, $year),
            'fiscal' => $cl->fiscal($c, $year), 'allocation' => $cl->allocationProposal($c, $year), 'lock_date' => $this->posting->lockDate($c),
            'depreciation_module' => $c->hasModule('immobilisations')]];
    }

    public function saveYearFiscal(Request $r, int $year)
    {
        $v = $r->validate(['reintegrations' => 'present|array|max:50', 'deductions' => 'present|array|max:50', 'reintegrations.*.amount' => 'numeric', 'deductions.*.amount' => 'numeric']);

        return $this->runDomain(fn () => ['data' => $this->closing()->saveFiscal($this->company(), $year, $v['reintegrations'], $v['deductions'])]);
    }

    public function saveFiscalSettings(Request $r)
    {
        $v = $r->validate(['is_rate' => 'nullable|numeric|min:0|max:100', 'min_rate' => 'nullable|numeric|min:0|max:100', 'min_floor' => 'nullable|numeric|min:0', 'min_cap' => 'nullable|numeric|min:0']);

        return ['data' => $this->closing()->saveFiscalSettings($this->company(), $v)];
    }

    public function yearAction(Request $r, int $year, string $action)
    {
        $c = $this->company();
        $cl = $this->closing();

        return $this->runDomain(function () use ($r, $c, $cl, $year, $action) {
            match ($action) {
                'tax' => $cl->postTax($c, $year),
                'close' => $cl->close($c, $year, $r->user()),
                'reopen' => $cl->reopen($c, $year),
                'allocate' => $cl->allocate($c, $year, $r->validate(['legal_reserve' => 'nullable|numeric|min:0', 'other_reserves' => 'nullable|numeric|min:0', 'dividends' => 'nullable|numeric|min:0',
                    'report' => 'nullable|numeric', 'date' => 'required|date']), (string) $r->input('date')),
                'cancel-allocation' => $cl->cancelAllocation($c, $year),
                'depreciation' => $c->hasModule('immobilisations') ? app(\App\Modules\Immobilisations\AssetService::class)->postDue($c, FiscalCalendar::period($c, $year)[1]) : throw new \DomainException('Module Immobilisations inactif.'),
            };
            ActivityLog::record('compta.year.'.$action, $c, ['year' => $year]);

            return $this->yearDetail($year);
        });
    }

    public function liasse(Request $r, int $year, ?string $format = null)
    {
        $c = $this->company();
        $cl = $this->closing();

        return match ($format) {
            'xlsx' => response()->download($cl->liasseBook($c, $year), 'liasse_'.$year.'.xlsx')->deleteFileAfterSend(),
            'pdf' => response($cl->liassePdf($c, $year), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="liasse_'.$year.'.pdf"']),
            default => ['data' => $cl->liasse($c, $year)],
        };
    }

    public function vatReturn(Request $r, ?string $format = null)
    {
        $c = $this->company();
        $from = $r->query('from', now()->startOfMonth()->toDateString());
        $to = $r->query('to', now()->endOfMonth()->toDateString());
        $v = app(VatReturn::class);

        return match ($format) {
            'xlsx' => response()->download($v->book($c, $from, $to), 'tva_'.$from.'_'.$to.'.xlsx')->deleteFileAfterSend(),
            'pdf' => response($v->pdf($c, $from, $to), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="etat_annexe_tva.pdf"']),
            default => ['data' => $v->build($c, $from, $to)],
        };
    }

    // ------------------------------------------------------------------ consolidation

    protected function conso(): Consolidation
    {
        // module vendu à part : la filiale peut générer un code et voir son groupe sans l'avoir
        abort_unless($this->company()->hasModule('consolidation') || in_array(request()->route()?->getActionMethod(), ['group', 'groupLinkCode', 'groupDetach'], true), 403, 'Module Consolidation de groupe non activé.');

        return app(Consolidation::class);
    }

    public function group()
    {
        $c = $this->company();
        $parent = \App\Modules\Compta\Models\GroupMember::with('parent')->where('member_id', $c->id)->first();
        $link = \Illuminate\Support\Facades\DB::table('compta_group_links')->where('company_id', $c->id)->where('expires_at', '>', now())->first();
        [$from, $to] = FiscalCalendar::period($c, FiscalCalendar::yearOf($c, now()->toDateString()));

        return ['data' => [
            'members' => $this->conso()->members($c), 'methods' => Consolidation::METHODS,
            'parent' => $parent ? ['id' => $parent->id, 'name' => $parent->parent?->name, 'method' => Consolidation::METHODS[$parent->method] ?? $parent->method, 'interest_pct' => $parent->interest_pct] : null,
            'link_code' => $link ? ['code' => $link->code, 'expires_at' => $link->expires_at] : null,
            'currency' => $c->currency, 'default_from' => $from, 'default_to' => $to,
            'deferred_tax_rate' => Consolidation::deferredTaxRate($c),
            'companies' => collect([['id' => $c->id, 'name' => $c->name]])->merge(collect($this->conso()->members($c))->map(fn ($m) => ['id' => $m['member_id'], 'name' => $m['name']]))->values(),
        ]];
    }

    public function groupLinkCode()
    {
        $c = $this->company();
        ActivityLog::record('compta.group.code', $c);

        return ['data' => $this->conso()->linkCode($c)];
    }

    protected function memberRules(): array
    {
        return ['interest_pct' => 'nullable|numeric|min:0|max:100', 'control_pct' => 'nullable|numeric|min:0|max:100', 'method' => 'nullable|in:ig,ip,mee',
            'investment_cost' => 'nullable|numeric|min:0', 'equity_at_acquisition' => 'nullable|numeric', 'acquisition_date' => 'nullable|date', 'rate' => 'nullable|numeric|min:0', 'average_rate' => 'nullable|numeric|min:0', 'historical_rate' => 'nullable|numeric|min:0',
            'goodwill_years' => 'nullable|integer|min:0|max:40', 'goodwill_impairment' => 'nullable|numeric|min:0', 'goodwill_impairment_date' => 'nullable|date',
            'exit_date' => 'nullable|date', 'exit_proceeds' => 'nullable|numeric|min:0'];
    }

    public function groupAttach(Request $r)
    {
        $c = $this->company();
        $v = $r->validate(['code' => 'required|string|max:16'] + $this->memberRules());

        return $this->runDomain(function () use ($c, $v) {
            $g = $this->conso()->attach($c, $v['code'], collect($v)->except('code')->filter(fn ($x) => $x !== null)->all());
            ActivityLog::record('compta.group.attach', $c, ['member' => $g->member_id]);

            return ['data' => $this->conso()->memberToApi($c, $g->load('member'))];
        });
    }

    public function groupUpdate(Request $r, int $id)
    {
        $c = $this->company();
        $g = \App\Modules\Compta\Models\GroupMember::where('parent_id', $c->id)->findOrFail($id);
        $v = $r->validate($this->memberRules());

        return $this->runDomain(function () use ($c, $g, $v, $r) {
            $g->update($this->conso()->clean(array_intersect_key($v, $r->all())));

            return ['data' => $this->conso()->memberToApi($c, $g->fresh('member'))];
        });
    }

    public function groupDetach(int $id)
    {
        $c = $this->company();
        $g = \App\Modules\Compta\Models\GroupMember::where(fn ($q) => $q->where('parent_id', $c->id)->orWhere('member_id', $c->id))->findOrFail($id);
        $g->delete();
        ActivityLog::record('compta.group.detach', $c, ['member' => $g->member_id]);

        return ['ok' => true];
    }

    public function consolidation(Request $r, ?string $format = null)
    {
        $c = $this->company();
        [$df, $dt] = FiscalCalendar::period($c, FiscalCalendar::yearOf($c, now()->toDateString()));
        $from = $r->query('from', $df);
        $to = $r->query('to', $dt);

        return $this->runDomain(fn () => match ($format) {
            'xlsx' => response()->download($this->conso()->book($c, $from, $to), 'consolidation_'.$from.'_'.$to.'.xlsx')->deleteFileAfterSend(),
            'pdf' => response($this->conso()->pdf($c, $from, $to), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="comptes_consolides_'.$to.'.pdf"']),
            default => ['data' => $this->conso()->statements($c, $from, $to, $r->boolean('fresh')), 'adjustments' => $this->conso()->adjustments($c, $to)],
        });
    }

    protected function periodOf(Request $r): array
    {
        $v = $r->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);

        return [Carbon::parse($v['from'])->toDateString(), Carbon::parse($v['to'])->toDateString()];
    }

    public function consolidationFreeze(Request $r)
    {
        $c = $this->company();
        [$from, $to] = $this->periodOf($r);

        return $this->runDomain(function () use ($c, $from, $to, $r) {
            $d = $this->conso()->freeze($c, $from, $to, $r->user()->id);
            ActivityLog::record('compta.consolidation.freeze', $c, ['from' => $from, 'to' => $to]);

            return ['data' => $d, 'adjustments' => $this->conso()->adjustments($c, $to)];
        });
    }

    public function consolidationUnfreeze(Request $r)
    {
        $c = $this->company();
        [$from, $to] = $this->periodOf($r);
        $this->conso()->unfreeze($c, $from, $to);
        ActivityLog::record('compta.consolidation.unfreeze', $c, ['from' => $from, 'to' => $to]);

        return $this->runDomain(fn () => ['data' => $this->conso()->statements($c, $from, $to), 'adjustments' => $this->conso()->adjustments($c, $to)]);
    }

    public function groupStake(Request $r, int $id)
    {
        $c = $this->company();
        $g = \App\Modules\Compta\Models\GroupMember::where('parent_id', $c->id)->findOrFail($id);
        $v = $r->validate(['date' => 'required|date', 'change_pct' => 'required|numeric|between:-100,100', 'amount' => 'nullable|numeric|min:0', 'cost_basis' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:190']);

        return $this->runDomain(function () use ($c, $g, $v) {
            $g = $this->conso()->addStake($c, $g, $v);
            ActivityLog::record('compta.group.stake', $c, ['member' => $g->member_id, 'change' => $v['change_pct']]);

            return ['data' => $this->conso()->memberToApi($c, $g)];
        });
    }

    public function groupStakeDelete(int $id)
    {
        $c = $this->company();
        $s = \App\Modules\Compta\Models\GroupStake::findOrFail($id);
        $g = \App\Modules\Compta\Models\GroupMember::where('parent_id', $c->id)->findOrFail($s->group_member_id);
        $s->delete();

        return ['data' => $this->conso()->memberToApi($c, $g->fresh(['member', 'stakes']))];
    }

    public function groupSettings(Request $r)
    {
        $c = $this->company();
        $v = $r->validate(['deferred_tax_rate' => 'nullable|numeric|min:0|max:60']);
        $settings = $c->settings ?? [];
        data_set($settings, 'compta.group.deferred_tax_rate', $v['deferred_tax_rate'] ?? null);
        $c->update(['settings' => $settings]);

        return ['data' => ['deferred_tax_rate' => Consolidation::deferredTaxRate($c->fresh())]];
    }

    public function groupAdjustment(Request $r)
    {
        $c = $this->company();
        $v = $r->validate(['to' => 'required|date', 'id' => 'nullable|integer', 'kind' => 'required|in:stock_margin,dividend,entry', 'label' => 'nullable|string|max:190',
            'seller_id' => 'nullable|integer', 'buyer_id' => 'nullable|integer', 'amount' => 'nullable|numeric|min:0', 'margin_pct' => 'nullable|numeric|min:0|max:100',
            'lines' => 'nullable|array|max:30', 'lines.*.account' => 'nullable|string|max:16', 'lines.*.debit' => 'nullable|numeric|min:0', 'lines.*.credit' => 'nullable|numeric|min:0']);

        return $this->runDomain(fn () => ['data' => $this->conso()->saveAdjustment($c, Carbon::parse($v['to'])->toDateString(), $v, $r->user()->id)]);
    }

    public function groupAdjustmentDelete(int $id)
    {
        $c = $this->company();

        return $this->runDomain(function () use ($c, $id) {
            $this->conso()->deleteAdjustment($c, $id);

            return ['ok' => true];
        });
    }
}
