<?php

namespace App\Support;

/**
 * Codes à usage unique basés sur le temps (RFC 6238) : ceux qu'affichent Google Authenticator, Microsoft Authenticator,
 * Authy… 6 chiffres, renouvelés toutes les 30 secondes, HMAC-SHA1. Sans dépendance externe.
 */
class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    protected const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Nouveau secret (160 bits) encodé en base32. */
    public static function secret(): string
    {
        return self::base32(random_bytes(20));
    }

    /** Adresse otpauth:// à mettre dans le QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC3986);
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::unbase32($secret);
        $hash = hash_hmac('sha1', pack('N2', $step >> 32 & 0xFFFFFFFF, $step & 0xFFFFFFFF), $key, true);
        $o = ord($hash[19]) & 0x0F;
        $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);

        return str_pad((string) ($n % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Pas de temps du code s'il est valable (tolérance d'un pas avant / après pour une horloge de téléphone décalée),
     * sinon null. Le pas sert à refuser qu'un même code soit rejoué.
     */
    public static function verify(string $secret, string $code, ?int $now = null, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $step = intdiv($now ?? time(), self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $step + $i), $code)) {
                return $step + $i;
            }
        }

        return null;
    }

    public static function base32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function unbase32(string $s): string
    {
        $s = strtoupper(preg_replace('/[\s=-]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) {
            $v = strpos(self::ALPHABET, $c);
            if ($v === false) {
                throw new \InvalidArgumentException('Secret base32 invalide.');
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
