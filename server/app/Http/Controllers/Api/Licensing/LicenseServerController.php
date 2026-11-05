<?php

namespace App\Http\Controllers\Api\Licensing;

use App\Http\Controllers\Controller;
use App\Licensing\LicenseIssuer;
use App\Licensing\LicenseSigner;
use Illuminate\Http\Request;

/** Points d'entrée publics appelés par les installations sous licence (plateforme SaaS uniquement). */
class LicenseServerController extends Controller
{
    public function __construct(protected LicenseIssuer $issuer)
    {
        abort_unless(config('erp.mode') === 'saas', 404);
    }

    protected function input(Request $r): array
    {
        return $r->validate([
            'key' => 'required|string|max:40',
            'fingerprint' => 'required|string|size:64',
            'device' => 'nullable|array',
            'device.*' => 'nullable',
        ]);
    }

    public function activate(Request $r)
    {
        if (! LicenseSigner::ready()) {
            return response()->json(['message' => 'La plateforme Yomara n\'a pas encore activé la signature des licences. Réessayez plus tard.'], 503);
        }
        $d = $this->input($r);
        [$code, $body] = $this->issuer->activate($d['key'], $d['fingerprint'], (array) ($d['device'] ?? []), $r->ip());

        return response()->json($body, $code);
    }

    public function heartbeat(Request $r)
    {
        if (! LicenseSigner::ready()) {
            return response()->json(['message' => 'La plateforme Yomara n\'a pas encore activé la signature des licences. Réessayez plus tard.'], 503);
        }
        $d = $this->input($r);
        [$code, $body] = $this->issuer->heartbeat($d['key'], $d['fingerprint'], (array) ($d['device'] ?? []), $r->ip());

        return response()->json($body, $code);
    }

    /**
     * Une installation se relie au compte en ligne de son administrateur : la clé est créée (ou retrouvée
     * pour le même appareil) sans que le client ait à la manipuler.
     */
    public function link(Request $r)
    {
        if (! LicenseSigner::ready()) {
            return response()->json(['message' => 'La plateforme Yomara n\'a pas encore activé la signature des licences. Réessayez plus tard.'], 503);
        }
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string', 'fingerprint' => 'required|string|size:64', 'device' => 'nullable|array']);
        // Plafond par compte, quelle que soit l'adresse IP : pas de devinette de mot de passe par cette porte
        $key = 'lic-link|'.strtolower(trim($d['email']));
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['message' => 'Trop d\'essais pour ce compte. Réessayez dans '.ceil(\Illuminate\Support\Facades\RateLimiter::availableIn($key) / 60).' minute(s).'], 429);
        }
        $user = \App\Models\User::where('email', strtolower($d['email']))->where('is_active', true)->first();
        if (! $user || ! \Illuminate\Support\Facades\Hash::check($d['password'], $user->password)) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 900);

            return response()->json(['message' => 'E-mail ou mot de passe incorrect. Utilisez le compte créé sur '.rtrim(config('app.url'), '/').'.'], 422);
        }
        \Illuminate\Support\Facades\RateLimiter::clear($key);
        $owner = \App\Models\Company::find($user->company_id);
        if (! $owner || ! ($user->is_super_admin || $user->hasPermission('core.settings.manage'))) {
            return response()->json(['message' => 'Seul un administrateur du compte peut relier une installation.'], 403);
        }
        $l = \App\Models\License::where('owner_company_id', $owner->id)->where('fingerprint', $d['fingerprint'])->where('status', '!=', 'revoked')->first();
        if (! $l) {
            $dev = (array) ($d['device'] ?? []);
            $target = match (strtolower((string) ($dev['os'] ?? ''))) { 'darwin' => 'mac', 'windows' => 'windows', default => 'server' };
            try {
                $l = $this->issuer->create($owner, $user, (string) ($dev['company'] ?? $owner->name) ?: $owner->name, $target, 'Reliée automatiquement depuis l\'installation');
            } catch (\DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // Liée tout de suite à cet appareil
        [$code, $body] = $this->issuer->activate($l->key, $d['fingerprint'], (array) ($d['device'] ?? []), $r->ip());
        if ($code !== 200) {
            return response()->json($body, $code);
        }

        return ['key' => $l->key, 'installation' => $l->company_name];
    }

    public function publicKey()
    {
        return ['public_key' => LicenseSigner::publicKey(), 'algorithm' => 'ed25519'];
    }
}
