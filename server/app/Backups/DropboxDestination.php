<?php

namespace App\Backups;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/** Dropbox (application de type « dossier d'application »), dossier « /Sauvegardes Yomara ERP ». */
class DropboxDestination extends Destination
{
    public const KEY = 'dropbox';

    public const LABEL = 'Dropbox';

    public const FOLDER = '/Sauvegardes Yomara ERP';

    public const CHUNK = 8 * 1024 * 1024;

    protected function app(): array
    {
        $s = $this->backups->settings();
        $id = config('erp.backups.dropbox_key') ?: ($s['dropbox_app_key'] ?? null);
        $secret = config('erp.backups.dropbox_secret') ?: (! empty($s['dropbox_app_secret']) ? rescue(fn () => Crypt::decryptString($s['dropbox_app_secret']), null, false) : null);
        if (! $id || ! $secret) {
            throw new \DomainException('Application Dropbox non configurée : créez-la sur dropbox.com/developers (accès « App folder ») avec l\'adresse de retour '.self::redirectUri().', puis saisissez sa clé et son secret.');
        }

        return [$id, $secret];
    }

    public function authUrl(string $state): string
    {
        [$id] = $this->app();

        return 'https://www.dropbox.com/oauth2/authorize?'.http_build_query(['client_id' => $id, 'response_type' => 'code', 'token_access_type' => 'offline',
            'redirect_uri' => self::redirectUri(), 'state' => $state]);
    }

    protected function exchange(string $code): array
    {
        [$id, $secret] = $this->app();
        $r = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', ['code' => $code, 'grant_type' => 'authorization_code', 'client_id' => $id, 'client_secret' => $secret, 'redirect_uri' => self::redirectUri()]);
        if (! $r->successful()) {
            throw $this->fail($r, 'connexion');
        }
        $t = $r->json();
        $t['email'] = Http::withToken($t['access_token'])->withBody('null', 'application/json')->post('https://api.dropboxapi.com/2/users/get_current_account')->json('email');

        return $t;
    }

    protected function refresh(string $rt): array
    {
        [$id, $secret] = $this->app();
        $r = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', ['refresh_token' => $rt, 'grant_type' => 'refresh_token', 'client_id' => $id, 'client_secret' => $secret]);
        if (! $r->successful()) {
            throw $this->fail($r, 'jeton');
        }

        return $r->json();
    }

    protected function api(string $path, ?array $body = null)
    {
        return Http::withToken($this->token())->timeout(120)->withBody(json_encode($body), 'application/json')->post('https://api.dropboxapi.com/2/'.$path);
    }

    public function upload(string $path, string $name): void
    {
        $dest = self::FOLDER.'/'.$name;
        $size = filesize($path);
        $content = fn (string $endpoint, array $arg, string $bytes) => Http::withToken($this->token())->timeout(600)
            ->withHeaders(['Dropbox-API-Arg' => json_encode($arg)])->withBody($bytes, 'application/octet-stream')->post('https://content.dropboxapi.com/2/'.$endpoint);
        $h = fopen($path, 'r');
        try {
            if ($size <= self::CHUNK) {
                $r = $content('files/upload', ['path' => $dest, 'mode' => 'add', 'autorename' => true], (string) fread($h, max(1, $size)));
                if (! $r->successful()) {
                    throw $this->fail($r, 'envoi');
                }

                return;
            }
            $r = $content('files/upload_session/start', ['close' => false], (string) fread($h, self::CHUNK));
            if (! $r->successful()) {
                throw $this->fail($r, 'envoi');
            }
            $sid = $r->json('session_id');
            $offset = self::CHUNK;
            while ($offset + self::CHUNK < $size) {
                $r = $content('files/upload_session/append_v2', ['cursor' => ['session_id' => $sid, 'offset' => $offset], 'close' => false], (string) fread($h, self::CHUNK));
                if (! $r->successful()) {
                    throw $this->fail($r, 'envoi');
                }
                $offset += self::CHUNK;
            }
            $r = $content('files/upload_session/finish', ['cursor' => ['session_id' => $sid, 'offset' => $offset], 'commit' => ['path' => $dest, 'mode' => 'add', 'autorename' => true]], (string) stream_get_contents($h));
            if (! $r->successful()) {
                throw $this->fail($r, 'envoi');
            }
        } finally {
            fclose($h);
        }
    }

    public function list(): array
    {
        $r = $this->api('files/list_folder', ['path' => self::FOLDER]);
        if ($r->status() === 409) {
            return []; // dossier pas encore créé
        }
        if (! $r->successful()) {
            throw $this->fail($r, 'liste');
        }

        return collect($r->json('entries') ?? [])->where('.tag', 'file')->map(fn ($f) => ['id' => $f['path_lower'], 'name' => $f['name'], 'size' => (int) ($f['size'] ?? 0),
            'created_at' => $f['server_modified'] ?? null])->sortByDesc('created_at')->values()->all();
    }

    public function prune(int $keep): void
    {
        foreach (array_slice($this->list(), max(1, $keep)) as $f) {
            $this->api('files/delete_v2', ['path' => $f['id']]);
        }
    }

    public function download(string $id, string $to): void
    {
        $r = Http::withToken($this->token())->timeout(3600)->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => $id])])->sink($to)->post('https://content.dropboxapi.com/2/files/download');
        if (! $r->successful()) {
            throw $this->fail($r, 'téléchargement');
        }
    }
}
