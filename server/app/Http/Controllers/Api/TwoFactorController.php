<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Double authentification : étape de connexion (code) et réglages du compte. */
class TwoFactorController extends Controller
{
    // ---------- Connexion (sans jeton d'accès : le défi en tient lieu) ----------

    public function send(Request $request)
    {
        $d = $request->validate(['challenge' => 'required|string|size:48']);
        try {
            TwoFactor::sendLoginCode($d['challenge']);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return ['ok' => true, 'message' => 'Un nouveau code vient d\'être envoyé par e-mail.'];
    }

    public function verify(Request $request, AuthController $auth)
    {
        $d = $request->validate([
            'challenge' => 'required|string|size:48',
            'code' => 'required|string|max:20',
            'trust_device' => 'sometimes|boolean',
        ]);
        try {
            $r = TwoFactor::verifyChallenge($d['challenge'], $d['code']);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }
        $out = $auth->issue($request, $r['user'], $r['device'], $r['via'], true);
        if ($request->boolean('trust_device')) {
            $out['trust_token'] = TwoFactor::trustToken($r['user']);
        }
        if ($r['with'] === 'recovery') {
            $out['recovery_left'] = TwoFactor::recoveryLeft($r['user']);
        }

        return $out;
    }

    // ---------- Mon compte ----------

    public function status(Request $request)
    {
        $u = $request->user();

        return [
            'enabled' => TwoFactor::enabled($u),
            'method' => TwoFactor::enabled($u) ? $u->two_factor_method : null,
            'required' => TwoFactor::required($u),
            'recovery_left' => TwoFactor::enabled($u) ? TwoFactor::recoveryLeft($u) : 0,
            'confirmed_at' => $u->two_factor_confirmed_at,
            'email_hint' => TwoFactor::maskEmail($u->email),
        ];
    }

    public function setup(Request $request)
    {
        TwoFactor::ensureReady();
        $d = $request->validate(['method' => 'required|in:app,email', 'password' => 'required|string']);
        $this->checkPassword($request, $d['password']);

        return TwoFactor::beginSetup($request->user(), $d['method']);
    }

    public function confirm(Request $request, AuthController $auth)
    {
        TwoFactor::ensureReady();
        $d = $request->validate(['code' => 'required|string|max:20']);
        $u = $request->user();
        try {
            $codes = TwoFactor::confirmSetup($u, $d['code']);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }
        // Les autres sessions ouvertes sans le second facteur sont fermées
        $u->tokens()->where('id', '!=', $u->currentAccessToken()?->id)->delete();

        return ['recovery_codes' => $codes, 'user' => $auth->userPayload($u->fresh())];
    }

    public function disable(Request $request, AuthController $auth)
    {
        $d = $request->validate(['password' => 'required|string']);
        $u = $request->user();
        $this->checkPassword($request, $d['password']);
        abort_if(TwoFactor::required($u), 422, $u->is_super_admin
            ? 'La double authentification est obligatoire pour le super administrateur.'
            : 'Votre société exige la double authentification : elle ne peut pas être désactivée.');
        TwoFactor::disable($u);

        return ['ok' => true, 'user' => $auth->userPayload($u->fresh())];
    }

    public function recoveryCodes(Request $request)
    {
        $d = $request->validate(['password' => 'required|string']);
        $this->checkPassword($request, $d['password']);
        abort_unless(TwoFactor::enabled($request->user()), 422, 'Activez d\'abord la double authentification.');

        return ['recovery_codes' => TwoFactor::regenerateRecoveryCodes($request->user())];
    }

    protected function checkPassword(Request $request, string $password): void
    {
        if (! Hash::check($password, $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }
    }
}
