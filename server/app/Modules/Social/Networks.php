<?php

namespace App\Modules\Social;

use App\Models\PlatformSetting;

/**
 * Catalogue des réseaux : limites de caractères, exigences de média, mode de connexion.
 * « provider » : application OAuth à paramétrer par le super administrateur (null = connexion par jeton saisi).
 */
class Networks
{
    public const ALL = [
        'facebook' => ['label' => 'Facebook', 'limit' => 63206, 'ideal' => 250, 'media' => 'optional', 'provider' => 'meta', 'color' => '#1877F2', 'links' => true],
        'instagram' => ['label' => 'Instagram', 'limit' => 2200, 'ideal' => 150, 'hashtags' => 30, 'media' => 'required', 'provider' => 'meta', 'color' => '#E1306C', 'links' => false],
        'threads' => ['label' => 'Threads', 'limit' => 500, 'ideal' => 300, 'media' => 'optional', 'provider' => 'threads', 'color' => '#101010', 'links' => true],
        'linkedin' => ['label' => 'LinkedIn', 'limit' => 3000, 'ideal' => 1300, 'media' => 'optional', 'provider' => 'linkedin', 'color' => '#0A66C2', 'links' => true],
        'x' => ['label' => 'X (Twitter)', 'limit' => 280, 'ideal' => 200, 'media' => 'optional', 'provider' => 'x', 'color' => '#000000', 'links' => true, 'url_weight' => 23],
        'tiktok' => ['label' => 'TikTok', 'limit' => 2200, 'ideal' => 150, 'title' => 90, 'media' => 'required', 'provider' => 'tiktok', 'color' => '#FE2C55', 'links' => false],
        'pinterest' => ['label' => 'Pinterest', 'limit' => 500, 'ideal' => 200, 'title' => 100, 'media' => 'image', 'provider' => 'pinterest', 'color' => '#E60023', 'links' => true],
        'youtube' => ['label' => 'YouTube', 'limit' => 5000, 'ideal' => 500, 'title' => 100, 'media' => 'video', 'provider' => 'google', 'color' => '#FF0000', 'links' => true],
        'gbp' => ['label' => 'Google Business Profile', 'limit' => 1500, 'ideal' => 300, 'media' => 'optional', 'provider' => 'google', 'color' => '#4285F4', 'links' => false],
        'telegram' => ['label' => 'Telegram', 'limit' => 4096, 'media_limit' => 1024, 'ideal' => 600, 'media' => 'optional', 'provider' => null, 'color' => '#229ED9', 'links' => true],
        'mastodon' => ['label' => 'Mastodon', 'limit' => 500, 'ideal' => 400, 'media' => 'optional', 'provider' => null, 'color' => '#6364FF', 'links' => true, 'url_weight' => 23],
        'bluesky' => ['label' => 'Bluesky', 'limit' => 300, 'ideal' => 250, 'media' => 'optional', 'provider' => null, 'color' => '#0085FF', 'links' => true],
        'sensya' => ['label' => 'Sensya', 'limit' => 2000, 'ideal' => 500, 'media' => 'optional', 'provider' => null, 'color' => '#6C3CE1', 'links' => true],
    ];

    /** Applications OAuth (une par fournisseur) : réseaux qu'elles connectent et lien vers la console développeur. */
    public const PROVIDERS = [
        'meta' => ['label' => 'Meta (Facebook et Instagram)', 'networks' => ['facebook', 'instagram'], 'console' => 'https://developers.facebook.com/apps'],
        'threads' => ['label' => 'Threads', 'networks' => ['threads'], 'console' => 'https://developers.facebook.com/apps'],
        'linkedin' => ['label' => 'LinkedIn', 'networks' => ['linkedin'], 'console' => 'https://www.linkedin.com/developers/apps'],
        'x' => ['label' => 'X (Twitter)', 'networks' => ['x'], 'console' => 'https://developer.x.com/en/portal/dashboard'],
        'tiktok' => ['label' => 'TikTok', 'networks' => ['tiktok'], 'console' => 'https://developers.tiktok.com/apps'],
        'pinterest' => ['label' => 'Pinterest', 'networks' => ['pinterest'], 'console' => 'https://developers.pinterest.com/apps'],
        'google' => ['label' => 'Google (YouTube et Google Business Profile)', 'networks' => ['youtube', 'gbp'], 'console' => 'https://console.cloud.google.com/apis/credentials'],
    ];

    public static function get(string $n): array
    {
        return self::ALL[$n] ?? throw new \DomainException('Réseau inconnu : '.$n);
    }

    public static function label(string $n): string
    {
        return self::ALL[$n]['label'] ?? $n;
    }

    /** Limite de caractères du texte pour ce réseau (Telegram : plus courte avec un média ; Sensya : réglable). */
    public static function limit(string $n, bool $withMedia = false, ?array $account = null): int
    {
        $d = self::get($n);
        if ($n === 'sensya' && ! empty($account['meta']['limit'])) {
            return (int) $account['meta']['limit'];
        }
        if ($withMedia && isset($d['media_limit'])) {
            return $d['media_limit'];
        }

        return $d['limit'];
    }

    /** Longueur telle que le réseau la compte (X et Mastodon : chaque lien vaut 23 caractères ; Bluesky : graphèmes). */
    public static function count(string $n, string $text): int
    {
        $d = self::ALL[$n] ?? [];
        if (! empty($d['url_weight'])) {
            $text = preg_replace('~https?://\S+~u', str_repeat('x', $d['url_weight']), $text);
        }
        if ($n === 'x') {
            // Les caractères asiatiques et les émojis comptent double sur X
            $wide = preg_match_all('/[\x{1100}-\x{11FF}\x{2E80}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}\x{1F000}-\x{1FAFF}]/u', $text);

            return mb_strlen($text) + (int) $wide;
        }
        if ($n === 'bluesky' && function_exists('grapheme_strlen')) {
            return (int) grapheme_strlen($text);
        }

        return mb_strlen($text);
    }

    public static function catalog(): array
    {
        $apps = self::configuredProviders();

        return collect(self::ALL)->map(fn ($d, $k) => [
            'key' => $k, 'label' => $d['label'], 'limit' => $d['limit'], 'ideal' => $d['ideal'], 'title' => $d['title'] ?? null, 'media' => $d['media'],
            'media_limit' => $d['media_limit'] ?? null, 'hashtags' => $d['hashtags'] ?? null, 'color' => $d['color'], 'links' => $d['links'],
            'connect' => $d['provider'] ? 'oauth' : 'token', 'provider' => $d['provider'], 'available' => ! $d['provider'] || in_array($d['provider'], $apps, true),
        ])->values()->all();
    }

    // ------------------------------------------------------------------ applications OAuth (réglées par le super administrateur)

    public static function app(string $provider): array
    {
        $all = (array) PlatformSetting::get('social.apps', []);
        $a = $all[$provider] ?? [];
        $secret = ! empty($a['client_secret']) ? rescue(fn () => decrypt($a['client_secret']), null, false) : null;

        return ['client_id' => $a['client_id'] ?? null, 'client_secret' => $secret];
    }

    public static function configuredProviders(): array
    {
        return array_keys(array_filter((array) PlatformSetting::get('social.apps', []), fn ($a) => ! empty($a['client_id']) && ! empty($a['client_secret'])));
    }

    public static function redirectUri(string $provider): string
    {
        return url('/oauth/social/'.$provider.'/retour');
    }
}
