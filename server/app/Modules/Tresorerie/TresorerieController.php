<?php

namespace App\Modules\Tresorerie;

use App\Http\Controllers\Controller;
use App\Modules\Tresorerie\Models\Operation;
use App\Modules\Tresorerie\Models\Statement;
use App\Modules\Tresorerie\Models\StatementLine;
use App\Modules\Tresorerie\Models\TreasuryAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TresorerieController extends Controller
{
    public function __construct(protected TreasuryService $svc) {}

    public function summary()
    {
        return response()->json(['data' => $this->svc->summary()]);
    }

    public function categories()
    {
        return response()->json(['data' => collect(Operation::CATEGORIES)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'account' => $c[1], 'sign' => $c[2]])->values()]);
    }

    public function forecast(Request $request)
    {
        return response()->json(['data' => $this->svc->forecast((int) $request->query('weeks', 0))]);
    }

    /** Relevé déposé : colonnes reconnues et aperçu, avant import. */
    public function analyze(Request $request, string $account)
    {
        TreasuryAccount::findOrFail($account);
        $request->validate(['file' => 'required|file|max:10240']);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        // Formats bancaires normalisés : reconnus sans correspondance de colonnes
        $raw = (string) file_get_contents($file->getRealPath());
        if ($fmt = \App\Modules\Tresorerie\Bank\StatementFormats::detect($raw)) {
            try {
                $p = \App\Modules\Tresorerie\Bank\StatementFormats::parse($raw, $fmt);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Relevé '.\App\Modules\Tresorerie\Bank\StatementFormats::LABELS[$fmt].' illisible : '.$e->getMessage()], 422);
            }
            $rel = 'statements/'.app('tenant')->id.'/'.Str::uuid().'.std';
            Storage::disk('local')->put($rel, $raw);

            return response()->json(['data' => ['file' => basename($rel), 'filename' => $file->getClientOriginalName(), 'format' => $fmt, 'format_label' => \App\Modules\Tresorerie\Bank\StatementFormats::LABELS[$fmt],
                'headers' => ['Date', 'Libellé', 'Montant', 'Référence'], 'sample' => array_map(fn ($l) => [$l['date'], $l['label'], $l['amount'], $l['reference']], array_slice($p['lines'], 0, 8)),
                'rows' => count($p['lines']), 'mapping' => (object) ['date' => 0, 'label' => 1, 'amount' => 2, 'reference' => 3], 'closing' => $p['closing'], 'currency' => $p['currency']]]);
        }
        if (! in_array($ext, ['xlsx', 'csv', 'txt'], true)) {
            return response()->json(['message' => 'Relevé attendu en Excel (.xlsx), CSV, OFX, MT940, CAMT.053 ou CFONB 120 : votre banque ou votre opérateur Mobile Money le propose à l\'export.'], 422);
        }
        $rel = 'statements/'.app('tenant')->id.'/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->putFileAs(dirname($rel), $file, basename($rel));
        try {
            $a = $this->svc->analyzeStatement(Storage::disk('local')->path($rel));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Fichier illisible : '.$e->getMessage()], 422);
        }

        return response()->json(['data' => ['file' => basename($rel), 'filename' => $file->getClientOriginalName(), 'headers' => $a['headers'], 'sample' => array_slice($a['rows'], 0, 8), 'rows' => count($a['rows']), 'mapping' => (object) $a['mapping']]]);
    }

    public function import(Request $request, string $account)
    {
        $acc = TreasuryAccount::findOrFail($account);
        $v = $request->validate(['file' => ['required', 'string', 'regex:/^[0-9a-f-]+\.(xlsx|csv|txt|std)$/'], 'filename' => 'nullable|string|max:255', 'mapping' => 'nullable|array', 'mapping.*' => 'nullable|integer|min:0', 'invert' => 'boolean']);
        $path = Storage::disk('local')->path('statements/'.app('tenant')->id.'/'.$v['file']);
        abort_unless(is_file($path), 404);
        if (str_ends_with($v['file'], '.std')) {
            try {
                $p = \App\Modules\Tresorerie\Bank\StatementFormats::parse((string) file_get_contents($path));
                if ($v['invert'] ?? false) {
                    $p['lines'] = array_map(fn ($l) => ['amount' => -$l['amount']] + $l, $p['lines']);
                }
                if (! $p['lines']) {
                    return response()->json(['message' => 'Ce relevé ne contient aucune opération.'], 422);
                }

                return response()->json(['data' => $this->svc->importLines($acc, $p['lines'], $v['filename'] ?? $v['file'], $request->user()->name, $p['format'], $p['closing'])]);
            } catch (\DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }
        $v['mapping'] ??= [];
        $mapping = array_filter($v['mapping'], fn ($x) => $x !== null);
        if (! isset($mapping['date']) || (! isset($mapping['amount']) && ! isset($mapping['debit']) && ! isset($mapping['credit']))) {
            return response()->json(['message' => 'Indiquez au moins la colonne Date et la colonne Montant (ou Débit et Crédit).'], 422);
        }
        try {
            return response()->json(['data' => $this->svc->importStatement($acc, $path, $v['filename'] ?? $v['file'], $mapping, $v['invert'] ?? false, $request->user()->name)]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Lignes du relevé d'un compte, avec le mouvement rapproché. */
    public function lines(Request $request, string $account)
    {
        $acc = TreasuryAccount::findOrFail($account);
        $status = $request->query('status', 'open');
        $lines = StatementLine::where('account_id', $acc->id)->when($status !== 'all', fn ($q) => $q->where('status', $status))->orderByDesc('date')->orderByDesc('id')->limit(500)->get();
        $book = $this->svc->bookMovements($acc)->keyBy('id');

        return response()->json(['data' => [
            'lines' => $lines->map(fn ($l) => $l->only(['id', 'date', 'label', 'amount', 'balance', 'reference', 'status', 'match_type', 'match_id', 'matched_by']) + ['date' => $l->date->toDateString(), 'match' => $l->match_id ? $book->get($l->match_id) : null]),
            'statements' => Statement::where('account_id', $acc->id)->latest('id')->take(10)->get(),
            // Mouvements du livre sans ligne de relevé (à vérifier : chèque pas encore encaissé, oubli…)
            'unmatched_book' => (function () use ($acc, $book) {
                $first = StatementLine::where('account_id', $acc->id)->min('date');
                $last = StatementLine::where('account_id', $acc->id)->max('date');
                if (! $first) {
                    return [];
                }
                $taken = StatementLine::where('account_id', $acc->id)->where('status', 'matched')->pluck('match_id')->flip();

                return $book->filter(fn ($m) => $m['date'] >= substr($first, 0, 10) && $m['date'] <= substr($last, 0, 10) && ! $taken->has($m['id']))->sortBy('date')->values();
            })(),
        ]]);
    }

    public function auto(string $account)
    {
        return response()->json(['data' => ['matched' => $this->svc->autoMatch(TreasuryAccount::findOrFail($account))]]);
    }

    public function candidates(int $id)
    {
        return response()->json(['data' => $this->svc->candidates(StatementLine::findOrFail($id))]);
    }

    public function act(Request $request, int $id)
    {
        $l = StatementLine::findOrFail($id);
        $v = $request->validate([
            'action' => 'required|in:match,unmatch,ignore,operation,payment',
            'type' => 'required_if:action,match|in:payment,operation', 'id' => 'required_if:action,match|uuid',
            'category' => 'required_if:action,operation|in:'.implode(',', array_keys(Operation::CATEGORIES)), 'label' => 'nullable|string|max:255',
            'document_id' => 'required_if:action,payment|uuid',
        ]);
        // Rapprochement manuel : uniquement avec un mouvement du même compte (paiement ou opération de cette société)
        if ($v['action'] === 'match' && ! $this->svc->bookMovements(TreasuryAccount::findOrFail($l->account_id))->contains(fn ($m) => $m['type'] === $v['type'] && (string) $m['id'] === (string) $v['id'])) {
            return response()->json(['message' => 'Mouvement introuvable sur ce compte.'], 422);
        }
        try {
            match ($v['action']) {
                'match' => $this->svc->setMatch($l, $v['type'], $v['id']),
                'unmatch' => $this->svc->unmatch($l),
                'ignore' => $l->forceFill(['status' => 'ignored'])->save(),
                'operation' => $this->svc->createOperation($l, $v['category'], $v['label'] ?? null),
                'payment' => $this->svc->createPayment($l, $v['document_id']),
            };
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $l->fresh()]);
    }

    public function forecastSettings(CashForecastService $svc)
    {
        return response()->json(['data' => $svc->settings(app('tenant'))]);
    }

    public function saveForecastSettings(Request $request, CashForecastService $svc)
    {
        $v = $request->validate([
            'threshold' => 'sometimes|numeric|min:-1000000000000|max:1000000000000', 'weeks' => 'sometimes|integer|min:4|max:26',
            'pessimistic_extra_days' => 'sometimes|integer|min:0|max:180', 'doubtful_days' => 'sometimes|integer|min:15|max:720',
            'orders_pessimistic' => 'sometimes|integer|min:0|max:100', 'invoice_lag_days' => 'sometimes|integer|min:0|max:120',
            'supplier_terms_days' => 'sometimes|integer|min:0|max:180', 'include_pipeline' => 'sometimes|boolean',
            'vat_enabled' => 'sometimes|boolean', 'vat_day' => 'sometimes|integer|min:1|max:28',
        ]);

        return response()->json(['data' => $svc->saveSettings(app('tenant'), $v)]);
    }
}
