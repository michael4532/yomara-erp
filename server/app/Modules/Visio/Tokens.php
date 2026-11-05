<?php

namespace App\Modules\Visio;

/**
 * Jetons d'accès des deux infrastructures vidéo, générés côté serveur (les secrets ne quittent jamais le serveur).
 *  - Agora : AccessToken2 (« 007 »), service RTC.
 *  - ZEGOCLOUD : token04 (AES-CBC).
 */
class Tokens
{
    public const AGORA_JOIN = 1;

    public const AGORA_PUBLISH_AUDIO = 2;

    public const AGORA_PUBLISH_VIDEO = 3;

    public const AGORA_PUBLISH_DATA = 4;

    public static function agora(string $appId, string $certificate, string $channel, int $uid, int $ttl = 86400, bool $publish = true, ?int $issuedAt = null, ?int $salt = null): string
    {
        if (! preg_match('/^[0-9a-f]{32}$/i', $appId) || ! preg_match('/^[0-9a-f]{32}$/i', $certificate)) {
            throw new \InvalidArgumentException('Identifiants Agora invalides (App ID et certificat : 32 caractères hexadécimaux).');
        }
        $issuedAt ??= time();
        $salt ??= random_int(1, 99999999);
        $privileges = [self::AGORA_JOIN => $ttl];
        if ($publish) {
            $privileges += [self::AGORA_PUBLISH_AUDIO => $ttl, self::AGORA_PUBLISH_VIDEO => $ttl, self::AGORA_PUBLISH_DATA => $ttl];
        }
        ksort($privileges);
        $map = pack('v', count($privileges));
        foreach ($privileges as $k => $v) {
            $map .= pack('v', $k).pack('V', $v);
        }
        $str = fn (string $s) => pack('v', strlen($s)).$s;
        $service = pack('v', 1).$map.$str($channel).$str($uid === 0 ? '' : (string) $uid);

        $signing = hash_hmac('sha256', hash_hmac('sha256', $certificate, pack('V', $issuedAt), true), pack('V', $salt), true);
        $data = $str($appId).pack('V', $issuedAt).pack('V', $ttl).pack('V', $salt).pack('v', 1).$service;
        $signature = hash_hmac('sha256', $data, $signing, true);

        return '007'.base64_encode(zlib_encode($str($signature).$data, ZLIB_ENCODING_DEFLATE));
    }

    /** Décodage (contrôle et tests) : renvoie appId, émission, durée, canal, uid et privilèges. */
    public static function decodeAgora(string $token): array
    {
        $raw = zlib_decode(base64_decode(substr($token, 3)));
        $p = 0;
        $u16 = function () use ($raw, &$p) {
            $v = unpack('v', substr($raw, $p, 2))[1];
            $p += 2;

            return $v;
        };
        $u32 = function () use ($raw, &$p) {
            $v = unpack('V', substr($raw, $p, 4))[1];
            $p += 4;

            return $v;
        };
        $str = function () use ($raw, &$p, $u16) {
            $n = $u16();
            $s = substr($raw, $p, $n);
            $p += $n;

            return $s;
        };
        $signature = $str();
        $start = $p;
        $out = ['app_id' => $str(), 'issued_at' => $u32(), 'expire' => $u32(), 'salt' => $u32()];
        $services = $u16();
        for ($i = 0; $i < $services; $i++) {
            $out['service'] = $u16();
            $n = $u16();
            for ($j = 0; $j < $n; $j++) {
                $k = $u16();
                $out['privileges'][$k] = $u32();
            }
            $out['channel'] = $str();
            $out['uid'] = $str();
        }
        $out['signed_data'] = substr($raw, $start);
        $out['signature'] = $signature;

        return $out;
    }

    public static function zego(int $appId, string $secret, string $userId, int $ttl = 86400, string $payload = '', ?string $iv = null): string
    {
        if (! in_array(strlen($secret), [16, 24, 32], true)) {
            throw new \InvalidArgumentException('ServerSecret ZEGOCLOUD invalide (32 caractères attendus).');
        }
        $now = time();
        $data = ['app_id' => $appId, 'user_id' => $userId, 'nonce' => random_int(0, 2147483647), 'ctime' => $now, 'expire' => $now + $ttl, 'payload' => $payload];
        $plain = json_encode($data, JSON_BIGINT_AS_STRING | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $iv ??= self::randomIv();
        $cipher = ['16' => 'aes-128-cbc', '24' => 'aes-192-cbc', '32' => 'aes-256-cbc'][(string) strlen($secret)];
        $encrypted = openssl_encrypt($plain, $cipher, $secret, OPENSSL_RAW_DATA, $iv);

        return '04'.base64_encode(pack('J', $data['expire']).pack('n', strlen($iv)).$iv.pack('n', strlen($encrypted)).$encrypted);
    }

    public static function decodeZego(string $token, string $secret): array
    {
        $raw = base64_decode(substr($token, 2));
        $expire = unpack('J', substr($raw, 0, 8))[1];
        $ivLen = unpack('n', substr($raw, 8, 2))[1];
        $iv = substr($raw, 10, $ivLen);
        $len = unpack('n', substr($raw, 10 + $ivLen, 2))[1];
        $enc = substr($raw, 12 + $ivLen, $len);
        $cipher = ['16' => 'aes-128-cbc', '24' => 'aes-192-cbc', '32' => 'aes-256-cbc'][(string) strlen($secret)];

        return ['expire' => $expire] + (array) json_decode((string) openssl_decrypt($enc, $cipher, $secret, OPENSSL_RAW_DATA, $iv), true);
    }

    protected static function randomIv(): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
        $iv = '';
        for ($i = 0; $i < 16; $i++) {
            $iv .= $chars[random_int(0, 35)];
        }

        return $iv;
    }
}
