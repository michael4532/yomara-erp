<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use App\Platform\ClientComms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Double authentification.
 *
 * - Méthodes : « app » (code d'une application d'authentification) ou « email » (code envoyé à l'adresse du compte).
 * - Codes de secours : 8 codes à usage unique, en cas de téléphone perdu.
 * - Connexion : après le mot de passe, un « défi » (jeton à durée courte) attend le code ; aucun jeton d'accès n'est donné avant.
 * - Appareil de confiance : facultatif, 30 jours, invalidé si le mot de passe ou la double authentification change.
 * - Obligatoire pour le super administrateur (réglable) et pour tous les utilisateurs d'une société qui l'exige.
 */
class TwoFactor
{
    public const CHALLENGE_TTL = 600;       // 10 minutes pour saisir le code

    public const MAX_ATTEMPTS = 5;          // essais par défi

    public const TRUST_DAYS = 30;

    public const RECOVERY_COUNT = 8;

    public static function enabled(User $u): bool
    {
        return in_array($u->two_factor_method ?? null, ['app', 'email'], true) && ($u->two_factor_confirmed_at ?? null) !== null;
    }

    /** La double authentification est-elle exigée pour cet utilisateur ? */
    public static function required(User $u): bool
    {
        if ($u->is_super_admin && config('erp.two_factor.super_admin', true)) {
            return true;
        }
        $c = $u->company_id ? Company::withoutGlobalScopes()->find($u->company_id) : null;

        return (bool) ($c?->settings['require_2fa'] ?? false);
    }

    /** À configurer avant de pouvoir utiliser l'application. */
    public static function setupRequired(User $u): bool
    {
        // Base pas encore mise à jour (fichiers déposés sans « Mettre à jour la base ») : pas d'obligation,
        // sinon le super administrateur ne pourrait plus atteindre le bouton qui met la base à jour.
        return self::ready() && self::required($u) && ! self::enabled($u);
    }

    protected static ?bool $ready = null;

    /** Tables et colonnes de la double authentification présentes. */
    public static function ready(): bool
    {
        return self::$ready ??= rescue(fn () => \Illuminate\Support\Facades\Schema::hasTable('two_factor_pending')
            && \Illuminate\Support\Facades\Schema::hasColumn('users', 'two_factor_last_step'), false, false);
    }

    /** Erreur lisible quand la base n'est pas à jour. */
    public static function ensureReady(): void
    {
        if (! self::ready()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'code' => 'database_outdated',
                'message' => 'La base de données de ce serveur n\'est pas à jour : mettez-la à jour (Super admin → Système & serveur), puis recommencez.',
            ], 409));
        }
    }

    // ---------- Défi de connexion ----------

    /** Crée le défi après un mot de passe correct. Avec la méthode e-mail, le code part tout de suite. */
    public static function challenge(User $u, ?string $device, string $via): array
    {
        $token = Str::random(48);
        self::put(self::key($token), 'challenge', $u->id, ['user' => $u->id, 'attempts' => 0, 'device' => $device, 'via' => $via, 'sent' => 0, 'code' => null], self::CHALLENGE_TTL);
        if ($u->two_factor_method === 'email') {
            self::sendLoginCode($token);
        }
        ActivityLog::record('auth.2fa.challenge', $u, ['via' => $via]);

        return [
            'two_factor' => true,
            'challenge' => $token,
            'method' => $u->two_factor_method,
            'email_hint' => self::maskEmail($u->email),
            'expires_in' => self::CHALLENGE_TTL,
            'trust_days' => self::TRUST_DAYS,
        ];
    }

    public static function challengeData(string $token): ?array
    {
        return strlen($token) === 48 ? self::get(self::key($token)) : null;
    }

    /** Envoie (ou renvoie) le code par e-mail pour un défi. */
    public static function sendLoginCode(string $token): void
    {
        $ch = self::challengeData($token) ?? throw new \DomainException('Code expiré : reconnectez-vous.');
        $u = User::find($ch['user']) ?? throw new \DomainException('Compte introuvable.');
        if ($u->two_factor_method !== 'email') {
            throw new \DomainException('Ce compte utilise une application d\'authentification : saisissez le code qu\'elle affiche, ou un code de secours.');
        }
        if ($ch['sent'] >= 5) {
            throw new \DomainException('Trop d\'envois : reconnectez-vous dans quelques minutes.');
        }
        $code = self::randomCode();
        $ch['code'] = hash('sha256', $code);
        $ch['sent']++;
        self::put(self::key($token), 'challenge', $ch['user'], $ch, null);
        self::mailCode($u, $code, 'connexion');
    }

    /**
     * Vérifie le code d'un défi. Renvoie l'utilisateur, ou lève une erreur lisible.
     * Accepte : code de l'application (méthode app), code reçu par e-mail (méthode email), code de secours.
     */
    public static function verifyChallenge(string $token, string $code): array
    {
        $ch = self::challengeData($token) ?? throw new \DomainException('Code expiré : reconnectez-vous.');
        $u = User::find($ch['user']);
        if (! $u || ! $u->is_active) {
            self::forget(self::key($token));
            throw new \DomainException('Ce compte est désactivé.');
        }
        if ($ch['attempts'] >= self::MAX_ATTEMPTS) {
            self::forget(self::key($token));
            throw new \DomainException('Trop d\'essais : reconnectez-vous.');
        }
        $ch['attempts']++;
        self::put(self::key($token), 'challenge', $ch['user'], $ch, null);

        $ok = self::checkCode($u, $code, $ch['code']) ?? (self::useRecoveryCode($u, $code) ? 'recovery' : null);
        if (! $ok) {
            ActivityLog::record('auth.2fa.failed', $u);
            $left = self::MAX_ATTEMPTS - $ch['attempts'];
            throw new \DomainException('Code incorrect.'.($left > 0 ? ' Encore '.$left.' essai(s).' : ' Reconnectez-vous.'));
        }
        self::forget(self::key($token));
        ActivityLog::record('auth.2fa.passed', $u, ['with' => $ok]);

        return ['user' => $u, 'device' => $ch['device'], 'via' => $ch['via'], 'with' => $ok];
    }

    /** Code de l'application d'authentification (connexion depuis une application ancienne). */
    public static function verifyAppCode(User $u, string $code): bool
    {
        return self::checkCode($u, $code, null) === 'app';
    }

    /** 'app' ou 'email' si le code (hors code de secours) est bon, sinon null. */
    protected static function checkCode(User $u, string $code, ?string $emailHash): ?string
    {
        $digits = preg_replace('/\D/', '', $code);
        if ($u->two_factor_method === 'app' && $u->two_factor_secret) {
            $step = Totp::verify(Crypt::decryptString($u->two_factor_secret), $digits);
            // Un code déjà utilisé ne sert pas deux fois (vol par-dessus l'épaule, interception)
            $last = (int) $u->two_factor_last_step;
            if ($step !== null && $step > $last) {
                $u->forceFill(['two_factor_last_step' => $step])->saveQuietly();

                return 'app';
            }
        }
        if ($u->two_factor_method === 'email' && $emailHash && strlen($digits) === 6 && hash_equals($emailHash, hash('sha256', $digits))) {
            return 'email';
        }

        return null;
    }

    // ---------- Activation ----------

    /** Prépare l'activation : secret à scanner (app) ou code envoyé (email). Rien n'est actif avant la confirmation. */
    public static function beginSetup(User $u, string $method): array
    {
        if ($method === 'app') {
            $secret = Totp::secret();
            self::put('2fa:setup:'.$u->id, 'setup', $u->id, ['method' => 'app', 'secret' => Crypt::encryptString($secret)], 3600);

            return ['method' => 'app', 'secret' => $secret, 'uri' => Totp::uri($secret, $u->email, (string) config('erp.name', 'Yomara ERP'))];
        }
        $code = self::randomCode();
        self::put('2fa:setup:'.$u->id, 'setup', $u->id, ['method' => 'email', 'code' => hash('sha256', $code), 'attempts' => 0], 900);
        self::mailCode($u, $code, 'activation');

        return ['method' => 'email', 'email_hint' => self::maskEmail($u->email)];
    }

    /** Confirme l'activation avec un premier code ; renvoie les codes de secours (affichés une seule fois). */
    public static function confirmSetup(User $u, string $code): array
    {
        $p = self::get('2fa:setup:'.$u->id) ?? throw new \DomainException('Activation expirée (plus d\'une heure) : touchez « Retour » puis recommencez.');
        $digits = preg_replace('/\D/', '', $code);
        if ($p['method'] === 'app') {
            $step = Totp::verify(Crypt::decryptString($p['secret']), $digits);
            if ($step === null) {
                throw new \DomainException('Code incorrect. Vérifiez que l\'heure du téléphone est automatique, puis saisissez le code affiché.');
            }
            $u->forceFill(['two_factor_last_step' => $step])->saveQuietly();
        } else {
            $p['attempts'] = ($p['attempts'] ?? 0) + 1;
            self::put('2fa:setup:'.$u->id, 'setup', $u->id, $p, null);
            if ($p['attempts'] > self::MAX_ATTEMPTS) {
                self::forget('2fa:setup:'.$u->id);
                throw new \DomainException('Trop d\'essais : recommencez l\'activation.');
            }
            if (! hash_equals($p['code'], hash('sha256', $digits))) {
                throw new \DomainException('Code incorrect.');
            }
        }
        self::forget('2fa:setup:'.$u->id);
        $codes = self::newRecoveryCodes();
        $u->forceFill([
            'two_factor_method' => $p['method'],
            'two_factor_secret' => $p['method'] === 'app' ? $p['secret'] : null,
            'two_factor_recovery_codes' => self::storeCodes($codes),
            'two_factor_confirmed_at' => now(),
        ])->saveQuietly();
        ActivityLog::record('auth.2fa.enabled', $u, ['method' => $p['method']]);

        return $codes;
    }

    public static function disable(User $u, string $reason = 'user'): void
    {
        $u->forceFill(['two_factor_method' => null, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->saveQuietly();
        ActivityLog::record($reason === 'admin' ? 'auth.2fa.reset' : 'auth.2fa.disabled', $u);
    }

    public static function regenerateRecoveryCodes(User $u): array
    {
        $codes = self::newRecoveryCodes();
        $u->forceFill(['two_factor_recovery_codes' => self::storeCodes($codes)])->saveQuietly();
        ActivityLog::record('auth.2fa.recovery_regenerated', $u);

        return $codes;
    }

    public static function recoveryLeft(User $u): int
    {
        return count(self::codes($u));
    }

    // ---------- Appareil de confiance ----------

    public static function trustToken(User $u): string
    {
        $exp = now()->addDays(self::TRUST_DAYS)->timestamp;

        return $u->id.'.'.$exp.'.'.self::trustSig($u, $exp);
    }

    public static function trusted(User $u, ?string $token): bool
    {
        if (! $token || ! preg_match('/^(\d+)\.(\d{10})\.([a-f0-9]{64})$/', $token, $m) || (int) $m[1] !== $u->id || (int) $m[2] < time()) {
            return false;
        }

        return hash_equals(self::trustSig($u, (int) $m[2]), $m[3]);
    }

    /** Lié au mot de passe et à la date d'activation : changer l'un des deux retire la confiance à tous les appareils. */
    protected static function trustSig(User $u, int $exp): string
    {
        return hash_hmac('sha256', $u->id.'|'.$exp.'|'.$u->two_factor_confirmed_at?->timestamp.'|'.$u->password, (string) config('app.key'));
    }

    // ---------- Outils ----------

    protected static function key(string $token): string
    {
        return '2fa:ch:'.hash('sha256', $token);
    }

    // Stockage en base (table two_factor_pending), contenu chiffré : indépendant du cache de l'hébergement

    /** $ttl null : garde l'échéance existante. */
    protected static function put(string $key, string $kind, int $userId, array $data, ?int $ttl): void
    {
        $h = hash('sha256', $key);
        $row = ['kind' => $kind, 'user_id' => $userId, 'data' => Crypt::encryptString(json_encode($data)), 'updated_at' => now()];
        if ($ttl !== null) {
            $row['expires_at'] = now()->addSeconds($ttl);
        }
        if (DB::table('two_factor_pending')->where('token_hash', $h)->update($row) === 0) {
            DB::table('two_factor_pending')->insert($row + ['token_hash' => $h, 'created_at' => now(), 'expires_at' => now()->addSeconds($ttl ?? self::CHALLENGE_TTL)]);
        }
        // Ménage des défis et activations expirés
        if (random_int(1, 20) === 1) {
            DB::table('two_factor_pending')->where('expires_at', '<', now()->subDay())->delete();
        }
    }

    protected static function get(string $key): ?array
    {
        $r = DB::table('two_factor_pending')->where('token_hash', hash('sha256', $key))->where('expires_at', '>', now())->first();
        if (! $r) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($r->data), true) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function forget(string $key): void
    {
        DB::table('two_factor_pending')->where('token_hash', hash('sha256', $key))->delete();
    }

    /** Pour les tests : contenu d'un défi ou d'une activation en cours. */
    public static function pending(string $key): ?array
    {
        return self::get($key);
    }

    protected static function randomCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    protected static function newRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $s = '';
            for ($j = 0; $j < 8; $j++) {
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($s, 0, 4).'-'.substr($s, 4);
        }

        return $codes;
    }

    protected static function normCode(string $c): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c));
    }

    protected static function storeCodes(array $codes): string
    {
        return Crypt::encryptString(json_encode(array_map(fn ($c) => hash('sha256', self::normCode($c)), $codes)));
    }

    protected static function codes(User $u): array
    {
        if (! $u->two_factor_recovery_codes) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($u->two_factor_recovery_codes), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    protected static function useRecoveryCode(User $u, string $code): bool
    {
        $n = self::normCode($code);
        if (strlen($n) !== 8) {
            return false;
        }
        $h = hash('sha256', $n);
        $codes = self::codes($u);
        foreach ($codes as $i => $c) {
            if (hash_equals($c, $h)) {
                unset($codes[$i]);
                $u->forceFill(['two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes)))])->saveQuietly();
                ActivityLog::record('auth.2fa.recovery_used', $u, ['left' => count($codes)]);

                return true;
            }
        }

        return false;
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('•', max(2, min(6, mb_strlen($local) - 1))).'@'.$domain;
    }

    protected static function mailCode(User $u, string $code, string $purpose): void
    {
        $app = e(config('erp.name', 'Yomara ERP'));
        $title = $purpose === 'activation' ? 'Activer la double authentification' : 'Votre code de connexion';
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1530;max-width:560px;margin:0 auto;padding:24px">'
            .'<h2 style="margin:0 0 12px">'.$title.'</h2>'
            .'<p style="line-height:1.6">Bonjour '.e($u->name).',</p><p style="line-height:1.6">Voici votre code '.$app.', valable 10 minutes :</p>'
            .'<p style="margin:22px 0;font-size:32px;font-weight:800;letter-spacing:8px">'.$code.'</p>'
            .'<p style="color:#64748B;font-size:13px;line-height:1.6">Ne communiquez ce code à personne, même à quelqu\'un qui se présente comme le support. '
            .'Si vous n\'êtes pas à l\'origine de cette demande, changez votre mot de passe.</p></div>';
        $loc = in_array($u->locale ?? null, Tr::LOCALES, true) ? $u->locale : app()->getLocale();
        if ($loc !== 'fr') {
            $html = HtmlTranslator::translate($html, $loc);
        }
        $subject = $code.' — '.($loc !== 'fr' ? Tr::f($title, [], $loc) : $title);
        rescue(fn () => ClientComms::mail($u->email, $u->name, $subject, $html, "$title : $code (valable 10 minutes)"), null, true);
    }
}
