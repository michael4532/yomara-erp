<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\User;
use App\Services\CompanySetup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Identification du serveur (utilisée par l'app pour valider l'adresse saisie). */
    public function ping()
    {
        return [
            'app' => config('erp.name'),
            'product' => 'yomara-erp',
            'version' => config('erp.version'),
            'mode' => config('erp.mode'),
            'registration' => config('erp.mode') === 'saas',
            'google_signin' => (bool) config('erp.google_signin.enabled'),
            'push' => \App\Support\Push::enabled(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:100',
            'trust_token' => 'nullable|string|max:200',
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Identifiants incorrects.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Ce compte est désactivé.']);
        }

        return $this->issue($request, $user, $data['device_name'] ?? null);
    }

    /**
     * Ouvre la session, ou demande d'abord le code de double authentification (sauf appareil de confiance).
     * $passed : le code vient d'être vérifié.
     */
    public function issue(Request $request, User $user, ?string $device, string $via = 'password', bool $passed = false): array
    {
        if (! $passed && \App\Support\TwoFactor::enabled($user) && ! \App\Support\TwoFactor::trusted($user, $request->input('trust_token'))) {
            return \App\Support\TwoFactor::challenge($user, $device, $via);
        }
        // Plateforme déclarée par l'application (android, ios, windows…) : valeur courte et contrôlée
        $platform = (string) $request->header('X-Client-Platform', 'web');
        $platform = preg_match('/^[a-z]{2,15}$/', $platform) ? $platform : 'web';
        $token = $user->createToken(($device ?: 'app').' ('.$platform.')')->plainTextToken;

        $user->forceFill(['last_seen_at' => now(), 'last_platform' => $platform, 'last_ip' => $request->ip()])->saveQuietly();
        auth()->setUser($user);
        ActivityLog::record('auth.login', $user, ['platform' => $platform] + ($via !== 'password' ? ['via' => $via] : []));

        return ['token' => $token, 'user' => $this->userPayload($user)];
    }

    /**
     * « Se connecter avec Google » (facultatif) : le jeton d'identité Google est vérifié auprès de Google,
     * puis on ouvre le compte qui a déjà cette adresse e-mail. Aucun compte n'est créé ici.
     */
    public function google(Request $request)
    {
        abort_unless(config('erp.google_signin.enabled'), 404);
        $data = $request->validate(['id_token' => 'required|string|max:4096', 'device_name' => 'nullable|string|max:100', 'trust_token' => 'nullable|string|max:200']);
        $claims = static::verifyGoogleToken($data['id_token']);
        if (! $claims) {
            throw ValidationException::withMessages(['id_token' => 'Connexion Google refusée. Réessayez ou connectez-vous avec votre e-mail.']);
        }
        $user = User::where('email', strtolower((string) $claims['email']))->first();
        if (! $user) {
            throw ValidationException::withMessages(['id_token' => 'Aucun compte avec l\'adresse '.$claims['email'].'. Connectez-vous avec votre e-mail professionnel, ou demandez à votre administrateur de vous créer un compte.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['id_token' => 'Ce compte est désactivé.']);
        }

        return $this->issue($request, $user, $data['device_name'] ?? null, 'google');
    }

    /** Remplacé dans les tests. */
    public static $googleVerifier = null;

    /** Vérifie un jeton d'identité Google : signature (par Google), émetteur, destinataire, expiration, e-mail vérifié. */
    public static function verifyGoogleToken(string $idToken): ?array
    {
        try {
            $c = static::$googleVerifier
                ? (static::$googleVerifier)($idToken)
                : \Illuminate\Support\Facades\Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken])->throw()->json();
        } catch (\Throwable) {
            return null;
        }
        $ok = is_array($c)
            && in_array($c['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            && in_array($c['aud'] ?? '', (array) config('erp.google_signin.client_ids'), true)
            && (int) ($c['exp'] ?? 0) > time()
            && filter_var($c['email_verified'] ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL);

        return $ok ? $c : null;
    }

    /** Inscription d'une nouvelle société (mode SaaS uniquement). */
    public function register(Request $request, CompanySetup $setup)
    {
        abort_if(config('erp.mode') !== 'saas', 404);

        $data = $request->validate([
            'company_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:50',
            'password' => 'required|string|min:8',
            'plan' => ['nullable', 'string', \Illuminate\Validation\Rule::exists('plans', 'code')->where('is_active', true)],
            'referral' => 'nullable|string|max:20',
        ]);

        $plan = Plan::where('code', $data['plan'] ?? null)->first()
            ?? Plan::where('is_active', true)->orderBy('sort')->first();

        $email = strtolower($data['email']);
        ['user' => $user, 'company' => $company] = $setup->create(
            ['name' => $data['company_name'], 'email' => $email, 'phone' => $data['phone'] ?? null],
            ['name' => $data['name'], 'email' => $email, 'phone' => $data['phone'] ?? null, 'password' => $data['password']],
            $plan,
            $plan?->is_free || ($plan && $plan->price_monthly <= 0) ? 'active' : 'trial',
        );
        // Même suite que l'inscription sur le site : parrainage, prospect chez l'éditeur, e-mail de bienvenue
        \App\Store\Referrals::attach($company, $data['referral'] ?? null);
        \App\Services\SiteTraffic::record($request, 'signup', $plan?->code);
        \App\Services\SiteTraffic::capture('signup', ['name' => $data['name'], 'email' => $email, 'phone' => $data['phone'] ?? null, 'company' => $data['company_name'], 'tags' => array_filter([$plan?->code, 'app'])]);
        rescue(fn () => app(\App\Http\Controllers\LandingController::class)->sendWelcome($user->name, $email, '', $data['company_name'], $plan), false, false);
        rescue(fn () => \App\Platform\SubscriptionLifecycle::welcome($user->company->fresh()), null, false);

        $token = $user->createToken('app ('.$request->header('X-Client-Platform', 'web').')')->plainTextToken;

        return response()->json(['token' => $token, 'user' => $this->userPayload($user)], 201);
    }

    public function me(Request $request)
    {
        return ['user' => $this->userPayload($request->user())];
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        // Hors ligne seulement si aucun autre appareil n'est actif.
        $otherActive = $user->tokens()->where('last_used_at', '>', now()->subSeconds(config('erp.presence_window')))->exists();
        if (! $otherActive) {
            $user->forceFill(['last_seen_at' => null])->saveQuietly();
        }
        $request->attributes->set('presence.skip', true);

        return ['ok' => true];
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $user = $request->user();
        abort_if(\App\Platform\StoreDemo::isDemoUser($user), 422, 'Le mot de passe du compte de démonstration se change depuis l\'administration de la plateforme.');
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mot de passe actuel incorrect.']);
        }
        $user->update(['password' => $data['password']]);
        // Les autres appareils sont déconnectés (mot de passe changé après une fuite, un téléphone perdu…)
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return ['ok' => true];
    }

    public function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'company_id' => $user->company_id,
            'role' => $user->role?->name,
            'is_super_admin' => $user->is_super_admin,
            'onboarded' => (bool) $user->onboarded_at,
            'two_factor' => \App\Support\TwoFactor::enabled($user),
            'two_factor_method' => \App\Support\TwoFactor::enabled($user) ? $user->two_factor_method : null,
            'two_factor_required' => \App\Support\TwoFactor::setupRequired($user),
        ];
    }
}
