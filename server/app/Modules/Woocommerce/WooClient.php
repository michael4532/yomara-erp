<?php

namespace App\Modules\Woocommerce;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/** API REST WooCommerce v3 : authentification par clé (Basic sur HTTPS, ou paramètres d'URL si l'hébergeur supprime l'en-tête). */
class WooClient
{
    public function __construct(public string $url, protected string $key, protected string $secret, public bool $queryAuth = false) {}

    public static function for(object $store): self
    {
        $cr = json_decode(Crypt::decryptString($store->credentials), true) ?: [];

        return new self($store->url, (string) ($cr['key'] ?? ''), (string) ($cr['secret'] ?? ''), (bool) $store->query_auth);
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $p = parse_url($url);
        $path = preg_replace('#/(wp-admin|wp-json|wp-login\.php|shop|boutique)(/.*)?$#i', '', (string) ($p['path'] ?? ''));

        return rtrim(strtolower($p['scheme'] ?? 'https').'://'.strtolower($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '').$path, '/');
    }

    public function call(string $method, string $path, array $data = []): Response
    {
        $u = $this->url.'/wp-json/wc/v3/'.ltrim($path, '/');
        // boutique sur un serveur public uniquement (pas d'accès au réseau interne), adresse IP figée, sans redirection
        try {
            $pin = \App\Support\UrlGuard::pinned($u);
        } catch (\DomainException $e) {
            throw new WooException($e->getMessage());
        }
        $req = Http::timeout(40)->connectTimeout(10)->acceptJson()->withUserAgent('Yomara ERP')
            ->withOptions(['verify' => (bool) config('erp.mailbox.verify_tls', true)] + $pin);
        $auth = ['consumer_key' => $this->key, 'consumer_secret' => $this->secret];
        $opt = $method === 'GET' ? ['query' => $data + ($this->queryAuth ? $auth : [])] : ['json' => $data] + ($this->queryAuth ? ['query' => $auth] : []);
        if (! $this->queryAuth) {
            $req = $req->withBasicAuth($this->key, $this->secret);
        }
        try {
            return $req->send($method, $u, $opt);
        } catch (ConnectionException $e) {
            throw new WooException('Site injoignable ('.parse_url($this->url, PHP_URL_HOST).') : vérifiez l\'adresse et que le site est en ligne.');
        } catch (\Illuminate\Http\Client\RequestException $e) {
            throw new WooException('Site injoignable ('.parse_url($this->url, PHP_URL_HOST).') : réponse '.$e->response->status().' du réseau ou du pare-feu.');
        }
    }

    /** Appel qui lève une erreur lisible si WooCommerce répond autrement que 2xx. */
    public function json(string $method, string $path, array $data = []): array
    {
        $r = $this->call($method, $path, $data);
        if (! $r->successful()) {
            throw new WooException(self::errorOf($r), $r->status());
        }
        $j = $r->json();

        return is_array($j) ? $j : [];
    }

    public function get(string $path, array $q = []): array
    {
        return $this->json('GET', $path, $q);
    }

    public function post(string $path, array $d = []): array
    {
        return $this->json('POST', $path, $d);
    }

    public function put(string $path, array $d = []): array
    {
        return $this->json('PUT', $path, $d);
    }

    /** Toutes les pages d'une liste (100 par page, au plus $max pages). */
    public function all(string $path, array $q = [], int $max = 30): array
    {
        $out = [];
        for ($page = 1; $page <= $max; $page++) {
            $r = $this->get($path, $q + ['per_page' => 100, 'page' => $page]);
            $out = array_merge($out, $r);
            if (count($r) < 100) {
                break;
            }
        }

        return $out;
    }

    public static function errorOf(Response $r): string
    {
        $msg = $r->json('message');
        $code = (string) $r->json('code');
        if ($r->status() === 401 || in_array($code, ['woocommerce_rest_cannot_view', 'woocommerce_rest_authentication_error'], true)) {
            return 'Clé API refusée par WooCommerce : vérifiez la clé et le secret (WooCommerce › Réglages › Avancé › API REST), avec l\'accès « Lecture/Écriture ».';
        }
        if ($r->status() === 404 && ! $r->json()) {
            return 'API WooCommerce introuvable à cette adresse : WooCommerce est-il installé et les permaliens activés (Réglages › Permaliens) ?';
        }

        return $msg ? strip_tags(html_entity_decode((string) $msg)) : 'Erreur WooCommerce (HTTP '.$r->status().')';
    }
}
