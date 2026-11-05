<?php

namespace App\Modules\Social;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Boîte de réception des réseaux : conversations, réponses, attribution, prospects, réponses automatiques. */
class InboxController extends Controller
{
    public function __construct(protected Inbox $inbox) {}

    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function conv(int $id): object
    {
        return DB::table('so_conversations')->where('company_id', app('tenant')->id)->where('id', $id)->first() ?? abort(404, 'Conversation introuvable.');
    }

    protected function row(object $c, array $accounts): array
    {
        $a = $accounts[$c->account_id] ?? null;

        return ['id' => $c->id, 'network' => $c->network, 'network_label' => Networks::label($c->network), 'kind' => $c->kind, 'account' => $a?->name, 'author' => $c->author_name ?: 'Inconnu',
            'post_text' => $c->post_text, 'last_text' => $c->last_text, 'last_at' => $c->last_at, 'status' => $c->status, 'unread' => (int) $c->unread, 'assigned_to' => $c->assigned_to,
            'assigned_name' => $c->assigned_to ? DB::table('users')->where('id', $c->assigned_to)->value('name') : null, 'lead_id' => $c->lead_id];
    }

    public function index(Request $r)
    {
        $cid = app('tenant')->id;
        $q = DB::table('so_conversations')->where('company_id', $cid)
            ->when($r->query('status', 'open') !== 'all', fn ($w) => $w->where('status', $r->query('status', 'open')))
            ->when($r->query('kind'), fn ($w, $k) => $w->where('kind', $k))
            ->when($r->query('network'), fn ($w, $n) => $w->where('network', $n))
            ->when($r->query('mine'), fn ($w) => $w->where('assigned_to', $r->user()->id))
            ->when(trim((string) $r->query('q')), fn ($w, $s) => $w->where(fn ($x) => $x->where('author_name', 'like', '%'.$s.'%')->orWhere('last_text', 'like', '%'.$s.'%')));
        $accounts = DB::table('so_accounts')->where('company_id', $cid)->get()->keyBy('id')->all();

        return ['data' => $q->orderByDesc('last_at')->limit(200)->get()->map(fn ($c) => $this->row($c, $accounts))->values(),
            'counts' => ['open' => DB::table('so_conversations')->where('company_id', $cid)->where('status', 'open')->count(), 'unread' => (int) DB::table('so_conversations')->where('company_id', $cid)->sum('unread')],
            'accounts' => DB::table('so_accounts')->where('company_id', $cid)->whereIn('network', Inbox::NETWORKS)->get(['id', 'network', 'name', 'inbox_enabled', 'inbox_polled_at'])
                ->map(fn ($a) => ['id' => $a->id, 'network' => $a->network, 'name' => $a->name, 'enabled' => (bool) $a->inbox_enabled, 'polled_at' => $a->inbox_polled_at])->values(),
            'extended' => SocialOAuth::metaExtended()];
    }

    public function show(int $id)
    {
        $c = $this->conv($id);
        DB::table('so_conversations')->where('id', $id)->update(['unread' => 0]);
        $accounts = DB::table('so_accounts')->where('company_id', app('tenant')->id)->get()->keyBy('id')->all();

        return ['data' => array_merge($this->row($c, $accounts), ['unread' => 0]) + ['messages' => DB::table('so_messages')->where('conversation_id', $id)->orderBy('sent_at')->orderBy('id')->limit(300)->get()
            ->map(fn ($m) => ['id' => $m->id, 'direction' => $m->direction, 'author' => $m->author_name, 'text' => $m->text, 'hidden' => (bool) $m->hidden, 'private' => (bool) $m->private, 'at' => $m->sent_at])->values()]];
    }

    public function reply(Request $r, int $id)
    {
        $v = $r->validate(['text' => 'required|string|max:2000', 'private' => 'nullable|boolean']);
        $c = $this->conv($id);

        return $this->guard(function () use ($r, $c, $v, $id) {
            if (! empty($v['private']) && $c->kind === 'comment') {
                $last = DB::table('so_messages')->where('conversation_id', $c->id)->where('direction', 'in')->whereNotNull('external_id')->orderByDesc('sent_at')->value('external_id') ?? $c->thread_ref;
                $acc = DB::table('so_accounts')->find($c->account_id);
                $this->inbox->privateReply($acc, $c, $last, $v['text'], $r->user());
            } else {
                $this->inbox->reply($c, $v['text'], $r->user());
            }
            DB::table('so_conversations')->where('id', $id)->update(['assigned_to' => $c->assigned_to ?? $r->user()->id]);

            return $this->show($id);
        });
    }

    public function update(Request $r, int $id)
    {
        $v = $r->validate(['status' => 'nullable|in:open,done', 'assigned_to' => 'nullable|integer']);
        $this->conv($id);
        if (array_key_exists('assigned_to', $v) && $v['assigned_to']) {
            abort_unless(DB::table('users')->where('company_id', app('tenant')->id)->where('id', $v['assigned_to'])->exists(), 422, 'Utilisateur introuvable.');
        }
        DB::table('so_conversations')->where('id', $id)->update(array_intersect_key($v, array_flip(['status', 'assigned_to'])) + ['updated_at' => now()]);

        return $this->show($id);
    }

    public function hide(Request $r, int $id, int $message)
    {
        $v = $r->validate(['hide' => 'nullable|boolean']);

        return $this->guard(function () use ($id, $message, $v) {
            $this->inbox->hide($this->conv($id), $message, $v['hide'] ?? true);

            return $this->show($id);
        });
    }

    public function toLead(Request $r, int $id)
    {
        return $this->guard(fn () => ['data' => ['lead_id' => $this->inbox->toLead(app('tenant'), $this->conv($id), $r->user())]]);
    }

    public function enable(int $account)
    {
        $acc = DB::table('so_accounts')->where('company_id', app('tenant')->id)->whereIn('network', Inbox::NETWORKS)->where('id', $account)->first() ?? abort(404);

        return $this->guard(fn () => ['data' => $this->inbox->enable($acc)]);
    }

    public function disable(int $account)
    {
        DB::table('so_accounts')->where('company_id', app('tenant')->id)->where('id', $account)->update(['inbox_enabled' => false]);

        return ['data' => ['ok' => true]];
    }

    public function refresh()
    {
        DB::table('so_accounts')->where('company_id', app('tenant')->id)->where('inbox_enabled', true)->update(['inbox_polled_at' => now()->subHour()]);

        return $this->guard(fn () => ['data' => ['new' => $this->inbox->poll(app('tenant'))]]);
    }

    public function rules()
    {
        return ['data' => array_values((array) app('tenant')->setting('social_inbox_rules', []))];
    }

    public function saveRules(Request $r)
    {
        $v = $r->validate(['rules' => 'present|array|max:30', 'rules.*.keywords' => 'required|string|max:300', 'rules.*.public' => 'nullable|string|max:1000', 'rules.*.private' => 'nullable|string|max:1000']);
        foreach ($v['rules'] as $i => $rule) {
            abort_if(empty($rule['public']) && empty($rule['private']), 422, 'Règle '.($i + 1).' : écrivez la réponse publique ou le message privé.');
        }
        $c = app('tenant');
        $s = (array) $c->settings;
        $s['social_inbox_rules'] = array_values(array_map(fn ($x) => ['keywords' => trim($x['keywords']), 'public' => $x['public'] ?? null, 'private' => $x['private'] ?? null], $v['rules']));
        $c->settings = $s;
        $c->save();

        return $this->rules();
    }
}
