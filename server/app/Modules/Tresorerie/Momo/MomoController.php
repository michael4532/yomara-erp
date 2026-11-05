<?php

namespace App\Modules\Tresorerie\Momo;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Tresorerie\Models\Operation;
use App\Modules\Tresorerie\Models\TreasuryAccount;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Http\Request;

class MomoController extends Controller
{
    public function __construct(private MomoService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Webhook public : SMS transféré depuis le téléphone marchand. */
    public function hook(Request $r, string $token)
    {
        abort_unless(strlen($token) >= 32, 404);
        $a = TreasuryAccount::withoutGlobalScopes()->where('momo_token', $token)->whereNull('deleted_at')->first();
        abort_unless($a && $a->kind === 'mobile_money', 404);
        $c = Company::find($a->company_id);
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('tresorerie'), 404);
        app()->instance('tenant', $c);
        $payload = $r->all() ?: (array) json_decode((string) $r->getContent(), true);
        [$text, $from, $at] = MomoService::fromPayload($payload);
        if (! $text && is_string($r->getContent()) && $r->getContent() !== '' && ! $payload) {
            $text = $r->getContent();
        }
        if (! $text || mb_strlen(trim($text)) < 10) {
            return response()->json(['message' => 'SMS vide.'], 422);
        }
        $m = $this->svc->receive($c, TreasuryAccount::find($a->id), $text, $from, $at);

        return response()->json(['status' => $m->status, 'amount' => $m->amount, 'document' => $m->document_id ? SalesDocument::find($m->document_id)?->number : null]);
    }

    public function index(Request $r)
    {
        $q = MomoMessage::query()->orderByDesc('received_at')->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->when($r->query('account_id'), fn ($q, $a) => $q->where('account_id', $a));
        $rows = $q->limit(200)->get();

        return ['data' => $rows->map(fn ($m) => $this->svc->toArray($m, true))->values(),
            'counts' => MomoMessage::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
            'accounts' => TreasuryAccount::where('kind', 'mobile_money')->orderBy('name')->get()->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'url' => $this->svc->url($a)])];
    }

    public function token(Request $r, string $account)
    {
        $a = TreasuryAccount::findOrFail($account);
        if ($a->kind !== 'mobile_money') {
            return response()->json(['message' => 'Réservé aux comptes Mobile Money.'], 422);
        }
        $this->svc->token($a, $r->boolean('renew'));

        return ['data' => ['url' => $this->svc->url($a->fresh())]];
    }

    /** Essai : lecture d'un SMS collé (rien n'est enregistré). */
    public function test(Request $r)
    {
        $v = $r->validate(['text' => 'required|string|max:2000', 'sender' => 'nullable|string|max:60']);

        return ['data' => MomoParser::parse($v['text'], $v['sender'] ?? null)];
    }

    /** Saisie manuelle d'un SMS (copié depuis le téléphone). */
    public function store(Request $r)
    {
        $v = $r->validate(['account_id' => 'required|uuid', 'text' => 'required|string|min:10|max:2000', 'sender' => 'nullable|string|max:60']);
        $a = TreasuryAccount::findOrFail($v['account_id']);
        abort_unless($a->kind === 'mobile_money', 422, 'Réservé aux comptes Mobile Money.');

        return response()->json(['data' => $this->svc->toArray($this->svc->receive(app('tenant'), $a, $v['text'], $v['sender'] ?? null), true)], 201);
    }

    public function assign(Request $r, string $id)
    {
        $v = $r->validate(['document_id' => 'required|uuid']);
        $m = MomoMessage::findOrFail($id);
        $d = SalesDocument::findOrFail($v['document_id']);

        return $this->guard(function () use ($m, $d, $r) {
            $this->svc->assign($m, $d, 'Rapproché à la main', $r->user()->name);

            return ['data' => $this->svc->toArray($m->fresh())];
        });
    }

    public function operation(Request $r, string $id)
    {
        $v = $r->validate(['category' => 'required|in:'.implode(',', array_keys(Operation::CATEGORIES)), 'label' => 'nullable|string|max:250']);
        $m = MomoMessage::findOrFail($id);

        return $this->guard(function () use ($m, $v, $r) {
            $this->svc->toOperation($m, $v['category'], $v['label'] ?? null, $r->user()->name);

            return ['data' => $this->svc->toArray($m->fresh())];
        });
    }

    public function ignore(Request $r, string $id)
    {
        $m = MomoMessage::findOrFail($id);
        if ($m->status === 'matched') {
            return response()->json(['message' => 'Déjà rapproché.'], 422);
        }
        $m->forceFill(['status' => $r->boolean('restore') ? 'pending' : 'ignored', 'handled_by' => $r->user()->name])->save();

        return ['data' => $this->svc->toArray($m, true)];
    }
}
