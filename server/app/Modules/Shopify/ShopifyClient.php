<?php

namespace App\Modules\Shopify;

use App\Modules\Woocommerce\WooException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/** API Admin Shopify (REST 2024-10 + GraphQL), jeton d'une application personnalisée (shpat_…). */
class ShopifyClient
{
    public const VERSION = '2024-10';

    public function __construct(public string $shop, protected string $token) {}

    public static function for(object $store): self
    {
        $cr = json_decode(Crypt::decryptString($store->credentials), true) ?: [];

        return new self(self::domain($store->url), (string) ($cr['key'] ?? ''));
    }

    /** « maboutique », « maboutique.myshopify.com » ou une adresse complète → maboutique.myshopify.com */
    public static function domain(string $shop): string
    {
        $h = strtolower(trim($shop));
        $h = preg_replace('#^https?://#', '', $h);
        $h = explode('/', $h)[0];
        if (! str_contains($h, '.')) {
            $h .= '.myshopify.com';
        }

        return $h;
    }

    public function call(string $method, string $path, array $data = [], int $try = 0): Response
    {
        $u = str_starts_with($path, 'https://') ? $path : 'https://'.$this->shop.'/admin/api/'.self::VERSION.'/'.ltrim($path, '/');
        try {
            $r = Http::timeout(40)->connectTimeout(10)->acceptJson()->withHeaders(['X-Shopify-Access-Token' => $this->token])
                ->send($method, $u, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException) {
            throw new WooException('Boutique Shopify injoignable ('.$this->shop.').');
        } catch (\Illuminate\Http\Client\RequestException $e) {
            throw new WooException('Boutique Shopify injoignable ('.$this->shop.') : réponse '.$e->response->status().' du réseau ou du pare-feu.');
        }
        if ($r->status() === 429 && $try < 3) { // limite de débit : on patiente
            usleep((int) (max(1, (float) ($r->header('Retry-After') ?: 1)) * 1_000_000));

            return $this->call($method, $path, $data, $try + 1);
        }

        return $r;
    }

    public function json(string $method, string $path, array $data = []): array
    {
        $r = $this->call($method, $path, $data);
        if (! $r->successful()) {
            throw new WooException(self::errorOf($r), $r->status());
        }

        return (array) $r->json();
    }

    /** Liste paginée (curseur page_info de l'en-tête Link). */
    public function all(string $path, string $key, array $q = [], int $max = 40): array
    {
        $out = [];
        $url = $path;
        $query = $q + ['limit' => 250];
        for ($i = 0; $i < $max && $url; $i++) {
            $r = $this->call('GET', $url, $query);
            if (! $r->successful()) {
                throw new WooException(self::errorOf($r), $r->status());
            }
            $out = array_merge($out, (array) $r->json($key));
            $url = preg_match('/<([^>]+)>;\s*rel="next"/', (string) $r->header('Link'), $m) ? $m[1] : null;
            $query = [];
        }

        return $out;
    }

    public function graphql(string $query, array $vars = []): array
    {
        $j = $this->json('POST', 'graphql.json', ['query' => $query, 'variables' => $vars]);
        if (! empty($j['errors'])) {
            throw new WooException('Shopify : '.($j['errors'][0]['message'] ?? 'erreur GraphQL'));
        }

        return (array) ($j['data'] ?? []);
    }

    public static function errorOf(Response $r): string
    {
        if ($r->status() === 401) {
            return 'Jeton d\'accès refusé par Shopify : vérifiez le jeton de l\'API Admin (shpat_…) de votre application personnalisée.';
        }
        if ($r->status() === 403) {
            return 'Droit manquant sur l\'application Shopify : accordez read/write_products, read/write_orders, read/write_inventory et read_locations.';
        }
        if ($r->status() === 404 && ! $r->json()) {
            return 'Boutique Shopify introuvable : vérifiez l\'adresse (maboutique.myshopify.com).';
        }
        $e = $r->json('errors');

        return 'Shopify : '.(is_array($e) ? collect($e)->flatten()->implode(' ') : ($e ?: 'erreur HTTP '.$r->status()));
    }
}
