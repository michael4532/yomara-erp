<?php

namespace App\Licensing;

use App\Models\Module;
use App\Models\User;
use App\Support\Installation;
use Illuminate\Support\Facades\Http;

/**
 * Côté installation (mode single) : activation de la clé sur cet appareil, vérification périodique
 * auprès de la plateforme et modules autorisés. Fonctionne hors ligne pendant la durée de validité
 * de la dernière réponse signée (30 jours).
 */
class LicenseClient
{
    public static function statePath(): string
    {
        return storage_path('app/license.json');
    }

    public static function state(): array
    {
        $p = self::statePath();

        return is_file($p) ? (json_decode((string) @file_get_contents($p), true) ?: []) : [];
    }

    protected static function save(array $s): void
    {
        @mkdir(dirname(self::statePath()), 0775, true);
        file_put_contents(self::statePath(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        static::$cache = null;
    }

    public static function server(): string
    {
        return rtrim((string) config('erp.license_server') ?: 'https://erp.yomaratechnologies.com', '/');
    }

    public static function key(): ?string
    {
        return self::state()['key'] ?? (config('erp.license_key') ?: null);
    }

    /** La licence s'applique-t-elle à cette installation ? */
    public static function enforced(): bool
    {
        if (config('erp.mode') !== 'single' || ! config('erp.license_enforce')) {
            return false;
        }
        if (self::key()) {
            return true;
        }
        // Installations antérieures à la 0.98 sans clé : laissées ouvertes
        $lock = Installation::lockFile();
        if (is_file($lock)) {
            $v = json_decode((string) @file_get_contents($lock), true)['version'] ?? '0';

            return version_compare((string) $v, '0.98.0', '>=');
        }

        return true;
    }

    /** Empreinte de l'appareil : identifiant machine (ou nom d'hôte) et dossier d'installation. */
    public static function fingerprint(): string
    {
        return hash('sha256', implode('|', [self::machineId() ?: php_uname('n'), PHP_OS_FAMILY, realpath(base_path()) ?: base_path()]));
    }

    public static function machineId(): ?string
    {
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $f) {
            if (@is_readable($f) && ($v = trim((string) @file_get_contents($f)))) {
                return $v;
            }
        }
        $can = function_exists('shell_exec') && ! in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        if ($can && PHP_OS_FAMILY === 'Darwin') {
            if (preg_match('/"IOPlatformUUID"\s*=\s*"([^"]+)"/', (string) @shell_exec('ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null'), $m)) {
                return $m[1];
            }
        }
        if ($can && PHP_OS_FAMILY === 'Windows') {
            if (preg_match('/MachineGuid\s+REG_SZ\s+(\S+)/i', (string) @shell_exec('reg query HKLM\\SOFTWARE\\Microsoft\\Cryptography /v MachineGuid 2>nul'), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    public static function device(?string $company = null): array
    {
        $users = 0;
        try {
            $users = User::where('is_active', true)->count();
        } catch (\Throwable) {
        }

        return [
            'name' => php_uname('n'),
            'os' => PHP_OS_FAMILY,
            'os_version' => php_uname('r'),
            'arch' => php_uname('m'),
            'php' => PHP_VERSION,
            'db' => config('database.default'),
            'url' => config('app.url'),
            'version' => config('erp.version'),
            'company' => $company ?? (rescue(fn () => \App\Models\Company::query()->value('name'), null, false)),
            'users' => $users,
        ];
    }

    /** Active la clé sur cet appareil. Lève une exception avec un message lisible en cas de refus. */
    public static function activate(string $key, ?string $company = null): array
    {
        $key = LicenseIssuer::normalize($key);
        try {
            $r = Http::timeout(20)->acceptJson()->post(self::server().'/api/license/activate', [
                'key' => $key, 'fingerprint' => self::fingerprint(), 'device' => self::device($company),
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Impossible de joindre le serveur des licences ('.self::server().'). Vérifiez la connexion internet de cet appareil.');
        }
        if (! $r->successful()) {
            throw new \RuntimeException((string) ($r->json('message') ?: 'Activation refusée (code '.$r->status().').'));
        }
        $lic = (array) $r->json('license');
        if (! LicenseSigner::verify((string) ($lic['payload'] ?? ''), $lic['signature'] ?? null, $lic['public_key'] ?? null)) {
            throw new \RuntimeException('Réponse du serveur des licences invalide (signature).');
        }
        self::save([
            'key' => $key, 'payload' => $lic['payload'], 'signature' => $lic['signature'] ?? null, 'public_key' => $lic['public_key'] ?? null,
            'fingerprint' => self::fingerprint(), 'activated_at' => now()->toIso8601String(), 'checked_at' => now()->toIso8601String(), 'error' => null, 'invalid' => false,
        ]);
        app(\App\Support\ModuleRegistry::class)->flush();

        return self::info();
    }

    /** Vérification périodique (toutes les 6 h par le planificateur, ou à la demande). */
    public static function check(): array
    {
        $s = self::state();
        if (empty($s['key'])) {
            return self::info();
        }
        try {
            $r = Http::timeout(15)->acceptJson()->post(self::server().'/api/license/heartbeat', [
                'key' => $s['key'], 'fingerprint' => self::fingerprint(), 'device' => self::device(),
            ]);
        } catch (\Throwable) {
            $s['error'] = 'Serveur des licences injoignable : l\'installation continue avec la dernière licence reçue.';
            $s['last_attempt_at'] = now()->toIso8601String();
            self::save($s);

            return self::info();
        }
        $s['last_attempt_at'] = now()->toIso8601String();
        if ($r->successful()) {
            $lic = (array) $r->json('license');
            // La clé publique reste celle reçue à l'activation
            if (LicenseSigner::verify((string) ($lic['payload'] ?? ''), $lic['signature'] ?? null, $s['public_key'] ?? null)) {
                $s = array_merge($s, ['payload' => $lic['payload'], 'signature' => $lic['signature'] ?? null, 'checked_at' => now()->toIso8601String(), 'error' => null, 'invalid' => false]);
            } else {
                $s['error'] = 'Réponse du serveur des licences invalide (signature).';
            }
        } elseif (in_array($r->status(), [403, 404, 409], true)) {
            $s['invalid'] = true;
            $s['error'] = (string) ($r->json('message') ?: 'Licence refusée.');
        } else {
            $s['error'] = 'Le serveur des licences a répondu '.$r->status().' : nouvel essai plus tard.';
        }
        self::save($s);
        app(\App\Support\ModuleRegistry::class)->flush();

        return self::info();
    }

    public static function due(): bool
    {
        $s = self::state();
        if (empty($s['key'])) {
            return false;
        }
        $last = $s['last_attempt_at'] ?? $s['checked_at'] ?? null;

        return ! $last || \Carbon\Carbon::parse($last)->lt(now()->subHours(6));
    }

    protected static ?array $cache = null;

    /** Contenu vérifié de la dernière licence reçue, ou null. */
    public static function payload(): ?array
    {
        if (static::$cache !== null) {
            return static::$cache ?: null;
        }
        $s = self::state();
        $p = null;
        if (! empty($s['payload']) && LicenseSigner::verify((string) $s['payload'], $s['signature'] ?? null, $s['public_key'] ?? null)) {
            $p = json_decode((string) $s['payload'], true) ?: null;
            if ($p && (($p['key'] ?? null) !== ($s['key'] ?? null) || (! empty($p['fingerprint']) && $p['fingerprint'] !== self::fingerprint()))) {
                $p = null; // licence copiée depuis une autre installation
            }
        }
        static::$cache = $p ?: [];

        return $p;
    }

    /** Modules autorisés par la licence, ou null si la licence ne s'applique pas. */
    public static function allowedModules(): ?array
    {
        if (! self::enforced()) {
            return null;
        }
        $p = self::payload();
        $s = self::state();
        // Pas encore relié au compte en ligne : fonctions de base gratuites
        if (empty($s['key'])) {
            return self::baseModules();
        }
        $valid = $p && empty($s['invalid']) && in_array($p['status'] ?? '', ['active', 'unused'], true)
            && \Carbon\Carbon::parse($p['valid_until'] ?? '2000-01-01')->isFuture();
        if (! $valid) {
            return self::baseModules();
        }
        $exp = (array) ($p['expiries'] ?? []);

        $bought = array_filter((array) ($p['enabled'] ?? []), fn ($k) => empty($exp[$k]) || \Carbon\Carbon::parse($exp[$k])->isFuture());

        return array_values(array_unique(array_merge(self::baseModules(), $bought)));
    }

    /** Modules gratuits d'une installation (formule Gratuite) : toujours disponibles, sans compte. */
    public static function baseModules(): array
    {
        $free = rescue(fn () => Module::where(fn ($q) => $q->where('is_core', true)->orWhere('billing_mode', 'free'))->pluck('key')->all(), [], false);

        return array_values(array_unique(array_merge($free, \App\Support\PricingGrid::FREE)));
    }

    /** Relie l'installation au compte en ligne (e-mail et mot de passe de l'administrateur) : une clé est créée et activée. */
    public static function link(string $email, string $password, ?string $company = null): array
    {
        try {
            $r = Http::timeout(20)->acceptJson()->post(self::server().'/api/license/link', [
                'email' => $email, 'password' => $password, 'fingerprint' => self::fingerprint(), 'device' => self::device($company),
            ]);
        } catch (\Throwable) {
            throw new \RuntimeException('Impossible de joindre '.self::server().'. Vérifiez la connexion internet de cet appareil.');
        }
        if (! $r->successful()) {
            throw new \RuntimeException((string) ($r->json('message') ?: 'Liaison refusée (code '.$r->status().').'));
        }
        $key = (string) $r->json('key');

        return self::activate($key, $company);
    }

    public static function info(): array
    {
        $s = self::state();
        $p = self::payload();
        $allowed = self::allowedModules();
        $names = rescue(fn () => Module::pluck('name', 'key'), collect(), false);
        $core = rescue(fn () => Module::where('is_core', true)->pluck('key')->all(), [], false);
        $exp = (array) ($p['expiries'] ?? []);
        $status = match (true) {
            ! self::enforced() => 'not_required',
            empty($s['key']) => 'free',
            ! empty($s['invalid']) => 'refused',
            ! $p => 'missing',
            in_array($p['status'] ?? '', ['suspended', 'revoked'], true) => $p['status'],
            \Carbon\Carbon::parse($p['valid_until'] ?? '2000-01-01')->isPast() => 'expired',
            default => 'valid',
        };

        return [
            'enforced' => self::enforced(),
            'linked' => ! empty($s['key']),
            'status' => $status,
            'key' => isset($s['key']) ? substr($s['key'], 0, 9).'…'.substr($s['key'], -5) : null,
            'company_name' => $p['company_name'] ?? null,
            'target' => $p['target'] ?? null,
            'license_status' => $p['status'] ?? null,
            'server' => self::server(),
            'device' => php_uname('n'),
            'activated_at' => $s['activated_at'] ?? null,
            'checked_at' => $s['checked_at'] ?? null,
            'valid_until' => $p['valid_until'] ?? null,
            'next_expiry' => $p['next_expiry'] ?? null,
            'error' => $s['error'] ?? null,
            'modules' => collect($allowed ?? [])->reject(fn ($k) => in_array($k, $core, true))->map(fn ($k) => ['key' => $k, 'name' => $names[$k] ?? $k, 'expires_at' => $exp[$k] ?? null])->values(),
            'buy_url' => self::server().'/app/',
        ];
    }
}
