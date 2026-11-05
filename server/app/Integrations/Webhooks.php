<?php

namespace App\Integrations;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhooks sortants : à chaque événement, une requête POST signée (HMAC SHA-256) part vers les adresses abonnées.
 * Les livraisons sont enregistrées, envoyées juste après la réponse HTTP, puis relancées en cas d'échec
 * (1 min, 5 min, 30 min, 2 h, 6 h, 24 h) ; une adresse en échec 50 fois de suite est désactivée.
 */
class Webhooks
{
    public const BACKOFF = [60, 300, 1800, 7200, 21600, 86400];

    protected static array $endpoints = [];

    protected static array $queued = [];

    protected static bool $flushRegistered = false;

    public static bool $enabled = true;

    public static function forget(?int $companyId = null): void
    {
        if ($companyId) {
            unset(self::$endpoints[$companyId]);
        } else {
            self::$endpoints = [];
        }
    }

    protected static function endpoints(int $companyId)
    {
        $c = self::$endpoints[$companyId] ?? null;
        if (! $c || $c['at'] < time() - 60) {
            $c = self::$endpoints[$companyId] = ['at' => time(), 'list' => DB::table('wh_endpoints')->where('company_id', $companyId)->where('active', true)->whereNull('disabled_at')->get()
                ->map(function ($e) {
                    $e->events = (array) json_decode((string) $e->events, true);

                    return $e;
                })];
        }

        return $c['list'];
    }

    /** Écriture d'une entité synchronisée (appelé par le trait Syncable). */
    public static function model(Model $m, string $op): void
    {
        if (! self::$enabled || ! $m->company_id || ! property_exists($m, 'syncEntity')) {
            return;
        }
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('wh_endpoints')) {
                return;
            }
            $list = self::endpoints((int) $m->company_id);
            if ($list->isEmpty()) {
                return;
            }
            $entity = $m::$syncEntity;
            $events = $op === 'deleted' ? [$entity.'.deleted'] : [$entity.'.'.$op];
            if ($op !== 'deleted') {
                $events = array_merge($events, Events::derive($entity, $op === 'created', $m->getChanges(), $m->getAttributes()));
            }
            $wanted = array_values(array_filter($events, fn ($ev) => $list->contains(fn ($e) => Events::matches($e->events, $ev))));
            if (! $wanted) {
                return;
            }
            if (method_exists($m, 'lines') && $op !== 'deleted') {
                $m->loadMissing('lines');
            }
            $data = $op === 'deleted' ? ['id' => $m->getKey()] : (method_exists($m, 'toSyncArray') ? $m->toSyncArray() : $m->toArray());
            foreach ($wanted as $ev) {
                self::emit((int) $m->company_id, $ev, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('Webhooks : '.$e->getMessage());
        }
    }

    /** Diffuse un événement aux adresses abonnées de la société. */
    public static function emit(int $companyId, string $event, array $data, ?int $onlyEndpoint = null): array
    {
        if (! self::$enabled) {
            return [];
        }
        $ids = [];
        foreach (self::endpoints($companyId) as $e) {
            if ($onlyEndpoint ? $e->id !== $onlyEndpoint : ! Events::matches($e->events, $event)) {
                continue;
            }
            $id = (string) Str::uuid();
            $payload = ['id' => $id, 'event' => $event, 'created_at' => now()->toIso8601String(), 'company_id' => $companyId, 'data' => $data];
            DB::table('wh_deliveries')->insert(['id' => $id, 'endpoint_id' => $e->id, 'company_id' => $companyId, 'event' => $event,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 'status' => 'pending',
                'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $id;
        }
        if ($ids) {
            self::$queued = array_merge(self::$queued, $ids);
            if (! self::$flushRegistered) {
                self::$flushRegistered = true;
                app()->terminating(function () {
                    self::$flushRegistered = false;
                    $q = self::$queued;
                    self::$queued = [];
                    app(self::class)->deliverMany($q);
                });
            }
        }

        return $ids;
    }

    public static function sign(string $secret, int $ts, string $body): string
    {
        return 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$body, $secret);
    }

    public function deliverMany(array $ids): void
    {
        foreach (array_slice($ids, 0, 50) as $id) {
            $this->deliver($id);
        }
    }

    public function deliver(string $id): ?object
    {
        $d = DB::table('wh_deliveries')->where('id', $id)->first();
        if (! $d || $d->status === 'success') {
            return $d;
        }
        $e = DB::table('wh_endpoints')->find($d->endpoint_id);
        if (! $e) {
            DB::table('wh_deliveries')->where('id', $id)->update(['status' => 'failed', 'response_body' => 'Adresse supprimée.', 'next_attempt_at' => null]);

            return null;
        }
        $secret = rescue(fn () => Crypt::decryptString($e->secret), '', false);
        $ts = time();
        $start = microtime(true);
        $code = null;
        $body = '';
        try {
            $r = Http::timeout(15)->connectTimeout(8)->withOptions(\App\Support\UrlGuard::pinned((string) $e->url))->withHeaders([
                'Content-Type' => 'application/json', 'User-Agent' => 'Yomara-Webhooks/1.0', 'X-Yomara-Event' => $d->event, 'X-Yomara-Delivery' => $d->id,
                'X-Yomara-Signature' => self::sign($secret, $ts, $d->payload),
            ])->withBody($d->payload, 'application/json')->post($e->url);
            $code = $r->status();
            $body = mb_substr($r->body(), 0, 2000);
            $ok = $r->successful();
        } catch (\Throwable $ex) {
            $ok = false;
            $body = mb_substr($ex->getMessage(), 0, 500);
        }
        $attempts = $d->attempts + 1;
        $row = ['attempts' => $attempts, 'response_code' => $code, 'response_body' => $body, 'duration_ms' => (int) round((microtime(true) - $start) * 1000), 'updated_at' => now()];
        if ($ok) {
            $row += ['status' => 'success', 'delivered_at' => now(), 'next_attempt_at' => null];
            DB::table('wh_endpoints')->where('id', $e->id)->update(['consecutive_failures' => 0, 'last_success_at' => now()]);
        } else {
            $more = $attempts <= count(self::BACKOFF);
            $row += ['status' => $more ? 'pending' : 'failed', 'next_attempt_at' => $more ? now()->addSeconds(self::BACKOFF[$attempts - 1]) : null];
            $fails = $e->consecutive_failures + 1;
            DB::table('wh_endpoints')->where('id', $e->id)->update(['consecutive_failures' => $fails, 'last_failure_at' => now(), 'disabled_at' => $fails >= 50 ? now() : null]);
            if ($fails >= 50) {
                self::forget($e->company_id);
            }
        }
        DB::table('wh_deliveries')->where('id', $id)->update($row);

        return DB::table('wh_deliveries')->where('id', $id)->first();
    }

    /** Tâche planifiée : relances dues, puis ménage (livraisons de plus de 30 jours). */
    public function retryDue(): array
    {
        $ids = DB::table('wh_deliveries')->where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(300)->pluck('id');
        $ok = 0;
        foreach ($ids as $id) {
            $r = $this->deliver($id);
            if ($r && $r->status === 'success') {
                $ok++;
            }
        }
        DB::table('wh_deliveries')->where('created_at', '<', now()->subDays(30))->delete();

        return ['sent' => $ids->count(), 'success' => $ok];
    }

    public static function secret(): string
    {
        return 'whsec_'.Str::random(32);
    }
}
