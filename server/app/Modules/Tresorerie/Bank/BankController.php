<?php

namespace App\Modules\Tresorerie\Bank;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Tresorerie\Models\Operation;
use App\Modules\Tresorerie\Models\StatementLine;
use App\Modules\Tresorerie\Models\TreasuryAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Connexions bancaires (synchronisation automatique des relevés) et règles de rapprochement. */
class BankController extends Controller
{
    public function __construct(protected BankSync $sync, protected BankRules $rules) {}

    protected function row(BankConnection $c): array
    {
        return [
            'id' => $c->id, 'provider' => $c->provider, 'provider_label' => BankConnection::PROVIDERS[$c->provider] ?? $c->provider, 'label' => $c->label,
            'account_id' => $c->account_id, 'account' => DB::table('tr_accounts')->where('id', $c->account_id)->value('name'), 'status' => $c->status,
            'last_sync_at' => $c->last_sync_at?->toIso8601String(), 'next_sync_at' => $c->next_sync_at?->toIso8601String(), 'last_error' => $c->last_error,
            'imported' => $c->imported, 'last_balance' => $c->last_balance, 'institution' => $c->meta['institution_name'] ?? null,
            'inbound_address' => $c->inboundAddress(), 'inbound_url' => $c->inboundUrl(), 'expires_at' => $c->meta['expires_at'] ?? null,
            'has_key' => ! empty($c->credentials),
        ];
    }

    public function index()
    {
        return ['data' => [
            'connections' => BankConnection::orderBy('created_at')->get()->map(fn ($c) => $this->row($c)),
            'rules' => DB::table('tr_bank_rules')->where('company_id', app('tenant')->id)->orderBy('sort')->orderBy('id')->get(),
            'providers' => collect(BankConnection::PROVIDERS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'formats' => array_values(StatementFormats::LABELS),
            'gocardless_platform' => (bool) (config('erp.bank.gocardless_secret_id') && config('erp.bank.gocardless_secret_key')),
            'inbound_domain' => config('erp.bank.inbound_domain'),
            'categories' => collect(Operation::CATEGORIES)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'sign' => $c[2]])->values(),
        ]];
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'provider' => ['required', Rule::in(array_keys(BankConnection::PROVIDERS))], 'account_id' => 'required|uuid', 'label' => 'nullable|string|max:120',
            'api_key' => 'nullable|string|max:255', 'secret_id' => 'nullable|string|max:120', 'secret_key' => 'nullable|string|max:255', 'sync_from' => 'nullable|date',
        ]);
        TreasuryAccount::findOrFail($d['account_id']);
        $creds = array_filter(['api_key' => $d['api_key'] ?? null, 'secret_id' => $d['secret_id'] ?? null, 'secret_key' => $d['secret_key'] ?? null]);
        if ($d['provider'] === 'wave' && empty($creds['api_key'])) {
            return response()->json(['message' => 'Saisissez la clé API Wave Business (droit « Balance & Reconciliation »).'], 422);
        }
        $c = BankConnection::create(['provider' => $d['provider'], 'account_id' => $d['account_id'], 'label' => $d['label'] ?? null, 'credentials' => $creds ?: null,
            'status' => $d['provider'] === 'email' ? 'active' : 'pending', 'inbound_token' => $d['provider'] === 'email' ? BankSync::token() : null,
            'sync_from' => $d['sync_from'] ?? now()->subDays(30)->toDateString(), 'created_by' => $r->user()->id]);
        $out = ['data' => $this->row($c)];
        if ($d['provider'] === 'wave') {
            try {
                $out['sync'] = $this->sync->sync($c->fresh(), true);
            } catch (\DomainException $e) {
                $out['warning'] = $e->getMessage();
            }
            $out['data'] = $this->row($c->fresh());
        }

        return response()->json($out, 201);
    }

    public function update(Request $r, string $id)
    {
        $c = BankConnection::findOrFail($id);
        $d = $r->validate(['label' => 'nullable|string|max:120', 'paused' => 'nullable|boolean', 'api_key' => 'nullable|string|max:255']);
        if (array_key_exists('label', $d)) {
            $c->label = $d['label'];
        }
        if (array_key_exists('paused', $d)) {
            $c->status = $d['paused'] ? 'paused' : ($c->provider === 'email' ? 'active' : 'pending');
            $c->next_sync_at = null;
        }
        if (! empty($d['api_key'])) {
            $c->credentials = array_merge($c->credentials ?? [], ['api_key' => $d['api_key']]);
            $c->status = 'pending';
        }
        $c->save();

        return ['data' => $this->row($c)];
    }

    public function destroy(string $id)
    {
        BankConnection::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function syncNow(string $id)
    {
        $c = BankConnection::findOrFail($id);
        try {
            $res = $this->sync->sync($c, true);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => $this->row($c->fresh())], 422);
        }

        return ['data' => $this->row($c->fresh()), 'result' => $res];
    }

    // ---------------------------------------------------------------- Banques européennes (GoCardless)
    public function institutions(Request $r)
    {
        try {
            return ['data' => Providers::gcInstitutions($r->query('country', 'fr'))];
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function link(Request $r, string $id)
    {
        $c = BankConnection::where('provider', 'gocardless')->findOrFail($id);
        $d = $r->validate(['institution_id' => 'required|string|max:120', 'institution_name' => 'nullable|string|max:190']);
        try {
            $l = Providers::gcLink($c, $d['institution_id'], url('/banque/retour/'.$c->id));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $c->forceFill(['status' => 'pending', 'meta' => array_merge($c->meta ?? [], ['requisition' => $l['requisition'], 'institution' => $d['institution_id'],
            'institution_name' => $d['institution_name'] ?? $d['institution_id'], 'expires_at' => now()->addDays(90)->toDateString()]), 'last_error' => null])->save();

        return ['link' => $l['link'], 'data' => $this->row($c)];
    }

    /** Retour de la banque après l'authentification du client (page publique). */
    public function callback(string $id)
    {
        $c = BankConnection::withoutGlobalScopes()->where('provider', 'gocardless')->find($id);
        abort_unless($c, 404);
        $company = Company::find($c->company_id);
        app()->instance('tenant', $company);
        $ok = false;
        $msg = '';
        try {
            $a = Providers::gcAccounts($c);
            if ($a['accounts']) {
                $c->forceFill(['meta' => array_merge($c->meta ?? [], ['gc_account' => $a['accounts'][0]]), 'status' => 'active', 'next_sync_at' => now()])->save();
                $r = $this->sync->sync($c->fresh(), true);
                $ok = true;
                $msg = $r['imported'].' opération(s) importée(s). La synchronisation se fera ensuite automatiquement toutes les 4 heures.';
            } else {
                $msg = 'La banque n\'a pas confirmé l\'accès (statut '.($a['status'] ?? '?').'). Recommencez depuis Banque & trésorerie.';
            }
        } catch (\DomainException $e) {
            $msg = $e->getMessage();
        }
        $color = $ok ? '#12A150' : '#D93025';

        return response('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion bancaire</title></head>'
            .'<body style="font-family:system-ui,sans-serif;background:#F4F6FB;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">'
            .'<div style="background:#fff;border-radius:16px;padding:28px;max-width:440px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.08)">'
            .'<div style="font-size:42px;color:'.$color.'">'.($ok ? '✓' : '!').'</div><h2 style="margin:8px 0">'.($ok ? 'Banque connectée' : 'Connexion non terminée').'</h2>'
            .'<p style="color:#5B6B85;line-height:1.5">'.e($msg).'</p><a href="'.e(url('/app/')).'" style="display:inline-block;margin-top:12px;background:#1F6FEB;color:#fff;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:700">Retour à Yomara</a></div></body></html>');
    }

    // ---------------------------------------------------------------- Relevés reçus par e-mail
    /**
     * Webhook public : e-mail transféré par le service de réception (Mailgun, Postmark, SendGrid, Brevo…) ou envoi
     * direct de fichiers. Les pièces jointes sont lues et importées.
     */
    public function inbound(Request $r, string $token)
    {
        $c = BankConnection::withoutGlobalScopes()->where('provider', 'email')->where('inbound_token', $token)->first();
        abort_unless($c && $c->status !== 'paused', 404);
        $company = Company::find($c->company_id);
        abort_unless($company && ! $company->isSuspended() && $company->hasModule('tresorerie'), 404);
        app()->instance('tenant', $company);
        $files = [];
        foreach ($r->allFiles() as $f) {
            foreach (is_array($f) ? $f : [$f] as $one) {
                if ($one->getSize() <= 15 * 1024 * 1024) {
                    $files[] = ['name' => $one->getClientOriginalName(), 'data' => (string) file_get_contents($one->getRealPath())];
                }
            }
        }
        // Postmark / Brevo : pièces jointes en base64 dans le JSON
        foreach ((array) ($r->input('Attachments') ?? $r->input('attachments') ?? []) as $a) {
            if (is_array($a) && ! empty($a['Content'] ?? $a['content'] ?? null)) {
                $files[] = ['name' => (string) ($a['Name'] ?? $a['name'] ?? 'releve'), 'data' => (string) base64_decode((string) ($a['Content'] ?? $a['content']))];
            }
        }
        if (! $files) {
            return response()->json(['message' => 'Aucune pièce jointe.'], 422);
        }

        return ['data' => $this->sync->inbound($c, $files, (string) ($r->input('subject') ?? $r->input('Subject') ?? ''))];
    }

    // ---------------------------------------------------------------- Règles
    protected function ruleRules(): array
    {
        return [
            'name' => 'required|string|max:120', 'contains' => 'required|string|max:255', 'direction' => 'nullable|in:in,out,any', 'account_id' => 'nullable|uuid',
            'amount_min' => 'nullable|numeric|min:0', 'amount_max' => 'nullable|numeric|min:0', 'action' => 'nullable|in:operation,ignore',
            'category' => ['nullable', Rule::in(array_keys(Operation::CATEGORIES))], 'label' => 'nullable|string|max:190', 'active' => 'nullable|boolean',
        ];
    }

    public function storeRule(Request $r)
    {
        $d = $r->validate($this->ruleRules());
        $id = DB::table('tr_bank_rules')->insertGetId($d + ['company_id' => app('tenant')->id, 'direction' => $d['direction'] ?? 'any', 'action' => $d['action'] ?? 'operation',
            'active' => $d['active'] ?? true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => DB::table('tr_bank_rules')->find($id), 'preview' => $this->preview($id)], 201);
    }

    public function updateRule(Request $r, int $id)
    {
        $d = $r->validate($this->ruleRules());
        abort_unless(DB::table('tr_bank_rules')->where('company_id', app('tenant')->id)->where('id', $id)->update($d + ['updated_at' => now()]) !== false, 404);

        return ['data' => DB::table('tr_bank_rules')->find($id), 'preview' => $this->preview($id)];
    }

    public function destroyRule(int $id)
    {
        DB::table('tr_bank_rules')->where('company_id', app('tenant')->id)->where('id', $id)->delete();

        return response()->noContent();
    }

    /** Nombre de lignes à traiter que la règle reconnaîtrait. */
    protected function preview(int $id): int
    {
        $rule = DB::table('tr_bank_rules')->where('company_id', app('tenant')->id)->find($id);
        if (! $rule) {
            return 0;
        }

        return StatementLine::where('status', 'open')->when($rule->account_id, fn ($q) => $q->where('account_id', $rule->account_id))->limit(3000)->get()
            ->filter(fn ($l) => BankRules::matches($rule, $l->label.' '.$l->counterparty, (float) $l->amount))->count();
    }

    /** Applique une règle (ou toutes) aux lignes à traiter de tous les comptes. */
    public function applyRules(Request $r)
    {
        $only = $r->integer('rule_id') ?: null;
        $n = 0;
        foreach (TreasuryAccount::all() as $acc) {
            $n += $this->rules->apply($acc, $only);
        }

        return ['applied' => $n];
    }
}
