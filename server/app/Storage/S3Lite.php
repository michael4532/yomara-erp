<?php

namespace App\Storage;

use Illuminate\Support\Facades\Http;

/**
 * Client minimal pour les stockages compatibles S3 (Cloudflare R2, Backblaze B2, Wasabi, AWS S3, iDrive e2,
 * Scaleway, OVHcloud, Contabo, MinIO…) : déposer, lire, supprimer un fichier. Signature AWS v4, adresses « path-style ».
 * Pas de dépendance lourde (le SDK AWS ajouterait des dizaines de Mo à l'hébergement mutualisé).
 */
class S3Lite
{
    public function __construct(
        protected string $endpoint,
        protected string $region,
        protected string $bucket,
        protected string $key,
        protected string $secret,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->region = $region ?: 'auto';
    }

    /** Heure fixe pour les tests de signature. */
    public ?string $fixedNow = null;

    /** Requête signée sans l'envoyer (tests). */
    public function signedHeaders(string $method, string $path, string $body = '', array $headers = []): array
    {
        return $this->sign($method, $path, $body, $headers)[1];
    }

    public function put(string $path, string $bytes, string $mime = 'application/octet-stream'): void
    {
        $r = $this->send('PUT', $path, $bytes, ['Content-Type' => $mime]);
        if (! $r->successful()) {
            throw new \RuntimeException($this->error($r));
        }
    }

    public function get(string $path): string
    {
        $r = $this->send('GET', $path);
        if (! $r->successful()) {
            throw new \RuntimeException($this->error($r));
        }

        return $r->body();
    }

    public function delete(string $path): void
    {
        $r = $this->send('DELETE', $path);
        if (! $r->successful() && $r->status() !== 404) {
            throw new \RuntimeException($this->error($r));
        }
    }

    protected function error($r): string
    {
        $msg = preg_match('#<Message>(.*?)</Message>#s', $r->body(), $m) ? $m[1] : trim(substr(strip_tags($r->body()), 0, 200));

        return 'Stockage '.$r->status().($msg ? ' : '.$msg : '');
    }

    protected function sign(string $method, string $path, string $body = '', array $headers = []): array
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        $base = parse_url($this->endpoint, PHP_URL_PATH) ?: '';
        $uri = rtrim($base, '/').'/'.rawurlencode($this->bucket).'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        $now = $this->fixedNow ?? gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $hash = hash('sha256', $body);
        $all = ['host' => $host.($port ? ':'.$port : ''), 'x-amz-content-sha256' => $hash, 'x-amz-date' => $now] + array_change_key_case($headers);
        ksort($all);
        $canonHeaders = '';
        foreach ($all as $k => $v) {
            $canonHeaders .= $k.':'.trim((string) $v)."\n";
        }
        $signed = implode(';', array_keys($all));
        $canon = implode("\n", [$method, $uri, '', $canonHeaders, $signed, $hash]);
        $scope = "$date/{$this->region}/s3/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $now, $scope, hash('sha256', $canon)]);
        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $this->region, hash_hmac('sha256', $date, 'AWS4'.$this->secret, true), true), true), true);
        $auth = "AWS4-HMAC-SHA256 Credential={$this->key}/$scope, SignedHeaders=$signed, Signature=".hash_hmac('sha256', $toSign, $k);
        $send = $all;
        unset($send['host']);
        $url = preg_replace('#^(https?://[^/]+).*$#', '$1', $this->endpoint).$uri;

        return [$url, $send + ['Authorization' => $auth]];
    }

    protected function send(string $method, string $path, string $body = '', array $headers = [])
    {
        [$url, $h] = $this->sign($method, $path, $body, $headers);
        $req = Http::timeout(120)->withHeaders($h);

        return $method === 'PUT' ? $req->withBody($body, $headers['Content-Type'] ?? 'application/octet-stream')->put($url) : $req->send($method, $url);
    }
}
