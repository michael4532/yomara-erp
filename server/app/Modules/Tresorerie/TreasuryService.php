<?php

namespace App\Modules\Tresorerie;

use App\Models\Company;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\PurchasePayment;
use App\Modules\Compta\Posting;
use App\Modules\Core\Models\Payment;
use App\Modules\ImportExport\ImportService;
use App\Modules\ImportExport\Sheet;
use App\Modules\Tresorerie\Models\Forecast;
use App\Modules\Tresorerie\Models\Operation;
use App\Modules\Tresorerie\Models\Statement;
use App\Modules\Tresorerie\Models\StatementLine;
use App\Modules\Tresorerie\Models\TreasuryAccount;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TreasuryService
{
    public const JOURNALS = ['bank' => 'BQ', 'cash' => 'CA', 'mobile_money' => 'MM'];

    /** Premier compte de chaque type : compte du plan paramétré en comptabilité (null) ; suivants : sous-comptes 5211, 5212… */
    public function nextGlCode(string $kind): ?string
    {
        if (! TreasuryAccount::withTrashed()->where('kind', $kind)->exists()) {
            return null;
        }
        $base = \App\Modules\Compta\Chart::local(['bank' => '521', 'cash' => '571', 'mobile_money' => '5215'][$kind] ?? '521');
        $used = TreasuryAccount::withTrashed()->pluck('gl_code')->filter()->all();
        for ($i = 1; $i < 100; $i++) {
            if (! in_array($base.$i, $used, true)) {
                return $base.$i;
            }
        }

        return $base;
    }

    /** Premiers comptes créés automatiquement : caisse, banque, Mobile Money (à renommer ensuite). */
    public function ensureDefaults(): void
    {
        if (! app()->bound('tenant') || TreasuryAccount::withTrashed()->exists()) {
            return;
        }
        $w = app(\App\Services\EntityWriter::class);
        foreach ([['Caisse', 'cash', 0], ['Banque', 'bank', 1], ['Mobile Money', 'mobile_money', 2]] as [$name, $kind, $sort]) {
            $w->upsert(TreasuryAccount::class, (string) Str::uuid(), ['name' => $name, 'kind' => $kind, 'sort' => $sort, 'opening_date' => now()->startOfYear()->toDateString()]);
        }
    }

    /** Compte par défaut de chaque moyen de paiement (premier compte actif qui l'accepte). */
    public function defaults(): array
    {
        $this->ensureDefaults();
        $map = [];
        foreach (TreasuryAccount::where('active', true)->orderBy('sort')->orderBy('name')->get() as $a) {
            foreach ($a->methods ?? [] as $m) {
                $map[$m] ??= $a->id;
            }
        }

        return $map;
    }

    /** Paiements (encaissements +, règlements −) affectés à un compte, y compris par défaut selon le moyen. */
    public function paymentsFor(TreasuryAccount $a): \Illuminate\Database\Query\Builder
    {
        $methods = collect($this->defaults())->filter(fn ($id) => $id === $a->id)->keys()->all();

        return DB::table('payments')->where('payments.company_id', $a->company_id)->whereNull('payments.deleted_at')
            ->where(fn ($q) => $q->where('payments.treasury_account_id', $a->id)->orWhere(fn ($q) => $q->whereNull('payments.treasury_account_id')->whereIn('payments.method', $methods ?: ['__'])))
            ->when($a->opening_date, fn ($q) => $q->where('payments.date', '>=', $a->opening_date->toDateString()));
    }

    public function balance(TreasuryAccount $a, ?string $at = null): float
    {
        $p = $this->paymentsFor($a)->when($at, fn ($q) => $q->where('payments.date', '<=', $at))
            ->selectRaw("SUM(CASE WHEN direction = 'sale' THEN amount ELSE -amount END) s")->value('s');
        $o = Operation::where('account_id', $a->id)->when($a->opening_date, fn ($q) => $q->where('date', '>=', $a->opening_date->toDateString()))
            ->when($at, fn ($q) => $q->where('date', '<=', $at))->sum('amount');

        return round($a->opening_balance + (float) $p + (float) $o, 2);
    }

    public function summary(): array
    {
        $this->ensureDefaults();

        return TreasuryAccount::orderBy('sort')->orderBy('name')->get()->map(function (TreasuryAccount $a) {
            $last = StatementLine::where('account_id', $a->id)->orderByDesc('date')->orderByDesc('id')->first();
            $bank = $last?->balance;
            if ($last && $bank === null) {
                $bank = round($a->opening_balance + (float) StatementLine::where('account_id', $a->id)->sum('amount'), 2);
            }

            return [
                'id' => $a->id, 'name' => $a->name, 'kind' => $a->kind, 'active' => $a->active,
                'balance' => $this->balance($a),
                'statement_balance' => $bank,
                'statement_date' => $last?->date?->toDateString(),
                'book_at_statement' => $last ? $this->balance($a, $last->date->toDateString()) : null,
                'open_lines' => StatementLine::where('account_id', $a->id)->where('status', 'open')->count(),
            ];
        })->all();
    }

    // ---------- Comptabilisation des opérations ----------

    public function glCode(TreasuryAccount $acc): string
    {
        $map = \App\Modules\Compta\Chart::mapping(Company::find($acc->company_id));

        return $acc->gl_code ?: ($map[['bank' => 'bank', 'cash' => 'cash', 'mobile_money' => 'mobile_money'][$acc->kind]] ?? '521');
    }

    public function postOperation(Operation $op): void
    {
        $company = Company::find($op->company_id);
        $acc = TreasuryAccount::find($op->account_id);
        if (! $company || ! $acc) {
            return;
        }
        $counter = \App\Modules\Compta\Chart::code($company, Operation::CATEGORIES[$op->category][1] ?? '471');
        app(Posting::class)->postExternal($company, self::JOURNALS[$acc->kind] ?? 'BQ', $op->date->toDateString(), $op->label, $op->reference,
            [[$this->glCode($acc), $op->amount, $op->label], [$counter, -$op->amount, $op->label, $op->partner_id]], 'tr_operation', $op->id, 'op');
    }

    /**
     * Solde d'ouverture : D compte de trésorerie / C 471 (à reclasser par le comptable : capital, report à nouveau…),
     * à la date d'ouverture, journal AN. Remplacé si le solde ou la date change ; annulé à zéro.
     */
    public function postOpening(TreasuryAccount $acc, bool $remove = false): void
    {
        $company = Company::find($acc->company_id);
        if (! $company) {
            return;
        }
        $posting = app(Posting::class);
        if ($remove || abs((float) $acc->opening_balance) < 0.005) {
            $posting->cancelExternal($company, 'tr_account', $acc->id, 'opening', 'Solde d\'ouverture retiré : '.$acc->name, false);

            return;
        }
        $label = 'Solde d\'ouverture '.$acc->name;
        $posting->postExternal($company, 'AN', ($acc->opening_date ?? now())->toDateString(), $label, null,
            [[$this->glCode($acc), (float) $acc->opening_balance, $label], [\App\Modules\Compta\Chart::code($company, '471'), -(float) $acc->opening_balance, $label]], 'tr_account', $acc->id, 'opening');
    }

    public function unpostOperation(Operation $op): void
    {
        if ($company = Company::find($op->company_id)) {
            app(Posting::class)->cancelExternal($company, 'tr_operation', $op->id, 'op', 'Suppression : '.$op->label, false);
        }
    }

    /** Une opération rapprochée avec le relevé ne change plus de montant, de date ni de compte, et ne se supprime pas. */
    public function assertNotReconciled(Operation $op, ?array $data): void
    {
        if (! $op->exists || ! StatementLine::where('match_type', 'operation')->where('match_id', $op->id)->exists()) {
            return;
        }
        $changes = $data === null ? true : collect(['amount', 'date', 'account_id'])->contains(fn ($k) => array_key_exists($k, $data) && (string) $data[$k] !== (string) ($k === 'date' ? $op->date?->toDateString() : $op->{$k}) && ! ($k === 'amount' && abs((float) $data[$k] - $op->amount) < 0.001));
        if ($changes) {
            throw new \DomainException('Opération rapprochée avec le relevé : annulez d\'abord le rapprochement.');
        }
    }

    // ---------- Relevés ----------

    public const STATEMENT_FIELDS = [
        'date' => ['date', 'date operation', 'date d operation', 'date comptable', 'date transaction', 'date valeur', 'jour'],
        'label' => ['libelle', 'description', 'designation', 'operation', 'details', 'motif', 'nature', 'type', 'intitule'],
        'debit' => ['debit', 'retrait', 'retraits', 'sortie', 'sorties', 'montant debit', 'decaissement'],
        'credit' => ['credit', 'depot', 'depots', 'entree', 'entrees', 'montant credit', 'versement', 'encaissement'],
        'amount' => ['montant', 'amount', 'valeur', 'somme', 'montant transaction'],
        'balance' => ['solde', 'balance', 'nouveau solde', 'solde apres'],
        'reference' => ['reference', 'ref', 'n operation', 'numero', 'id transaction', 'transaction id', 'piece', 'n piece'],
    ];

    public function analyzeStatement(string $path): array
    {
        [$headers, $rows] = Sheet::read($path, 5000);
        // Certains relevés ont quelques lignes d'en-tête de banque : on cherche la ligne qui ressemble à des titres
        $all = array_merge([$headers], $rows);
        foreach (array_slice($all, 0, 15) as $i => $r) {
            $norm = array_map(fn ($v) => ImportService::norm((string) $v), $r);
            if (count(array_filter($norm, fn ($n) => in_array($n, ['date', 'libelle', 'debit', 'credit', 'montant', 'solde', 'description', 'date operation'], true))) >= 2) {
                $headers = array_map(fn ($v) => trim((string) $v), $r);
                $rows = array_slice($all, $i + 1);
                break;
            }
        }
        $map = [];
        foreach (self::STATEMENT_FIELDS as $field => $names) {
            foreach ($headers as $i => $h) {
                if (in_array($i, $map, true)) {
                    continue;
                }
                $n = ImportService::norm((string) $h);
                if (in_array($n, $names, true) || ($n !== '' && collect($names)->contains(fn ($s) => strlen($s) > 4 && str_starts_with($n, $s)))) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        if (isset($map['debit'], $map['credit'])) {
            unset($map['amount']);
        }

        return ['headers' => $headers, 'rows' => $rows, 'mapping' => $map];
    }

    public function importStatement(TreasuryAccount $acc, string $path, string $filename, array $mapping, bool $invert, ?string $userName): array
    {
        ['rows' => $rows] = $this->analyzeStatement($path);
        $get = fn ($r, $f) => isset($mapping[$f]) ? ($r[$mapping[$f]] ?? null) : null;
        $lines = [];
        $errors = [];
        foreach ($rows as $i => $r) {
            try {
                $date = ImportService::date($get($r, 'date'));
                if (! $date) {
                    continue; // lignes de total, report de solde…
                }
                if (isset($mapping['amount'])) {
                    $amount = ImportService::number($get($r, 'amount')) ?? 0;
                } else {
                    $amount = (ImportService::number($get($r, 'credit')) ?? 0) - abs(ImportService::number($get($r, 'debit')) ?? 0);
                }
                if ($invert) {
                    $amount = -$amount;
                }
                if (abs($amount) < 0.005) {
                    continue;
                }
                $label = trim((string) ($get($r, 'label') ?? ''));
                $lines[] = ['date' => $date, 'label' => mb_substr($label ?: 'Opération', 0, 500), 'amount' => round($amount, 2),
                    'balance' => ImportService::number($get($r, 'balance')), 'reference' => $get($r, 'reference') !== null ? mb_substr((string) $get($r, 'reference'), 0, 120) : null];
            } catch (\DomainException $e) {
                $errors[] = ['row' => $i + 2, 'message' => $e->getMessage()];
            }
        }
        if (! $lines) {
            throw new \DomainException('Aucune opération lisible : vérifiez les colonnes Date et Montant (ou Débit / Crédit).');
        }

        return $this->importLines($acc, $lines, $filename, $userName, 'file', null, $errors);
    }

    /**
     * Enregistre des lignes de relevé (fichier, format bancaire normalisé ou connexion automatique) : doublons écartés
     * (identifiant de la banque, ou date + montant + libellé), rapprochement automatique puis règles.
     *
     * @param  array<int, array{date: string, label: string, amount: float, balance?: ?float, reference?: ?string, external_id?: ?string, counterparty?: ?string}>  $lines
     */
    public function importLines(TreasuryAccount $acc, array $lines, string $filename, ?string $userName, string $source = 'file', ?float $closing = null, array $errors = []): array
    {
        return DB::transaction(function () use ($acc, $filename, $lines, $errors, $userName, $source, $closing) {
            $fresh = [];
            $dup = 0;
            $seen = [];
            foreach ($lines as $l) {
                $l['label'] = mb_substr(trim((string) ($l['label'] ?? '')) ?: 'Opération', 0, 500);
                $l['amount'] = round((float) $l['amount'], 2);
                $l['reference'] = isset($l['reference']) && $l['reference'] !== null ? mb_substr((string) $l['reference'], 0, 120) : null;
                if (! empty($l['external_id'])) {
                    $hash = hash('sha256', 'ext|'.$l['external_id']);
                } else {
                    // Deux opérations identiques le même jour restent distinctes grâce au rang
                    $base = $l['date'].'|'.$l['amount'].'|'.Str::lower($l['label']).'|'.$l['reference'];
                    $seen[$base] = ($seen[$base] ?? 0) + 1;
                    $hash = hash('sha256', $base.'|'.$seen[$base]);
                }
                if (isset($fresh[$hash]) || StatementLine::where('account_id', $acc->id)->where('hash', $hash)->exists()) {
                    $dup++;

                    continue;
                }
                $fresh[$hash] = ['date' => $l['date'], 'label' => $l['label'], 'amount' => $l['amount'], 'balance' => $l['balance'] ?? null, 'reference' => $l['reference'],
                    'external_id' => isset($l['external_id']) ? mb_substr((string) $l['external_id'], 0, 120) : null, 'counterparty' => isset($l['counterparty']) ? mb_substr((string) $l['counterparty'], 0, 190) : null];
            }
            if (! $fresh) {
                return ['statement_id' => null, 'imported' => 0, 'duplicates' => $dup, 'matched' => 0, 'rules' => 0, 'errors' => $errors];
            }
            $st = Statement::create(['account_id' => $acc->id, 'filename' => mb_substr($filename, 0, 255), 'user_name' => $userName, 'source' => $source, 'closing_balance' => $closing,
                'date_from' => collect($fresh)->min('date'), 'date_to' => collect($fresh)->max('date')]);
            foreach ($fresh as $hash => $l) {
                StatementLine::create($l + ['account_id' => $acc->id, 'statement_id' => $st->id, 'hash' => $hash]);
            }
            $st->forceFill(['lines' => count($fresh), 'duplicates' => $dup])->save();
            $auto = $this->autoMatch($acc);
            $ruled = app(\App\Modules\Tresorerie\Bank\BankRules::class)->apply($acc);

            return ['statement_id' => $st->id, 'imported' => count($fresh), 'duplicates' => $dup, 'matched' => $auto, 'rules' => $ruled, 'errors' => $errors];
        });
    }

    /** Mouvements du livre (paiements et opérations) d'un compte, avec leur montant signé. */
    public function bookMovements(TreasuryAccount $acc, ?string $from = null, ?string $to = null): Collection
    {
        $pay = $this->paymentsFor($acc)->when($from, fn ($q) => $q->where('payments.date', '>=', $from))->when($to, fn ($q) => $q->where('payments.date', '<=', $to))
            ->leftJoin('trade_documents as d', 'd.id', '=', 'payments.document_id')
            ->get(['payments.id', 'payments.date', 'payments.amount', 'payments.direction', 'payments.method', 'payments.reference', 'd.number', 'd.partner_name'])
            ->map(fn ($p) => ['type' => 'payment', 'id' => $p->id, 'date' => substr($p->date, 0, 10), 'amount' => $p->direction === 'sale' ? (float) $p->amount : -(float) $p->amount,
                'label' => trim(($p->direction === 'sale' ? 'Encaissement ' : 'Règlement ').($p->number ?? '').' '.($p->partner_name ?? '')), 'reference' => $p->reference, 'partner' => $p->partner_name, 'number' => $p->number]);
        $ops = Operation::where('account_id', $acc->id)->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to))->get()
            ->map(fn ($o) => ['type' => 'operation', 'id' => $o->id, 'date' => $o->date->toDateString(), 'amount' => $o->amount, 'label' => $o->label, 'reference' => $o->reference, 'partner' => null, 'number' => null]);

        return $pay->concat($ops)->values();
    }

    protected function matchedIds(TreasuryAccount $acc): array
    {
        return StatementLine::where('account_id', $acc->id)->where('status', 'matched')->pluck('match_id')->flip()->all();
    }

    /** Score de ressemblance (plus petit = meilleur) entre une ligne de relevé et un mouvement. */
    protected function score(StatementLine $l, array $m): ?float
    {
        if (abs($l->amount - $m['amount']) > 0.5) {
            return null;
        }
        $days = abs(Carbon::parse($m['date'])->diffInDays($l->date));
        if ($days > 10) {
            return null;
        }
        $text = ImportService::norm($l->label.' '.$l->reference);
        $bonus = 0;
        foreach (array_filter([$m['number'], $m['reference'], $m['partner']]) as $needle) {
            $n = ImportService::norm((string) $needle);
            if (strlen($n) >= 4 && str_contains($text, $n)) {
                $bonus -= 20;
            } elseif ($m['partner'] === $needle) {
                // un mot significatif du nom du client dans le libellé
                foreach (explode(' ', $n) as $w) {
                    if (strlen($w) >= 5 && str_contains($text, $w)) {
                        $bonus -= 8;
                        break;
                    }
                }
            }
        }

        return $days + $bonus;
    }

    /** Rapprochement automatique : montant identique, date proche, et un seul candidat (ou un candidat nettement meilleur). */
    public function autoMatch(TreasuryAccount $acc): int
    {
        $open = StatementLine::where('account_id', $acc->id)->where('status', 'open')->orderBy('date')->get();
        if ($open->isEmpty()) {
            return 0;
        }
        $book = $this->bookMovements($acc, $open->min('date')->copy()->subDays(10)->toDateString(), $open->max('date')->copy()->addDays(10)->toDateString());
        $taken = $this->matchedIds($acc);
        $n = 0;
        foreach ($open as $l) {
            $c = $book->filter(fn ($m) => ! isset($taken[$m['id']]))->map(fn ($m) => $m + ['score' => $this->score($l, $m)])->filter(fn ($m) => $m['score'] !== null)->sortBy('score')->values();
            if ($c->isEmpty()) {
                continue;
            }
            if ($c->count() === 1 || $c[1]['score'] - $c[0]['score'] >= 3) {
                $this->setMatch($l, $c[0]['type'], $c[0]['id'], 'auto');
                $taken[$c[0]['id']] = true;
                $n++;
            }
        }

        return $n;
    }

    public function candidates(StatementLine $l): array
    {
        $acc = TreasuryAccount::findOrFail($l->account_id);
        $taken = $this->matchedIds($acc);
        $book = $this->bookMovements($acc, $l->date->copy()->subDays(30)->toDateString(), $l->date->copy()->addDays(30)->toDateString())
            ->filter(fn ($m) => ! isset($taken[$m['id']]) && ($m['amount'] > 0) === ($l->amount > 0))
            ->map(fn ($m) => $m + ['score' => abs($l->amount - $m['amount']) < 0.5 ? ($this->score($l, $m) ?? 50) : 100 + abs(Carbon::parse($m['date'])->diffInDays($l->date))])
            ->sortBy('score')->take(15)->values();
        // Factures ouvertes du même montant : encaissement / règlement à créer
        $docClass = $l->amount > 0 ? SalesDocument::class : PurchaseDocument::class;
        $types = $l->amount > 0 ? ['invoice'] : ['supplier_invoice'];
        $text = ImportService::norm($l->label.' '.$l->reference);
        $docs = $docClass::where('status', 'validated')->whereIn('type', $types)->whereIn('payment_status', ['unpaid', 'partial'])->orderBy('date')->limit(500)->get()
            ->map(fn ($d) => ['id' => $d->id, 'number' => $d->number, 'partner' => $d->partner_name, 'date' => $d->date?->toDateString(), 'balance' => $d->balance(),
                'exact' => abs($d->balance() - abs($l->amount)) < 0.5,
                'named' => ($d->number && str_contains($text, ImportService::norm($d->number))) || collect(explode(' ', ImportService::norm((string) $d->partner_name)))->contains(fn ($w) => strlen($w) >= 5 && str_contains($text, $w))])
            ->sortBy(fn ($d) => ($d['exact'] ? 0 : 2) + ($d['named'] ? 0 : 1))->take(10)->values();

        return ['movements' => $book->all(), 'documents' => $docs->all()];
    }

    public function setMatch(StatementLine $l, string $type, string $id, string $by = 'manual'): void
    {
        if (StatementLine::where('match_type', $type)->where('match_id', $id)->where('id', '!=', $l->id)->exists()) {
            throw new \DomainException('Ce mouvement est déjà rapproché d\'une autre ligne du relevé.');
        }
        $l->forceFill(['status' => 'matched', 'match_type' => $type, 'match_id' => $id, 'matched_at' => now(), 'matched_by' => $by])->save();
    }

    public function unmatch(StatementLine $l): void
    {
        $l->forceFill(['status' => 'open', 'match_type' => null, 'match_id' => null, 'matched_at' => null, 'matched_by' => null])->save();
    }

    /** Ligne du relevé sans contrepartie : crée l'opération (frais, loyer…) et la rapproche. */
    public function createOperation(StatementLine $l, string $category, ?string $label, string $by = 'manual'): Operation
    {
        $r = app(\App\Services\EntityWriter::class)->upsert(Operation::class, (string) Str::uuid(), [
            'account_id' => $l->account_id, 'date' => $l->date->toDateString(), 'amount' => $l->amount, 'label' => $label ?: Str::limit($l->label, 250, ''), 'category' => $category, 'reference' => $l->reference,
        ]);
        if ($r['status'] !== 'applied') {
            throw new \DomainException($r['message'] ?? 'Opération refusée.');
        }
        $this->setMatch($l, 'operation', $r['record']->id, $by);

        return $r['record'];
    }

    /** Virement reçu (ou émis) pour une facture : crée le paiement sur ce compte et le rapproche. */
    public function createPayment(StatementLine $l, string $documentId): Payment
    {
        $acc = TreasuryAccount::findOrFail($l->account_id);
        $class = $l->amount > 0 ? SalesPayment::class : PurchasePayment::class;
        $method = ['cash' => 'cash', 'mobile_money' => 'mobile_money'][$acc->kind] ?? 'bank_transfer';
        $r = app(\App\Services\EntityWriter::class)->upsert($class, (string) Str::uuid(), [
            'document_id' => $documentId, 'date' => $l->date->toDateString(), 'amount' => abs($l->amount), 'method' => $method,
            'reference' => $l->reference ?: Str::limit($l->label, 95, ''), 'treasury_account_id' => $acc->id,
        ]);
        if ($r['status'] !== 'applied') {
            throw new \DomainException($r['errors'] ? collect($r['errors'])->flatten()->first() : ($r['message'] ?? 'Paiement refusé.'));
        }
        $this->setMatch($l, 'payment', $r['record']->id);

        return $r['record'];
    }

    // ---------- Prévision ----------

    /** Prévision de trésorerie (scénario réaliste à plat, plus les trois scénarios) : voir CashForecastService. */
    public function forecast(int $weeks = 13): array
    {
        return app(CashForecastService::class)->compute($weeks);
    }
}
