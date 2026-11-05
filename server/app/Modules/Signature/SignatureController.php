<?php

namespace App\Modules\Signature;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Signature\Models\SignEvent;
use App\Modules\Signature\Models\Signer;
use App\Modules\Signature\Models\SignRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SignatureController extends Controller
{
    public function __construct(protected SignatureService $svc) {}

    protected function canView(Request $r): bool
    {
        return $r->user()->hasPermission('signature.view|signature.manage');
    }

    /** Demande visible : gestionnaires, ou signataire interne concerné. */
    protected function visible(Request $r, string $id): SignRequest
    {
        $q = SignRequest::findOrFail($id);
        abort_unless($this->canView($r) || Signer::where('request_id', $q->id)->where('user_id', $r->user()->id)->exists(), 404);
        $this->svc->expireIfNeeded($q);

        return $q;
    }

    protected function present(SignRequest $q, Request $r, bool $full = false): array
    {
        $manage = $r->user()->hasPermission('signature.manage');
        $signers = $q->relationLoaded('signers') ? $q->signers : $q->signers()->get();
        $out = $q->toArray() + [
            'has_signed_file' => (bool) $q->signed_path,
            'signers' => $signers->map(fn ($s) => $s->only(['id', 'position', 'name', 'label', 'email', 'phone', 'user_id', 'status', 'verified_by', 'notified_at', 'viewed_at', 'signed_at', 'declined_at', 'decline_reason'])
                + ['link' => $manage && ! $s->user_id && in_array($s->status, ['pending', 'viewed'], true) && $q->status === 'pending' ? $s->link() : null,
                    'mine' => $s->user_id === $r->user()->id])->values(),
        ];
        if ($full) {
            $names = $signers->keyBy('id');
            $users = User::where('company_id', $q->company_id)->pluck('name', 'id');
            $out['events'] = $q->events()->get()->map(fn ($e) => ['type' => $e->type, 'label' => SignEvent::LABELS[$e->type] ?? $e->type, 'at' => $e->created_at, 'ip' => $e->ip, 'data' => $e->data,
                'who' => $e->signer_id ? ($names[$e->signer_id]->name ?? null) : ($e->user_id ? ($users[$e->user_id] ?? null) : null)]);
            $out['created_by_name'] = $q->created_by ? ($users[$q->created_by] ?? null) : null;
        }

        return $out;
    }

    public function index(Request $r)
    {
        $q = SignRequest::query()->with('signers')->orderByDesc('created_at');
        if (! $this->canView($r)) {
            $q->whereIn('id', Signer::where('user_id', $r->user()->id)->select('request_id'));
        }
        if ($r->filled('status')) {
            $q->whereIn('status', explode(',', $r->query('status')));
        }
        if ($r->filled('source_id')) {
            $q->where('source_id', $r->query('source_id'));
        }
        $rows = $q->limit(300)->get();
        $rows->each(fn ($x) => $this->svc->expireIfNeeded($x));

        return ['data' => $rows->map(fn ($x) => $this->present($x, $r))->values()];
    }

    /** Documents que l'utilisateur connecté doit signer maintenant. */
    public function mine(Request $r)
    {
        $ids = Signer::where('user_id', $r->user()->id)->whereIn('status', ['pending', 'viewed'])->pluck('request_id');
        $rows = SignRequest::with('signers')->whereIn('id', $ids)->where('status', 'pending')->orderBy('sent_at')->get();

        return ['data' => $rows->map(fn ($x) => $this->present($x, $r))->values()];
    }

    public function show(Request $r, string $id)
    {
        return ['data' => $this->present($this->visible($r, $id), $r, true)];
    }

    public function store(Request $r)
    {
        $v = $r->validate([
            'file' => 'required|file|max:15360', 'title' => 'nullable|string|max:200', 'message' => 'nullable|string|max:2000',
            'sequential' => 'nullable|boolean', 'expires_days' => 'nullable|integer|min:1|max:365', 'send' => 'nullable|boolean',
            'source_type' => 'nullable|in:rh_document,sales_document,mat_checkout', 'source_id' => 'nullable|uuid', 'signers' => 'required|string',
        ], ['file.max' => 'PDF trop lourd (15 Mo maximum).']);
        $signers = json_decode($v['signers'], true);
        if (! is_array($signers)) {
            return response()->json(['message' => 'Liste des signataires invalide.'], 422);
        }
        try {
            $q = $this->svc->create(app('tenant'), $r->user(), $r->file('file')->get(), $r->file('file')->getClientOriginalName(), $v, $signers);
            $sent = ! empty($v['send']) ? $this->svc->send(app('tenant'), $q) : null;
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($q->fresh(), $r, true), 'delivery' => $sent], 201);
    }

    public function send(Request $r, string $id)
    {
        $q = SignRequest::findOrFail($id);
        try {
            $sent = $this->svc->send(app('tenant'), $q);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => $this->present($q->fresh(), $r, true), 'delivery' => $sent];
    }

    public function remind(Request $r, string $id)
    {
        $q = SignRequest::findOrFail($id);
        $only = $r->filled('signer_id') ? Signer::where('request_id', $q->id)->findOrFail($r->input('signer_id')) : null;
        try {
            $n = $this->svc->remind(app('tenant'), $q, $only);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => $this->present($q->fresh(), $r, true), 'reminded' => $n];
    }

    public function cancel(Request $r, string $id)
    {
        $q = SignRequest::findOrFail($id);
        try {
            $this->svc->cancel($q, $r->input('reason'));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => $this->present($q->fresh(), $r, true)];
    }

    public function destroy(string $id)
    {
        $q = SignRequest::findOrFail($id);
        if ($q->status !== 'draft') {
            return response()->json(['message' => 'Seul un brouillon peut être supprimé ; annulez la demande à la place.'], 422);
        }
        Storage::disk('local')->deleteDirectory('signatures/'.$q->company_id.'/'.$q->id);
        Signer::where('request_id', $q->id)->delete();
        SignEvent::where('request_id', $q->id)->delete();
        $q->delete();

        return ['ok' => true];
    }

    public function file(Request $r, string $id, string $kind)
    {
        $q = $this->visible($r, $id);
        $path = $kind === 'signed' ? $q->signed_path : $q->original_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Document signé pas encore disponible.');
        $name = pathinfo($q->original_name, PATHINFO_FILENAME).($kind === 'signed' ? ' - signé' : '').'.pdf';

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $name).'"']);
    }

    /** Signature par un signataire interne, depuis l'application. */
    public function sign(Request $r, string $id)
    {
        $q = $this->visible($r, $id);
        $v = $r->validate(['signature' => 'required|string', 'agree' => 'accepted'], ['agree.accepted' => 'Confirmez avoir lu le document.', 'signature.required' => 'Tracez votre signature.']);
        $s = Signer::where('request_id', $q->id)->where('user_id', $r->user()->id)->whereNotIn('status', ['signed', 'declined'])->first();
        abort_unless($s, 403, 'Vous n\'êtes pas signataire de ce document.');
        try {
            $q = $this->svc->sign(app('tenant'), $q, $s, $v['signature'], $r->ip(), $r->userAgent(), true);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => $this->present($q, $r, true)];
    }

    public function decline(Request $r, string $id)
    {
        $q = $this->visible($r, $id);
        $s = Signer::where('request_id', $q->id)->where('user_id', $r->user()->id)->whereNotIn('status', ['signed', 'declined'])->first();
        abort_unless($s, 403, 'Vous n\'êtes pas signataire de ce document.');
        try {
            $this->svc->decline(app('tenant'), $q, $s, $r->input('reason'), $r->ip(), $r->userAgent(), true);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => $this->present($q->fresh(), $r, true)];
    }

    public function users()
    {
        return ['data' => User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])];
    }

    public function settings()
    {
        return ['data' => $this->svc->settings(app('tenant')) + ['verify_url' => rtrim(config('app.url'), '/').'/signature/verifier']];
    }

    public function updateSettings(Request $r)
    {
        $v = $r->validate(['otp' => 'boolean', 'reminder_days' => 'integer|min:0|max:30', 'max_reminders' => 'integer|min:0|max:10', 'expiry_days' => 'integer|min:1|max:365']);
        $c = app('tenant');
        $s = $c->settings ?? [];
        $s['signature'] = array_merge($this->svc->settings($c), $v);
        $c->forceFill(['settings' => $s])->save();

        return $this->settings();
    }
}
