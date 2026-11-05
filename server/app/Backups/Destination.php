<?php

namespace App\Backups;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Destination distante d'une sauvegarde (OAuth hors ligne, jeton de rafraîchissement chiffré). */
abstract class Destination
{
    public const KEY = '';

    public const LABEL = '';

    public function __construct(protected BackupService $backups) {}

    abstract public function authUrl(string $state): string;

    /** Échange le code contre les jetons ; renvoie ['refresh_token', 'access_token', 'expires_in', 'email']. */
    abstract protected function exchange(string $code): array;

    abstract protected function refresh(string $refreshToken): array;

    abstract public function upload(string $path, string $name): void;

    abstract public function prune(int $keep): void;

    /** @return list<array{id: string, name: string, size: int, created_at: string}> */
    abstract public function list(): array;

    abstract public function download(string $id, string $to): void;

    public static function redirectUri(): string
    {
        return url('/oauth/sauvegarde/'.static::KEY.'/retour');
    }

    public function connectUrl(int $userId): string
    {
        $state = Str::random(40);
        PlatformSetting::put('backups.oauth', ['state' => $state, 'provider' => static::KEY, 'user_id' => $userId, 'expires' => time() + 900]);

        return $this->authUrl($state);
    }

    public function complete(string $state, string $code): array
    {
        $o = (array) PlatformSetting::get('backups.oauth', []);
        if (($o['state'] ?? null) !== $state || ($o['provider'] ?? null) !== static::KEY || ($o['expires'] ?? 0) < time()) {
            throw new \DomainException('Lien de connexion expiré : recommencez depuis le logiciel.');
        }
        PlatformSetting::put('backups.oauth', []);
        $t = $this->exchange($code);
        if (empty($t['refresh_token'])) {
            throw new \DomainException(static::LABEL.' n\'a pas accordé d\'accès hors ligne : recommencez la connexion.');
        }
        $d = ['refresh_token' => Crypt::encryptString($t['refresh_token']), 'email' => $t['email'] ?? null, 'connected_at' => now()->toIso8601String(),
            'access' => Crypt::encryptString(json_encode(['token' => $t['access_token'] ?? null, 'expires' => time() + (int) ($t['expires_in'] ?? 3600) - 60]))];
        $this->backups->setDestination(static::KEY, $d);

        return $d;
    }

    protected function token(): string
    {
        $d = $this->backups->destination(static::KEY);
        if (! $d) {
            throw new \DomainException(static::LABEL.' n\'est pas connecté.');
        }
        $a = json_decode((string) rescue(fn () => Crypt::decryptString($d['access'] ?? ''), '', false), true) ?: [];
        if (! empty($a['token']) && ($a['expires'] ?? 0) > time()) {
            return $a['token'];
        }
        $t = $this->refresh(Crypt::decryptString($d['refresh_token']));
        $d['access'] = Crypt::encryptString(json_encode(['token' => $t['access_token'], 'expires' => time() + (int) ($t['expires_in'] ?? 3600) - 60]));
        if (! empty($t['refresh_token'])) {
            $d['refresh_token'] = Crypt::encryptString($t['refresh_token']);
        }
        $this->backups->setDestination(static::KEY, $d);

        return $t['access_token'];
    }

    protected function fail(\Illuminate\Http\Client\Response $r, string $what): \RuntimeException
    {
        $msg = $r->json('error.message') ?? $r->json('error_summary') ?? $r->json('error_description') ?? (is_string($r->json('error')) ? $r->json('error') : null) ?? ('HTTP '.$r->status());

        return new \RuntimeException(static::LABEL.' ('.$what.') : '.$msg);
    }
}
