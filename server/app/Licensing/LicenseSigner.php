<?php

namespace App\Licensing;

use App\Models\PlatformSetting;

/**
 * Signature Ed25519 des licences délivrées par la plateforme. La clé privée ne quitte jamais
 * le serveur de l'éditeur ; les installations reçoivent la clé publique à l'activation.
 */
class LicenseSigner
{
    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_detached');
    }

    /** Clé publique officielle (épinglée dans le code). */
    public static function pinned(): string
    {
        return (string) config('erp.license_public_key');
    }

    protected static function pair(): ?array
    {
        if (! self::available()) {
            return null;
        }
        $p = (array) PlatformSetting::get('license.signing', []);
        // Tests : paire éphémère, épinglée pour la durée du test
        if (app()->runningUnitTests() && (empty($p['secret']) || ($p['public'] ?? '') !== self::pinned())) {
            $kp = sodium_crypto_sign_keypair();
            $p = ['secret' => base64_encode(sodium_crypto_sign_secretkey($kp)), 'public' => base64_encode(sodium_crypto_sign_publickey($kp))];
            PlatformSetting::put('license.signing', $p);
            config(['erp.license_public_key' => $p['public']]);
        }
        // Seule la clé officielle signe : une autre paire produirait des licences refusées par les installations
        if (empty($p['secret']) || ($p['public'] ?? '') !== self::pinned()) {
            return null;
        }

        return $p;
    }

    /** La plateforme peut-elle délivrer des licences (clé officielle déverrouillée) ? */
    public static function ready(): bool
    {
        return self::pair() !== null;
    }

    /** Déverrouille la clé officielle avec la clé éditeur (installation de la plateforme ou console super admin). */
    public static function unlock(string $editorKey): bool
    {
        $editorKey = trim($editorKey);
        if (! self::available() || ! hash_equals((string) config('erp.editor_key_hash'), hash('sha256', $editorKey))) {
            return false;
        }
        $raw = base64_decode((string) config('erp.license_signing_sealed'), true);
        if (! $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        $key = sodium_crypto_generichash('yomara-license-signing|'.$editorKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $secret = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        if ($secret === false || base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)) !== self::pinned()) {
            return false;
        }
        PlatformSetting::put('license.signing', ['secret' => base64_encode($secret), 'public' => self::pinned()]);

        return true;
    }

    public static function publicKey(): ?string
    {
        return self::pinned() ?: null;
    }

    /** Signature d'un message quelconque (paquets de mise à jour) ; null si la clé officielle n'est pas déverrouillée. */
    public static function signMessage(string $message): ?string
    {
        $p = self::pair();

        return $p ? base64_encode(sodium_crypto_sign_detached($message, base64_decode($p['secret']))) : null;
    }

    public static function releaseMessage(string $version, string $sha256): string
    {
        return 'yomara-release|'.$version.'|'.strtolower($sha256);
    }

    /** JSON canonique signé : ['payload' => chaîne JSON, 'signature' => base64]. */
    public static function sign(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $p = self::pair();

        return [
            'payload' => $json,
            'signature' => $p ? base64_encode(sodium_crypto_sign_detached($json, base64_decode($p['secret']))) : null,
            'public_key' => $p['public'] ?? null,
        ];
    }

    /**
     * Vérifie une licence avec la clé publique officielle uniquement : une clé reçue avec la licence n'est jamais
     * utilisée (sinon un faux serveur pourrait signer ses propres licences). Sans sodium : refusée.
     */
    public static function verify(string $json, ?string $signature, ?string $publicKey = null): bool
    {
        $pinned = self::pinned();
        if (! self::available() || ! $signature || $pinned === '') {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached(base64_decode($signature), $json, base64_decode($pinned));
        } catch (\Throwable) {
            return false;
        }
    }
}
