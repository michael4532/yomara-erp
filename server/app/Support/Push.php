<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications push sur les téléphones (Firebase Cloud Messaging, API HTTP v1).
 * Chaque notification de l'application (cloche) part aussi en push vers les appareils des destinataires,
 * en fin de requête pour ne pas ralentir l'action de l'utilisateur.
 */
class Push
{
    /** @var array<int, array{users: int[], title: string, body: ?string, data: array}> */
    protected static array $pending = [];

    protected static bool $hooked = false;

    /** Remplacé dans les tests pour observer les envois. */
    public static $sender = null;

    public static function account(): ?array
    {
        $raw = config('erp.push.fcm_service_account');
        $j = $raw ? json_decode((string) $raw, true) : null;

        return is_array($j) && ! empty($j['private_key']) && ! empty($j['client_email']) && ! empty($j['project_id']) ? $j : null;
    }

    public static function enabled(): bool
    {
        return static::$sender !== null || static::account() !== null;
    }

    /** Met des destinataires en file ; envoi groupé à la fin de la requête (ou du job). */
    public static function queue(iterable $userIds, string $title, ?string $body = null, array $data = []): void
    {
        if (! static::enabled()) {
            return;
        }
        $users = collect($userIds)->filter()->map(fn ($u) => (int) $u)->unique()->values()->all();
        if (! $users) {
            return;
        }
        static::$pending[] = ['users' => $users, 'title' => $title, 'body' => $body, 'data' => $data];
        if (! static::$hooked) {
            static::$hooked = true;
            app()->terminating(fn () => static::flush());
            if (app()->runningInConsole()) {
                \Illuminate\Support\Facades\Queue::after(fn () => static::flush());
            }
        }
    }

    public static function flush(): int
    {
        $batch = static::$pending;
        static::$pending = [];
        $sent = 0;
        foreach ($batch as $n) {
            try {
                $sent += static::toUsers($n['users'], $n['title'], $n['body'], $n['data']);
            } catch (\Throwable $e) {
                Log::warning('Push non envoyé : '.$e->getMessage());
            }
        }

        return $sent;
    }

    /** Envoie tout de suite à tous les appareils des utilisateurs ; renvoie le nombre d'envois réussis. */
    public static function toUsers(array $userIds, string $title, ?string $body = null, array $data = []): int
    {
        if (! Schema::hasTable('push_tokens')) {
            return 0;
        }
        $rows = DB::table('push_tokens')->whereIn('user_id', $userIds)->where('updated_at', '>=', now()->subDays(90))->limit(200)->get(['id', 'token', 'platform']);
        $ok = 0;
        foreach ($rows as $r) {
            $res = static::send((string) $r->token, $title, $body, $data);
            if ($res === 'gone') {
                DB::table('push_tokens')->where('id', $r->id)->delete(); // appareil désinstallé ou jeton expiré
            } elseif ($res === 'ok') {
                $ok++;
            }
        }

        return $ok;
    }

    /** @return 'ok'|'gone'|'error' */
    public static function send(string $token, string $title, ?string $body, array $data = []): string
    {
        $data = array_map(fn ($v) => (string) $v, array_filter($data, fn ($v) => $v !== null && $v !== ''));
        $message = [
            'token' => $token,
            'notification' => array_filter(['title' => mb_substr($title, 0, 200), 'body' => $body !== null ? mb_substr($body, 0, 500) : null]),
            'data' => (object) $data,
            'android' => ['priority' => 'HIGH', 'notification' => ['sound' => 'default', 'color' => '#1652C4']],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
        ];
        if (static::$sender) {
            return (static::$sender)($message);
        }
        $acc = static::account();
        $access = static::accessToken($acc);
        if (! $access) {
            return 'error';
        }
        $res = Http::withToken($access)->timeout(10)
            ->post('https://fcm.googleapis.com/v1/projects/'.$acc['project_id'].'/messages:send', ['message' => $message]);
        if ($res->successful()) {
            return 'ok';
        }
        $code = (string) data_get($res->json(), 'error.details.0.errorCode', data_get($res->json(), 'error.status', ''));
        if ($res->status() === 404 || in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'], true) && str_contains((string) $res->body(), 'token')) {
            return 'gone';
        }
        Log::warning('FCM '.$res->status().' : '.mb_substr((string) $res->body(), 0, 300));

        return 'error';
    }

    /** Jeton OAuth 2 du compte de service (JWT signé RS256), gardé 50 minutes. */
    protected static function accessToken(array $acc): ?string
    {
        return Cache::remember('fcm:access', 3000, function () use ($acc) {
            $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $now = time();
            $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode(['iss' => $acc['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $acc['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
            if (! openssl_sign($head.'.'.$claims, $sig, $acc['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Clé privée Firebase illisible.');
            }
            $res = Http::asForm()->timeout(10)->post($acc['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $head.'.'.$claims.'.'.$b64($sig),
            ]);
            if (! $res->successful()) {
                throw new \RuntimeException('Google refuse le compte de service : '.mb_substr((string) $res->body(), 0, 200));
            }

            return (string) $res->json('access_token');
        }) ?: null;
    }
}
