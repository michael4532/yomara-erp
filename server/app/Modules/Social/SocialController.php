<?php

namespace App\Modules\Social;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocialController extends Controller
{
    public function __construct(protected SocialService $s) {}

    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function overview(Request $r)
    {
        $c = app('tenant');

        return [
            'networks' => Networks::catalog(), 'accounts' => $this->s->accounts($c), 'statuses' => SocialService::STATUSES,
            'ai' => (bool) rescue(fn () => ($a = app(\App\Modules\Ia\AiService::class)->settings($c)) && $a['enabled'] && $a['configured'], false, false),
            'can' => ['manage' => $r->user()->hasPermission('social.manage'), 'publish' => $r->user()->hasPermission('social.publish'), 'accounts' => $r->user()->hasPermission('social.accounts'),
                'apps' => (bool) $r->user()->is_super_admin, 'inbox' => $r->user()->hasPermission('social.inbox') || $r->user()->hasPermission('social.manage') || $r->user()->hasPermission('social.publish')],
            'inbox_unread' => (int) DB::table('so_conversations')->where('company_id', $c->id)->where('status', 'open')->sum('unread'),
            'counts' => DB::table('so_posts')->where('company_id', $c->id)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->all() ?: (object) [],
        ];
    }

    // ------------------------------------------------------------------ comptes

    public function connect(Request $r, SocialOAuth $o, string $provider)
    {
        return $this->guard(fn () => ['url' => $o->authorizeUrl(app('tenant'), $r->user(), $provider)]);
    }

    public function connectToken(Request $r, Publisher $p, string $network)
    {
        $d = $r->validate(['token' => 'nullable|string|max:500', 'chat' => 'nullable|string|max:120', 'instance' => 'nullable|string|max:200',
            'handle' => 'nullable|string|max:200', 'password' => 'nullable|string|max:60', 'base_url' => 'nullable|string|max:200']);

        return $this->guard(fn () => ['connected' => $p->connectToken(app('tenant'), $r->user(), $network, $d), 'accounts' => $this->s->accounts(app('tenant'))]);
    }

    public function updateAccount(Request $r, int $id)
    {
        $d = $r->validate(['board_id' => 'nullable|string|max:60', 'enabled' => 'nullable|boolean', 'limit' => 'nullable|integer|min:50|max:100000']);
        $a = DB::table('so_accounts')->where('company_id', app('tenant')->id)->where('id', $id)->first() ?? abort(404);
        $meta = json_decode($a->meta ?? '{}', true) ?: [];
        if (array_key_exists('board_id', $d) && $a->network === 'pinterest') {
            in_array($d['board_id'], array_column($meta['boards'] ?? [], 'id'), true) || abort(422, 'Tableau inconnu.');
            $meta['board_id'] = $d['board_id'];
        }
        if (! empty($d['limit']) && $a->network === 'sensya') {
            $meta['limit'] = (int) $d['limit'];
        }
        $set = ['meta' => json_encode($meta), 'updated_at' => now()];
        if (array_key_exists('enabled', $d)) {
            $set['status'] = $d['enabled'] ? 'active' : 'disabled';
        }
        DB::table('so_accounts')->where('id', $id)->update($set);

        return ['accounts' => $this->s->accounts(app('tenant'))];
    }

    public function disconnect(int $id)
    {
        $c = app('tenant');
        $busy = DB::table('so_targets as t')->join('so_posts as p', 'p.id', '=', 't.post_id')->where('t.account_id', $id)->where('t.company_id', $c->id)->where('p.status', 'publishing')->exists();
        abort_if($busy, 422, 'Une publication est en cours sur ce compte.');
        // Les publications à venir perdent ce compte ; l'historique publié est conservé avec le compte supprimé
        DB::table('so_targets')->where('company_id', $c->id)->where('account_id', $id)->where('status', '!=', 'published')->delete();
        DB::table('so_accounts')->where('company_id', $c->id)->where('id', $id)->delete();

        return ['accounts' => $this->s->accounts($c)];
    }

    // ------------------------------------------------------------------ médias

    public function media(Request $r)
    {
        return DB::table('so_media')->where('company_id', app('tenant')->id)->orderByDesc('id')->limit(200)->get()->map(fn ($m) => $this->s->mediaRow($m));
    }

    public function upload(Request $r)
    {
        $r->validate(['file' => 'required|file|max:204800']);

        return $this->guard(fn () => $this->s->upload(app('tenant'), $r->user(), $r->file('file')));
    }

    public function deleteMedia(int $id)
    {
        return $this->guard(function () use ($id) {
            $this->s->deleteMedia(app('tenant'), $id);

            return ['ok' => true];
        });
    }

    // ------------------------------------------------------------------ publications

    public function index(Request $r)
    {
        return ['data' => $this->s->list(app('tenant'), $r->only(['status', 'from', 'to', 'q', 'network', 'order', 'limit']))];
    }

    public function show(int $id)
    {
        return $this->guard(fn () => $this->s->show(app('tenant'), $id));
    }

    protected function rules(): array
    {
        return [
            'action' => 'required|in:draft,schedule,now', 'body' => 'nullable|string|max:65000', 'title' => 'nullable|string|max:190',
            'link_url' => ['nullable', 'string', 'max:1000', 'regex:~^https?://~i'], 'media' => 'nullable|array|max:20', 'media.*' => 'integer',
            'accounts' => 'nullable|array|max:60', 'accounts.*' => 'integer', 'variants' => 'nullable|array', 'variants.*.text' => 'nullable|string|max:65000',
            'variants.*.title' => 'nullable|string|max:190', 'scheduled_at' => 'nullable|date', 'campaign' => 'nullable|string|max:120',
            'options' => 'nullable|array', 'options.first_comment' => 'nullable|string|max:2200', 'options.tiktok_privacy' => 'nullable|string|max:40',
            'options.youtube_privacy' => 'nullable|in:public,unlisted,private', 'options.board_id' => 'nullable|string|max:60',
        ];
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->rules());

        return $this->guard(fn () => response()->json($this->s->save(app('tenant'), $r->user(), $d), 201));
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate($this->rules());

        return $this->guard(fn () => $this->s->save(app('tenant'), $r->user(), $d, $id));
    }

    public function approve(Request $r, int $id)
    {
        return $this->guard(fn () => $this->s->approve(app('tenant'), $r->user(), $id));
    }

    public function reject(Request $r, int $id)
    {
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return $this->guard(fn () => $this->s->reject(app('tenant'), $r->user(), $id, $d['reason']));
    }

    public function publishNow(int $id)
    {
        return $this->guard(function () use ($id) {
            $c = app('tenant');
            DB::table('so_posts')->where('company_id', $c->id)->where('id', $id)->where('status', 'scheduled')->update(['scheduled_at' => now()]);

            return $this->s->publish($c, $id);
        });
    }

    public function retry(int $id)
    {
        return $this->guard(function () use ($id) {
            $c = app('tenant');
            DB::table('so_targets')->where('company_id', $c->id)->where('post_id', $id)->where('status', 'failed')->update(['attempts' => 0, 'retry_at' => null]);

            return $this->s->publish($c, $id, true);
        });
    }

    public function cancel(int $id)
    {
        return $this->guard(fn () => $this->s->cancel(app('tenant'), $id));
    }

    public function duplicate(Request $r, int $id)
    {
        return $this->guard(fn () => $this->s->duplicate(app('tenant'), $r->user(), $id));
    }

    public function destroy(int $id)
    {
        return $this->guard(function () use ($id) {
            $this->s->destroy(app('tenant'), $id);

            return ['ok' => true];
        });
    }

    public function syncStats()
    {
        return ['synced' => $this->s->syncStats(app('tenant'), 60)];
    }

    // ------------------------------------------------------------------ assistant de rédaction

    public function adapt(Request $r, TextAdapter $t)
    {
        $d = $r->validate(['text' => 'required|string|max:65000', 'networks' => 'required|array|min:1', 'networks.*' => 'string', 'link' => 'nullable|string|max:1000',
            'tone' => 'nullable|string|max:80', 'with_media' => 'nullable|boolean']);

        return $t->adapt(app('tenant'), $r->user(), $d['text'], $d['networks'], $d);
    }

    public function fit(Request $r)
    {
        $d = $r->validate(['text' => 'required|string|max:65000', 'network' => 'required|string', 'with_media' => 'nullable|boolean', 'link' => 'nullable|string|max:1000']);
        abort_unless(isset(Networks::ALL[$d['network']]), 422);
        $t = TextAdapter::fit($d['network'], $d['text'], (bool) ($d['with_media'] ?? false), null, $d['link'] ?? null);

        return ['text' => $t, 'count' => Networks::count($d['network'], $t), 'limit' => Networks::limit($d['network'], (bool) ($d['with_media'] ?? false))];
    }

    public function ideas(Request $r, TextAdapter $t)
    {
        $d = $r->validate(['topic' => 'required|string|max:1000', 'count' => 'nullable|integer|min:1|max:10', 'tone' => 'nullable|string|max:80']);

        return $t->ideas(app('tenant'), $r->user(), $d['topic'], (int) ($d['count'] ?? 5), $d);
    }

    public function hashtags(Request $r, TextAdapter $t)
    {
        $d = $r->validate(['text' => 'required|string|max:65000']);

        return $t->hashtags(app('tenant'), $r->user(), $d['text']);
    }

    public function bestTimes(Request $r)
    {
        return $this->s->bestTimes(app('tenant'), (array) $r->input('networks', []));
    }

    public function analytics(Request $r)
    {
        return $this->s->analytics(app('tenant'), max(7, min(365, (int) $r->input('days', 30))));
    }

    // ------------------------------------------------------------------ applications OAuth (super administrateur)

    public function apps()
    {
        $all = (array) PlatformSetting::get('social.apps', []);

        return ['providers' => collect(Networks::PROVIDERS)->map(fn ($p, $k) => $p + ['key' => $k, 'redirect_uri' => Networks::redirectUri($k),
            'client_id' => $all[$k]['client_id'] ?? null, 'has_secret' => ! empty($all[$k]['client_secret']), 'organizations' => (bool) ($all[$k]['organizations'] ?? false),
            'extended' => (bool) ($all[$k]['extended'] ?? false)] + ($k === 'meta' ? ['webhook_url' => url('/api/meta-webhook'), 'verify_token' => MetaWebhookController::verifyToken()] : []))->values()];
    }

    public function saveApp(Request $r, string $provider)
    {
        abort_unless(isset(Networks::PROVIDERS[$provider]), 404);
        $d = $r->validate(['client_id' => 'nullable|string|max:300', 'client_secret' => 'nullable|string|max:500', 'organizations' => 'nullable|boolean', 'extended' => 'nullable|boolean']);
        $all = (array) PlatformSetting::get('social.apps', []);
        $cur = $all[$provider] ?? [];
        $cur['client_id'] = trim((string) ($d['client_id'] ?? '')) ?: null;
        if (array_key_exists('client_secret', $d) && $d['client_secret'] !== null && $d['client_secret'] !== '') {
            $cur['client_secret'] = encrypt(trim($d['client_secret']));
        }
        if (! $cur['client_id']) {
            $cur = []; // vider l'identifiant désactive la connexion
        } else {
            if (array_key_exists('organizations', $d)) {
                $cur['organizations'] = (bool) $d['organizations'];
            }
            if (array_key_exists('extended', $d)) {
                $cur['extended'] = (bool) $d['extended'];
            }
        }
        $all[$provider] = $cur;
        PlatformSetting::put('social.apps', array_filter($all));

        return $this->apps();
    }
}
