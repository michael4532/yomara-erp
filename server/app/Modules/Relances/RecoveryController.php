<?php

namespace App\Modules\Relances;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Relances\Models\RecoveryCase;
use Illuminate\Http\Request;

/** Dossiers de recouvrement (contentieux). */
class RecoveryController extends Controller
{
    public function __construct(private RecoveryService $svc) {}

    protected function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function index(Request $r)
    {
        $status = $r->query('status', 'open');
        $rows = RecoveryCase::query()
            ->when($status === 'open', fn ($q) => $q->whereIn('status', RecoveryService::OPEN))
            ->when($status === 'closed', fn ($q) => $q->whereNotIn('status', RecoveryService::OPEN))
            ->when(! in_array($status, ['open', 'closed', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($r->query('assignee') === 'me', fn ($q) => $q->where('assigned_to', $r->user()->id))
            ->orderByRaw('CASE WHEN next_action_at IS NULL THEN 1 ELSE 0 END')->orderBy('next_action_at')->orderByDesc('id')->limit(400)->get();
        $out = $rows->map(function ($c) {
            if (in_array($c->status, RecoveryService::OPEN, true)) {
                $c = $this->svc->refresh($c);
            }

            return $this->svc->toApi($c);
        });
        if ($q = mb_strtolower((string) $r->query('q'))) {
            $out = $out->filter(fn ($x) => str_contains(mb_strtolower($x['partner'].' '.$x['number'].' '.$x['aux_code']), $q));
        }

        return ['data' => $out->values()];
    }

    public function show(int $id)
    {
        $c = RecoveryCase::findOrFail($id);

        return ['data' => $this->svc->toApi($this->svc->refresh($c), true)];
    }

    public function meta()
    {
        $c = app('tenant');

        return ['data' => ['statuses' => RecoveryService::STATUSES, 'actions' => RecoveryService::ACTIONS, 'settings' => $this->svc->settings($c),
            'users' => User::where('company_id', $c->id)->where('is_active', true)->orderBy('name')->get()->filter(fn ($u) => $u->hasPermission('relances.manage'))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'treasury' => $c->hasModule('tresorerie') ? \Illuminate\Support\Facades\DB::table('tr_accounts')->where('company_id', $c->id)->whereNull('deleted_at')->where('active', true)->orderBy('name')->get(['id', 'name', 'kind']) : []]];
    }

    public function candidates()
    {
        return ['data' => $this->svc->candidates(app('tenant'))];
    }

    public function dashboard(Request $r)
    {
        return ['data' => $this->svc->dashboard(app('tenant'), $r->query('from', now()->startOfYear()->toDateString()), $r->query('to', now()->toDateString()))];
    }

    public function store(Request $r)
    {
        $v = $r->validate(['partner_id' => 'required|uuid', 'invoice_ids' => 'nullable|array', 'invoice_ids.*' => 'uuid', 'assigned_to' => 'nullable|integer', 'note' => 'nullable|string|max:3000']);

        return $this->run(fn () => response()->json(['data' => $this->svc->toApi($this->svc->open(app('tenant'), $r->user(), $v['partner_id'], $v['invoice_ids'] ?? null, $v['assigned_to'] ?? null, $v['note'] ?? null), true)], 201));
    }

    public function act(Request $r, int $id, string $type)
    {
        $case = RecoveryCase::findOrFail($id);
        $v = $r->validate(['body' => 'nullable|string|max:5000', 'date' => 'nullable|date', 'amount' => 'nullable|numeric|min:0', 'promise_date' => 'nullable|date',
            'method' => 'nullable|string|max:20', 'reference' => 'nullable|string|max:100', 'treasury_account_id' => 'nullable|uuid', 'count' => 'nullable|integer',
            'first_date' => 'nullable|date', 'every' => 'nullable|in:week,month', 'days' => 'nullable|integer|min:1|max:90', 'send' => 'nullable|boolean',
            'bailiff' => 'nullable|string|max:160', 'outcome' => 'nullable|string|max:60', 'next_action_at' => 'nullable|date']);

        return $this->run(fn () => ['data' => $this->svc->toApi($this->svc->act(app('tenant'), $r->user(), $case, $type, $v + ['send' => $r->boolean('send')]), true)]);
    }

    public function close(Request $r, int $id)
    {
        $v = $r->validate(['note' => 'required|string|max:3000']);

        return $this->run(fn () => ['data' => $this->svc->toApi($this->svc->close(app('tenant'), $r->user(), RecoveryCase::findOrFail($id), $v['note']), true)]);
    }

    public function assign(Request $r, int $id)
    {
        $v = $r->validate(['user_id' => 'required|integer']);

        return $this->run(fn () => ['data' => $this->svc->toApi($this->svc->assign(app('tenant'), $r->user(), RecoveryCase::findOrFail($id), $v['user_id']), true)]);
    }

    public function notice(Request $r, int $id)
    {
        $case = RecoveryCase::findOrFail($id);

        return response($this->svc->noticePdf(app('tenant'), $case, $r->integer('days') ?: null), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="mise-en-demeure-'.$case->number.'.pdf"']);
    }

    public function saveSettings(Request $r)
    {
        $v = $r->validate(['min_days' => 'nullable|integer|min:1|max:730', 'notice_days' => 'nullable|integer|min:1|max:90', 'notice_text' => 'nullable|string|max:5000']);

        return ['data' => $this->svc->saveSettings(app('tenant'), array_filter($v, fn ($x) => $x !== null))];
    }
}
