<?php

namespace App\Modules\Veille;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Lecture de pages web publiques pour la veille : uniquement http(s), jamais d'adresse interne ou privée
 * (vérifiée aussi à chaque redirection), taille et durée limitées.
 */
class WebFetcher
{
    public const MAX_BYTES = 3_000_000;

    public const USER_AGENT = 'Mozilla/5.0 (compatible; YomaraVeille/1.0; +https://erp.yomaratechnologies.com)';

    /** Résolution DNS remplaçable dans les tests : fn(string $host): string[] (adresses IP). */
    public static ?\Closure $resolver = null;

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.ltrim($url, '/');
        }
        $p = parse_url($url);
        if (! $p || empty($p['host']) || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || strlen($url) > 500 || isset($p['user']) || isset($p['pass'])) {
            throw new \DomainException('Adresse web invalide : indiquez une adresse complète, par exemple https://www.exemple.com/tarifs');
        }

        return $url;
    }

    /** @return string[] */
    protected static function resolve(string $host): array
    {
        if (static::$resolver) {
            return (static::$resolver)($host);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = @gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        return $ips;
    }

    /** @return string[] adresses IP vérifiées */
    public static function assertPublic(string $url): array
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new \DomainException('Seules les pages publiques sur internet peuvent être surveillées.');
        }
        $ips = static::resolve($host);
        if (! $ips) {
            throw new \DomainException('Site introuvable : vérifiez l\'adresse « '.$host.' ».');
        }
        foreach ($ips as $ip) {
            if (! \App\Support\UrlGuard::ipAllowed((string) $ip)) {
                throw new \DomainException('Seules les pages publiques sur internet peuvent être surveillées.');
            }
        }

        return $ips;
    }

    /** @return array{status:int, body:string, ms:int, bytes:int, final_url:string, type:string} */
    public static function get(string $url, int $timeout = 20, array $headers = []): array
    {
        $url = static::normalizeUrl($url);
        $ips = static::assertPublic($url);
        $final = $url;
        // L'adresse IP vérifiée est figée pour l'appel (pas de changement de DNS entre la vérification et la connexion)
        $p = parse_url($url);
        $host = strtolower(trim((string) $p['host'], '[]'));
        $pin = [];
        if (! filter_var($host, FILTER_VALIDATE_IP) && defined('CURLOPT_RESOLVE') && static::$resolver === null) {
            $port = (int) ($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
            $pin = ['curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0])]]];
        }
        $t = microtime(true);
        try {
            $res = Http::withHeaders($headers + ['User-Agent' => self::USER_AGENT, 'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.6', 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'])
                ->timeout($timeout)->connectTimeout(8)
                ->withOptions($pin + ['allow_redirects' => ['max' => 4, 'protocols' => ['http', 'https'], 'track_redirects' => true,
                    'on_redirect' => function (RequestInterface $req, $resp, UriInterface $uri) use (&$final) {
                        static::assertPublic((string) $uri);
                        $final = (string) $uri;
                    }]])
                ->get($url);
        } catch (\DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            throw new \DomainException(str_contains($msg, 'timed out') || str_contains($msg, 'timeout') ? 'Le site n\'a pas répondu à temps.' : 'Site inaccessible ('.mb_substr(preg_replace('/\s+/', ' ', $msg), 0, 120).').');
        }
        $body = (string) $res->body();
        if (strlen($body) > self::MAX_BYTES) {
            $body = substr($body, 0, self::MAX_BYTES);
        }

        return ['status' => $res->status(), 'body' => $body, 'ms' => (int) round((microtime(true) - $t) * 1000), 'bytes' => strlen((string) $res->body()),
            'final_url' => $final, 'type' => strtolower((string) $res->header('Content-Type'))];
    }

    /** Texte lisible d'une page HTML, une ligne par bloc (titres, paragraphes, cellules, éléments de liste). */
    public static function textLines(string $html): array
    {
        $html = preg_replace('#<(script|style|noscript|svg|template|iframe|head)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? '';
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/td|/th|/section|/article|/header|/footer|/nav|/option|/a|/button|/dd|/dt)\b[^>]*>#i', "$0\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $l) {
            $l = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $l) ?? '');
            if (mb_strlen($l) < 3 || isset($out[$l])) {
                continue;
            }
            $out[$l] = true;
        }

        return array_keys($out);
    }
}
