<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\Events;
use App\Integrations\Feeds;
use App\Integrations\Webhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/** Intégrations : webhooks sortants, clés d'API, flux de données (Google Sheets, Power BI, Zapier, Make, n8n). */
class IntegrationsController extends Controller
{
    protected function endpointRow(object $e): array
    {
        $stats = DB::table('wh_deliveries')->where('endpoint_id', $e->id)->where('created_at', '>=', now()->subDays(7))->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return ['id' => $e->id, 'url' => $e->url, 'description' => $e->description, 'events' => json_decode($e->events, true), 'active' => (bool) $e->active,
            'disabled_at' => $e->disabled_at, 'consecutive_failures' => (int) $e->consecutive_failures, 'last_success_at' => $e->last_success_at, 'last_failure_at' => $e->last_failure_at,
            'stats' => ['success' => (int) ($stats['success'] ?? 0), 'failed' => (int) ($stats['failed'] ?? 0), 'pending' => (int) ($stats['pending'] ?? 0)]];
    }

    protected function keys(): array
    {
        $users = DB::table('users')->where('company_id', app('tenant')->id)->pluck('name', 'id');

        return PersonalAccessToken::where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $users->keys())->where('name', 'like', 'api:%')->latest()->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => substr($t->name, 4), 'write' => $t->can('write'), 'user' => $users[$t->tokenable_id] ?? null,
                'last_used_at' => $t->last_used_at?->toIso8601String(), 'created_at' => $t->created_at?->toIso8601String(), 'hint' => 'yk_…'.$t->id])->all();
    }

    protected function feedRow(object $f): array
    {
        return ['id' => $f->id, 'name' => $f->name, 'dataset' => $f->dataset, 'dataset_label' => Feeds::DATASETS[$f->dataset]['label'] ?? $f->dataset, 'days' => (int) $f->days,
            'csv' => url('/api/feeds/'.$f->token.'.csv'), 'json' => url('/api/feeds/'.$f->token.'.json'), 'hits' => (int) $f->hits, 'last_used_at' => $f->last_used_at,
            'owner' => DB::table('users')->where('id', $f->user_id)->value('name'), 'sheets' => '=IMPORTDATA("'.url('/api/feeds/'.$f->token.'.csv').'")'];
    }

    public function index()
    {
        $c = app('tenant');

        return ['data' => [
            'endpoints' => DB::table('wh_endpoints')->where('company_id', $c->id)->orderBy('id')->get()->map(fn ($e) => $this->endpointRow($e)),
            'events' => Events::catalog(),
            'keys' => $this->keys(),
            'feeds' => DB::table('data_feeds')->where('company_id', $c->id)->orderBy('id')->get()->map(fn ($f) => $this->feedRow($f)),
            'datasets' => collect(Feeds::DATASETS)->map(fn ($d, $k) => ['key' => $k, 'label' => $d['label']])->values(),
            'docs_url' => url('/api/docs'), 'api_base' => url('/api'),
            'channels' => DB::table('notify_channels')->where('company_id', $c->id)->orderBy('id')->get()->map(fn ($x) => \App\Integrations\Channels::toApi($x)),
            'channel_kinds' => collect(\App\Integrations\Channels::KINDS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'channel_topics' => collect(\App\Integrations\Channels::TOPICS)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0]])->values(),
        ]];
    }

    // ------------------------------------------------------------------ canaux d'équipe

    protected function channelData(Request $r, bool $creating): array
    {
        $d = $r->validate([
            'kind' => ($creating ? 'required' : 'sometimes').'|in:'.implode(',', array_keys(\App\Integrations\Channels::KINDS)), 'name' => ($creating ? 'required' : 'sometimes').'|string|max:120',
            'url' => 'nullable|string|max:1000', 'token' => 'nullable|string|max:200', 'chat_id' => 'nullable|string|max:60',
            'events' => ($creating ? 'required' : 'sometimes').'|array|min:1', 'events.*' => 'string|max:30', 'active' => 'nullable|boolean',
        ]);

        return $d;
    }

    public function storeChannel(Request $r)
    {
        $d = $this->channelData($r, true);
        try {
            $cfg = \App\Integrations\Channels::validateConfig($d['kind'], $d);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $id = DB::table('notify_channels')->insertGetId(['company_id' => app('tenant')->id, 'kind' => $d['kind'], 'name' => $d['name'], 'config' => Crypt::encryptString(json_encode($cfg)),
            'events' => json_encode(array_values(array_unique($d['events']))), 'active' => $d['active'] ?? true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => \App\Integrations\Channels::toApi(DB::table('notify_channels')->find($id))], 201);
    }

    public function updateChannel(Request $r, int $id)
    {
        $ch = DB::table('notify_channels')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($ch, 404);
        $d = $this->channelData($r, false);
        $set = array_intersect_key($d, array_flip(['name', 'active']));
        if (isset($d['events'])) {
            $set['events'] = json_encode(array_values(array_unique($d['events'])));
        }
        if (! empty($d['url']) || ! empty($d['token'])) {
            try {
                $set['config'] = Crypt::encryptString(json_encode(\App\Integrations\Channels::validateConfig($ch->kind, $d + \App\Integrations\Channels::config($ch))));
            } catch (\DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }
        DB::table('notify_channels')->where('id', $id)->update($set + ['updated_at' => now()]);

        return ['data' => \App\Integrations\Channels::toApi(DB::table('notify_channels')->find($id))];
    }

    public function destroyChannel(int $id)
    {
        DB::table('notify_channels')->where('company_id', app('tenant')->id)->where('id', $id)->delete();

        return response()->noContent();
    }

    public function testChannel(int $id)
    {
        $ch = DB::table('notify_channels')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($ch, 404);
        try {
            \App\Integrations\Channels::deliver($ch, 'Test de Yomara ERP', 'Ce canal recevra les notifications choisies.');
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['ok' => true];
    }

    protected function endpointRules(): array
    {
        return ['url' => ['required', 'url', 'max:500', 'starts_with:https://,http://', function ($attr, $v, $fail) {
            if (! \App\Support\UrlGuard::isPublic((string) $v)) {
                $fail('Adresse refusée : le webhook doit pointer vers un serveur public (pas le réseau interne).');
            }
        }], 'description' => 'nullable|string|max:190', 'events' => 'required|array|min:1', 'events.*' => 'string|max:60', 'active' => 'nullable|boolean'];
    }

    public function storeEndpoint(Request $r)
    {
        $d = $r->validate($this->endpointRules());
        $secret = Webhooks::secret();
        $id = DB::table('wh_endpoints')->insertGetId(['company_id' => app('tenant')->id, 'url' => $d['url'], 'description' => $d['description'] ?? null,
            'events' => json_encode(array_values(array_unique($d['events']))), 'secret' => Crypt::encryptString($secret), 'active' => $d['active'] ?? true,
            'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        Webhooks::forget(app('tenant')->id);

        return response()->json(['data' => $this->endpointRow(DB::table('wh_endpoints')->find($id)), 'secret' => $secret], 201);
    }

    protected function endpoint(int $id): object
    {
        $e = DB::table('wh_endpoints')->where('company_id', app('tenant')->id)->find($id);
        abort_unless($e, 404);

        return $e;
    }

    public function updateEndpoint(Request $r, int $id)
    {
        $this->endpoint($id);
        $d = $r->validate($this->endpointRules());
        DB::table('wh_endpoints')->where('id', $id)->update(['url' => $d['url'], 'description' => $d['description'] ?? null, 'events' => json_encode(array_values(array_unique($d['events']))),
            'active' => $d['active'] ?? true, 'disabled_at' => null, 'consecutive_failures' => 0, 'updated_at' => now()]);
        Webhooks::forget(app('tenant')->id);

        return ['data' => $this->endpointRow(DB::table('wh_endpoints')->find($id))];
    }

    public function destroyEndpoint(int $id)
    {
        $this->endpoint($id);
        DB::table('wh_endpoints')->where('id', $id)->delete();
        DB::table('wh_deliveries')->where('endpoint_id', $id)->delete();
        Webhooks::forget(app('tenant')->id);

        return response()->noContent();
    }

    public function secret(int $id)
    {
        $this->endpoint($id);
        $s = Webhooks::secret();
        DB::table('wh_endpoints')->where('id', $id)->update(['secret' => Crypt::encryptString($s), 'updated_at' => now()]);

        return ['secret' => $s];
    }

    /** Envoie un événement « ping » et renvoie le résultat immédiatement. */
    public function test(int $id, Webhooks $wh)
    {
        $e = $this->endpoint($id);
        Webhooks::forget(app('tenant')->id);
        $ids = Webhooks::emit(app('tenant')->id, 'ping', ['message' => 'Test de connexion depuis Yomara ERP', 'company' => app('tenant')->name, 'endpoint' => $e->url], $e->id);
        abort_unless($ids, 422, 'Adresse désactivée : réactivez-la avant de la tester.');

        return ['data' => $this->deliveryRow($wh->deliver($ids[0]), true)];
    }

    protected function deliveryRow(object $d, bool $full = false): array
    {
        return ['id' => $d->id, 'event' => $d->event, 'status' => $d->status, 'attempts' => (int) $d->attempts, 'response_code' => $d->response_code,
            'duration_ms' => $d->duration_ms, 'created_at' => (string) $d->created_at, 'next_attempt_at' => $d->next_attempt_at, 'delivered_at' => $d->delivered_at]
            + ($full ? ['payload' => json_decode($d->payload, true), 'response_body' => $d->response_body] : []);
    }

    public function deliveries(Request $r, int $id)
    {
        $this->endpoint($id);
        $q = DB::table('wh_deliveries')->where('endpoint_id', $id)->when($r->query('status'), fn ($w, $s) => $w->where('status', $s))->orderByDesc('created_at')->limit(100);

        return ['data' => $q->get()->map(fn ($d) => $this->deliveryRow($d))];
    }

    public function delivery(string $id)
    {
        $d = DB::table('wh_deliveries')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($d, 404);

        return ['data' => $this->deliveryRow($d, true)];
    }

    public function redeliver(string $id, Webhooks $wh)
    {
        $d = DB::table('wh_deliveries')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($d, 404);
        DB::table('wh_deliveries')->where('id', $id)->update(['status' => 'pending', 'next_attempt_at' => now()]);

        return ['data' => $this->deliveryRow($wh->deliver($id), true)];
    }

    // ---------------------------------------------------------------- Clés d'API
    public function storeKey(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:80', 'write' => 'nullable|boolean']);
        $t = $r->user()->createToken('api:'.$d['name'], ($d['write'] ?? false) ? ['read', 'write'] : ['read']);

        return response()->json(['token' => $t->plainTextToken, 'data' => collect($this->keys())->firstWhere('id', $t->accessToken->id)], 201);
    }

    public function destroyKey(int $id)
    {
        $users = DB::table('users')->where('company_id', app('tenant')->id)->pluck('id');
        abort_unless(PersonalAccessToken::whereIn('tokenable_id', $users)->where('tokenable_type', \App\Models\User::class)->where('name', 'like', 'api:%')->where('id', $id)->delete(), 404);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Flux de données
    public function storeFeed(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'dataset' => ['required', Rule::in(array_keys(Feeds::DATASETS))], 'days' => 'nullable|integer|min:0|max:3650']);
        abort_unless(Feeds::allowed($r->user(), $d['dataset']), 403, 'Vous n\'avez pas accès à ces données.');
        $id = DB::table('data_feeds')->insertGetId(['company_id' => app('tenant')->id, 'user_id' => $r->user()->id, 'name' => $d['name'], 'dataset' => $d['dataset'],
            'days' => $d['days'] ?? 0, 'token' => Str::random(40), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => $this->feedRow(DB::table('data_feeds')->find($id))], 201);
    }

    public function destroyFeed(int $id)
    {
        abort_unless(DB::table('data_feeds')->where('company_id', app('tenant')->id)->where('id', $id)->delete(), 404);

        return response()->noContent();
    }
}
