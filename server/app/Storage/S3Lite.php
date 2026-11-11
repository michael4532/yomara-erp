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

    /**
     * Adresse refusée pour un stockage : raison, ou null si elle est acceptable.
     * Toujours refusé : adresses de liaison locale (169.254.x.x, dont les métadonnées cloud) et non routables.
     * Réseau interne (127.x, 10.x, 192.168.x…) : seulement si $allowPrivate (serveur MinIO à vous).
     */
    public static function blockedReason(string $endpoint, bool $allowPrivate = false): ?string
    {
        $p = parse_url($endpoint);
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if (! in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) {
            return 'Adresse invalide : utilisez https://hôte[:port].';
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge((array) @gethostbynamel($host), self::aaaa($host));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            $ips[] = '127.0.0.1';
        }
        foreach (array_filter($ips) as $ip) {
            if (self::isLinkLocal($ip)) {
                return 'Adresse interdite (réseau de liaison locale / métadonnées du serveur).';
            }
            if (! $allowPrivate && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'Adresse interne refusée : seul un serveur MinIO à vous peut être sur le réseau local.';
            }
        }

        return null;
    }

    protected static function aaaa(string $host): array
    {
        $r = @dns_get_record($host, DNS_AAAA);

        return is_array($r) ? array_column($r, 'ipv6') : [];
    }

    protected static function isLinkLocal(string $ip): bool
    {
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);

            return ($n & 0xFFFF0000) === (ip2long('169.254.0.0') & 0xFFFF0000) || $ip === '100.100.100.200' || ($n & 0xFF000000) === 0;
        }
        $b = @inet_pton($ip);

        return $b !== false && (ord($b[0]) === 0xFE && (ord($b[1]) & 0xC0) === 0x80 || $ip === '::' || str_starts_with(strtolower($ip), 'fd00:ec2'));
    }

    /** Réseau interne autorisé (serveur MinIO de l'administrateur). */
    public bool $allowPrivate = true;

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
        // Vérifié à chaque requête (le nom d'hôte peut changer d'adresse après l'enregistrement)
        if ($why = self::blockedReason($this->endpoint, $this->allowPrivate)) {
            throw new \RuntimeException($why);
        }
        $req = Http::timeout(120)->withoutRedirecting()->withHeaders($h);

        return $method === 'PUT' ? $req->withBody($body, $headers['Content-Type'] ?? 'application/octet-stream')->put($url) : $req->send($method, $url);
    }
}
