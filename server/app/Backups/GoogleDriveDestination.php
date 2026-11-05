<?php

namespace App\Backups;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;

/** Google Drive (accès limité aux fichiers créés par le logiciel : drive.file), dossier « Sauvegardes Yomara ERP ». */
class GoogleDriveDestination extends Destination
{
    public const KEY = 'google';

    public const LABEL = 'Google Drive';

    public const FOLDER = 'Sauvegardes Yomara ERP';

    protected function app(): array
    {
        $id = config('erp.mailbox.google_client_id');
        $secret = config('erp.mailbox.google_client_secret');
        if (! $id || ! $secret) {
            throw new \DomainException('Application Google non configurée : renseignez GOOGLE_CLIENT_ID et GOOGLE_CLIENT_SECRET dans le fichier .env du serveur (avec l\'adresse de retour '.self::redirectUri().').');
        }

        return [$id, $secret];
    }

    public function authUrl(string $state): string
    {
        [$id] = $this->app();

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => $id, 'redirect_uri' => self::redirectUri(), 'response_type' => 'code',
            'scope' => 'openid email https://www.googleapis.com/auth/drive.file', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state]);
    }

    protected function exchange(string $code): array
    {
        [$id, $secret] = $this->app();
        $r = Http::asForm()->post('https://oauth2.googleapis.com/token', ['code' => $code, 'client_id' => $id, 'client_secret' => $secret, 'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code']);
        if (! $r->successful()) {
            throw $this->fail($r, 'connexion');
        }
        $t = $r->json();
        $t['email'] = Http::withToken($t['access_token'])->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');

        return $t;
    }

    protected function refresh(string $rt): array
    {
        [$id, $secret] = $this->app();
        $r = Http::asForm()->post('https://oauth2.googleapis.com/token', ['refresh_token' => $rt, 'client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'refresh_token']);
        if (! $r->successful()) {
            throw $this->fail($r, 'jeton');
        }

        return $r->json();
    }

    protected function folder(): string
    {
        $d = $this->backups->destination(self::KEY);
        if (! empty($d['folder_id'])) {
            return $d['folder_id'];
        }
        $tok = $this->token();
        $q = "name = '".self::FOLDER."' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $id = Http::withToken($tok)->get('https://www.googleapis.com/drive/v3/files', ['q' => $q, 'fields' => 'files(id)'])->json('files.0.id');
        if (! $id) {
            $r = Http::withToken($tok)->post('https://www.googleapis.com/drive/v3/files', ['name' => self::FOLDER, 'mimeType' => 'application/vnd.google-apps.folder']);
            if (! $r->successful()) {
                throw $this->fail($r, 'dossier');
            }
            $id = $r->json('id');
        }
        $d = $this->backups->destination(self::KEY);
        $d['folder_id'] = $id;
        $this->backups->setDestination(self::KEY, $d);

        return $id;
    }

    public function upload(string $path, string $name): void
    {
        $folder = $this->folder();
        $tok = $this->token();
        $init = Http::withToken($tok)->withHeaders(['X-Upload-Content-Type' => 'application/zip', 'X-Upload-Content-Length' => (string) filesize($path)])
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', ['name' => $name, 'parents' => [$folder], 'mimeType' => 'application/zip']);
        $session = $init->header('Location');
        if (! $init->successful() || ! $session) {
            throw $this->fail($init, 'envoi');
        }
        $r = Http::withToken($tok)->timeout(3600)->withBody(Utils::streamFor(fopen($path, 'r')), 'application/zip')->put($session);
        if (! $r->successful()) {
            throw $this->fail($r, 'envoi');
        }
    }

    public function list(): array
    {
        $r = Http::withToken($this->token())->get('https://www.googleapis.com/drive/v3/files', ['q' => "'".$this->folder()."' in parents and trashed = false",
            'orderBy' => 'createdTime desc', 'fields' => 'files(id,name,size,createdTime)', 'pageSize' => 200]);
        if (! $r->successful()) {
            throw $this->fail($r, 'liste');
        }

        return collect($r->json('files') ?? [])->map(fn ($f) => ['id' => $f['id'], 'name' => $f['name'], 'size' => (int) ($f['size'] ?? 0), 'created_at' => $f['createdTime'] ?? null])->all();
    }

    public function prune(int $keep): void
    {
        foreach (array_slice($this->list(), max(1, $keep)) as $f) {
            Http::withToken($this->token())->delete('https://www.googleapis.com/drive/v3/files/'.$f['id']);
        }
    }

    public function download(string $id, string $to): void
    {
        $r = Http::withToken($this->token())->timeout(3600)->sink($to)->get('https://www.googleapis.com/drive/v3/files/'.rawurlencode($id), ['alt' => 'media']);
        if (! $r->successful()) {
            throw $this->fail($r, 'téléchargement');
        }
    }
}
