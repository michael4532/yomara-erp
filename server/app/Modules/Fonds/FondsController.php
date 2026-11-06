<?php

namespace App\Modules\Fonds;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Fonds\Models\FundRequest;
use Illuminate\Http\Request;

class FondsController extends Controller
{
    public function __construct(private FundService $svc) {}

    protected function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function find(Request $r, int $id): FundRequest
    {
        return $this->svc->visible($r->user())->findOrFail($id);
    }

    public function index(Request $r)
    {
        $u = $r->user();
        $q = $this->svc->visible($u)->with([])
            ->when($r->query('status'), fn ($w, $s) => $s === 'open' ? $w->whereIn('status', ['submitted', 'question', 'approved', 'partial', 'provided']) : $w->where('status', $s))
            ->when($r->query('box') === 'mine', fn ($w) => $w->where('requester_id', $u->id))
            ->when($r->query('box') === 'decide', fn ($w) => $w->where('approver_id', $u->id)->whereIn('status', ['submitted', 'question']))
            ->when($r->query('box') === 'provide', fn ($w) => $w->whereIn('status', ['approved', 'partial'])->where(fn ($x) => $x->where('provider_id', $u->id)->orWhereNull('provider_id')))
            ->when($r->query('department'), fn ($w, $d) => $w->where('department', $d))
            ->when($r->query('q'), fn ($w, $s) => $w->where(fn ($x) => $x->where('title', 'like', "%$s%")->orWhere('number', 'like', "%$s%")->orWhere('department', 'like', "%$s%")))
            ->orderByRaw("CASE WHEN status IN ('submitted','question','approved','partial','provided') THEN 0 ELSE 1 END")->orderByDesc('id')->limit(400)->get();

        return ['data' => $q->map(fn ($x) => $this->svc->toApi($x, $u))->values()];
    }

    public function meta(Request $r)
    {
        $c = app('tenant');
        $users = User::where('company_id', $c->id)->where('is_active', true)->orderBy('name')->get();

        return ['data' => [
            'statuses' => FundService::STATUSES, 'types' => FundService::TYPES, 'methods' => FundService::METHODS, 'settings' => $this->svc->settings($c),
            'approvers' => $users->filter(fn ($u) => $u->hasPermission('fonds.approve') || $u->hasPermission('fonds.manage'))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'providers' => $users->filter(fn ($u) => $u->hasPermission('fonds.provide') || $u->hasPermission('fonds.manage'))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'treasury' => $c->hasModule('tresorerie') ? \Illuminate\Support\Facades\DB::table('tr_accounts')->where('company_id', $c->id)->whereNull('deleted_at')->where('active', true)->orderBy('name')->get(['id', 'name', 'kind']) : [],
        ]];
    }

    public function show(Request $r, int $id)
    {
        return ['data' => $this->svc->toApi($this->find($r, $id), $r->user(), true)];
    }

    public function store(Request $r)
    {
        $d = $this->validated($r, true);

        return $this->run(fn () => response()->json(['data' => $this->svc->toApi($this->svc->save(app('tenant'), $r->user(), null, $d), $r->user(), true)], 201));
    }

    public function update(Request $r, int $id)
    {
        $d = $this->validated($r, false);

        return $this->run(fn () => ['data' => $this->svc->toApi($this->svc->save(app('tenant'), $r->user(), $this->find($r, $id), $d), $r->user(), true)]);
    }

    protected function validated(Request $r, bool $creating): array
    {
        return $r->validate([
            'title' => ($creating ? 'required' : 'sometimes').'|string|max:200', 'department' => 'nullable|string|max:120', 'to_department' => 'nullable|string|max:120',
            'approver_id' => ['nullable', 'integer', \App\Support\TenantRef::of('users')], 'priority' => 'nullable|in:normal,urgent,very_urgent', 'needed_by' => 'nullable|date', 'purpose' => 'nullable|string|max:5000', 'project_id' => 'nullable|string|max:40',
            'lines' => ($creating ? 'required' : 'sometimes').'|array|max:100', 'lines.*.id' => 'nullable|integer', 'lines.*.type' => 'nullable|in:funds,equipment,service',
            'lines.*.description' => 'nullable|string|max:255', 'lines.*.quantity' => 'nullable|numeric|min:0', 'lines.*.unit_amount' => 'nullable|numeric|min:0',
            'lines.*.note' => 'nullable|string|max:255', 'lines.*.product_id' => 'nullable|string|max:40',
            'motive' => 'nullable|string|max:60', 'motive_other' => 'nullable|string|max:120', 'requester_function' => 'nullable|string|max:120', 'project_label' => 'nullable|string|max:160',
            'beneficiary' => 'nullable|string|max:160', 'beneficiary_structure' => 'nullable|string|max:160', 'payment_method' => 'nullable|string|max:60', 'payment_other' => 'nullable|string|max:120',
            'attachments' => 'nullable|array|max:20', 'attachments.*' => 'string|max:60', 'attachments_other' => 'nullable|string|max:120',
            'discount_mode' => 'nullable|in:percent,amount', 'discount_value' => 'nullable|numeric|min:0', 'remarks' => 'nullable|string|max:5000',
            'taxes' => 'nullable|array|max:10', 'taxes.*.label' => 'nullable|string|max:60', 'taxes.*.rate' => 'nullable|numeric|min:0|max:100', 'taxes.*.base' => 'nullable|in:net,gross',
        ]);
    }

    public function action(Request $r, int $id, string $action)
    {
        $c = app('tenant');
        $u = $r->user();
        $req = $this->find($r, $id);
        $v = $r->validate(['budget_available' => 'nullable|boolean', 'documents_compliant' => 'nullable|boolean', 'accounting_note' => 'nullable|string|max:2000',
            'body' => 'nullable|string|max:5000', 'note' => 'nullable|string|max:5000', 'lines' => 'nullable|array', 'items' => 'nullable|array',
            'provider_id' => 'nullable|integer', 'provider_department' => 'nullable|string|max:120', 'method' => 'nullable|string|max:20', 'date' => 'nullable|date',
            'reference' => 'nullable|string|max:100', 'source_account_id' => 'nullable|string|max:40', 'dest_account_id' => 'nullable|string|max:40']);

        return $this->run(fn () => ['data' => $this->svc->toApi(match ($action) {
            'submit' => $this->svc->submit($c, $u, $req),
            'question' => $this->svc->question($c, $u, $req, (string) ($v['body'] ?? '')),
            'answer' => $this->svc->answer($c, $u, $req, (string) ($v['body'] ?? '')),
            'approve' => $this->svc->approve($c, $u, $req, (array) ($v['lines'] ?? []), $v['provider_id'] ?? null, $v['provider_department'] ?? null, $v['note'] ?? null),
            'reject' => $this->svc->reject($c, $u, $req, (string) ($v['note'] ?? '')),
            'provide' => $this->svc->provide($c, $u, $req, (array) ($v['items'] ?? []), $v),
            'confirm' => $this->svc->confirm($c, $u, $req, $v['note'] ?? null),
            'cancel' => $this->svc->cancel($c, $u, $req, $v['note'] ?? null),
            'comment' => $this->svc->comment($c, $u, $req, (string) ($v['body'] ?? '')),
            'accounting' => $this->svc->accounting($c, $u, $req, array_intersect_key($r->all(), array_flip(['budget_available', 'documents_compliant', 'accounting_note']))),
        }, $u, true)]);
    }

    public function pdf(Request $r, int $id)
    {
        $req = $this->find($r, $id);

        return response($this->svc->pdf(app('tenant'), $req), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="appel-de-fonds-'.($req->number ?? $req->id).'.pdf"']);
    }

    public function dashboard(Request $r)
    {
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = $r->query('from', now()->startOfYear()->toDateString());
        $to = $r->query('to', now()->toDateString());

        return ['data' => $this->svc->dashboard(app('tenant'), $r->user(), $from, $to)];
    }

    public function saveSettings(Request $r)
    {
        $v = $r->validate(['approvers' => 'nullable|array', 'providers' => 'nullable|array', 'default_approver' => 'nullable|integer', 'remind_days' => 'nullable|integer|min:1|max:60',
            'email' => 'nullable|boolean', 'departments' => 'nullable|array|max:100', 'departments.*' => 'string|max:120']);

        return ['data' => $this->svc->saveSettings(app('tenant'), $v)];
    }

    /** Modèle du formulaire (logo, couleurs, titre, numérotation, listes, visas, pied de page, taux). */
    public function saveForm(Request $r)
    {
        return ['data' => FundForm::save(app('tenant'), $r->validate(FundForm::rules()))];
    }

    public function uploadLogo(Request $r)
    {
        $r->validate(['logo' => 'required|file|mimes:png,jpg,jpeg|max:2048']);

        return ['data' => FundForm::saveLogo(app('tenant'), $r->file('logo'))];
    }

    public function deleteLogo()
    {
        return ['data' => FundForm::deleteLogo(app('tenant'))];
    }

    /** Aperçu PDF du modèle (données d'exemple) pour régler la mise en page. */
    public function preview(Request $r)
    {
        return response(FundForm::preview(app('tenant'), $r->user(), $this->svc), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="apercu-appel-de-fonds.pdf"']);
    }
}
