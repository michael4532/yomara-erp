<?php

namespace App\Support;

/**
 * Adresses appelées par le serveur pour le compte d'un utilisateur (webhooks, passerelle SMS, flux RSS, IA) :
 * uniquement des serveurs publics. Bloque l'accès au serveur lui-même et au réseau interne (SSRF).
 */
class UrlGuard
{
    public static function isPublic(string $url): bool
    {
        return self::resolve($url) !== null;
    }

    /**
     * Options HTTP qui figent l'adresse IP vérifiée (contre le « DNS rebinding » : le nom ne peut plus pointer
     * ailleurs entre la vérification et l'appel) et refusent les redirections. Lève une exception si l'adresse est interne.
     */
    public static function pinned(string $url): array
    {
        $ips = self::resolve($url);
        if ($ips === null) {
            throw new \DomainException('Adresse refusée : seuls les serveurs publics (http/https) sont autorisés, pas le réseau interne.');
        }
        $p = parse_url(trim($url));
        $host = strtolower(trim((string) $p['host'], '[]'));
        $opt = ['allow_redirects' => false];
        if ($ips !== [] && ! filter_var($host, FILTER_VALIDATE_IP) && defined('CURLOPT_RESOLVE')) {
            $port = (int) ($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
            $ip = $ips[0];
            $opt['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]];
        }

        return $opt;
    }

    /** Nom d'hôte (serveur IMAP, SMTP…) : refusé s'il désigne le serveur lui-même ou le réseau interne. */
    public static function assertPublicHost(string $host): void
    {
        self::publicHostIp($host);
    }

    /**
     * Comme assertPublicHost, mais renvoie l'adresse IP vérifiée, à utiliser telle quelle pour la connexion
     * (contre le « DNS rebinding » : pas de seconde résolution du nom entre la vérification et la connexion).
     * null en tests (pas de DNS) : on se connecte alors au nom d'hôte.
     */
    public static function publicHostIp(string $host): ?string
    {
        $ips = self::resolve('https://'.trim($host, '[] '));
        if ($ips === null) {
            throw new \DomainException('Serveur refusé : '.$host.' désigne une adresse interne.');
        }

        return $ips[0] ?? null;
    }

    /** Adresses IP publiques du serveur visé, [] en tests (pas de DNS), null si l'adresse est refusée. */
    protected static function resolve(string $url): ?array
    {
        $p = parse_url(trim($url));
        if (! $p || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $host = strtolower(trim($p['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false && app()->runningUnitTests()) {
            return []; // tests : réseau simulé, pas de résolution DNS (les adresses IP littérales restent contrôlées)
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );
        if ($ips === []) {
            $ips = gethostbynamel($host) ?: [];
        }
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! self::ipAllowed((string) $ip)) {
                return null;
            }
        }

        return array_values($ips);
    }

    /**
     * Adresse IP joignable sur internet : refuse les plages privées, réservées, locales, CGNAT, métadonnées cloud,
     * et les adresses IPv6 qui cachent une IPv4 (::ffff:127.0.0.1, 64:ff9b::…, 2002:…).
     */
    public static function ipAllowed(string $ip): bool
    {
        $ip = strtolower(trim($ip, '[] '));
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin === false) {
                return false;
            }
            // IPv4 dans IPv6 : ::ffff:a.b.c.d, ::a.b.c.d, 64:ff9b::/96 (NAT64), 2002::/16 (6to4)
            if (substr($bin, 0, 12) === str_repeat("\0", 10)."\xff\xff" || substr($bin, 0, 12) === str_repeat("\0", 12)) {
                return self::ipAllowed((string) inet_ntop(substr($bin, 12)));
            }
            if (substr($bin, 0, 12) === "\x00\x64\xff\x9b".str_repeat("\0", 8)) {
                return self::ipAllowed((string) inet_ntop(substr($bin, 12)));
            }
            if (substr($bin, 0, 2) === "\x20\x02") {
                return self::ipAllowed((string) inet_ntop(substr($bin, 2, 4)));
            }
            if ($ip === '::1' || $ip === '::' || str_starts_with($ip, 'fe8') || str_starts_with($ip, 'fe9') || str_starts_with($ip, 'fea') || str_starts_with($ip, 'feb')
                || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd') || str_starts_with($ip, 'ff')) {
                return false;
            }
        }
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | (defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : 0);

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, $flags)
            && ! self::inRange($ip, ['100.64.0.0/10', '169.254.0.0/16', '127.0.0.0/8', '0.0.0.0/8', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4']);
    }

    public static function assertPublic(string $url): void
    {
        if (! self::isPublic($url)) {
            throw new \DomainException('Adresse refusée : seuls les serveurs publics (http/https) sont autorisés, pas le réseau interne.');
        }
    }

    protected static function inRange(string $ip, array $cidrs): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        foreach ($cidrs as $c) {
            [$net, $bits] = explode('/', $c);
            $mask = -1 << (32 - (int) $bits);
            if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
