<?php

namespace App\Licensing;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\License;
use App\Models\Module;
use App\Models\ModuleOrder;
use App\Models\User;
use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/** Côté plateforme (éditeur) : génération, activation sur un appareil, vérifications périodiques. */
class LicenseIssuer
{
    public const MAX_PER_ACCOUNT = 200;

    /** Jours pendant lesquels une installation fonctionne sans pouvoir joindre la plateforme. */
    public const OFFLINE_DAYS = 30;

    public function __construct(protected ModuleRegistry $registry) {}

    public static function newKey(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $groups = [];
            for ($g = 0; $g < 4; $g++) {
                $s = '';
                for ($i = 0; $i < 5; $i++) {
                    $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $groups[] = $s;
            }
            $key = 'YMR-'.implode('-', $groups);
        } while (License::where('key', $key)->exists());

        return $key;
    }

    public static function normalize(string $key): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($key)));
    }

    public function create(Company $owner, ?User $by, string $companyName, string $target, ?string $notes = null): License
    {
        if (License::where('owner_company_id', $owner->id)->count() >= self::MAX_PER_ACCOUNT) {
            throw new \DomainException('Nombre maximal de licences atteint pour ce compte. Contactez-nous.');
        }

        return DB::transaction(function () use ($owner, $by, $companyName, $target, $notes) {
            $c = new Company([
                'name' => $companyName, 'email' => $owner->email, 'phone' => $owner->phone, 'country' => $owner->country,
                'currency' => $owner->currency, 'status' => 'license',
                'settings' => ['license_owner_id' => $owner->id],
            ]);
            $c->save();
            $l = License::create([
                'owner_company_id' => $owner->id, 'company_id' => $c->id, 'created_by' => $by?->id, 'key' => self::newKey(),
                'company_name' => $companyName, 'target' => $target, 'status' => 'unused', 'notes' => $notes,
            ]);
            $l->log('created', ['target' => $target, 'by' => $by?->name]);

            return $l;
        });
    }

    /** Modules utilisables par l'installation (dépendances résolues) et échéance de chacun. */
    public function modules(License $l): array
    {
        $company = $l->company;
        if (! $company || in_array($l->status, ['suspended', 'revoked'], true)) {
            return ['enabled' => [], 'expiries' => []];
        }
        $this->registry->flush();
        $enabled = $this->registry->enabledFor($company);
        $expiries = CompanyModule::where('company_id', $company->id)->where('enabled', true)->whereIn('module_key', $enabled)
            ->get()->mapWithKeys(fn ($cm) => [$cm->module_key => $cm->expires_at?->toIso8601String()])->all();

        return ['enabled' => array_values($enabled), 'expiries' => (object) $expiries];
    }

    public function payload(License $l): array
    {
        $m = $this->modules($l);
        $nearest = collect((array) $m['expiries'])->filter()->map(fn ($d) => \Carbon\Carbon::parse($d))->min();

        return LicenseSigner::sign([
            'v' => 1,
            'key' => $l->key,
            'license_id' => $l->id,
            'company_name' => $l->company_name,
            'target' => $l->target,
            'status' => $l->status,
            'fingerprint' => $l->fingerprint,
            'enabled' => $m['enabled'],
            'expiries' => $m['expiries'],
            'next_expiry' => $nearest?->toIso8601String(),
            'issued_at' => now()->toIso8601String(),
            'valid_until' => now()->addDays(self::OFFLINE_DAYS)->toIso8601String(),
            'server' => rtrim(config('app.url'), '/'),
        ]);
    }

    /**
     * Active (ou réactive sur le même appareil) une installation.
     *
     * @return array{0:int,1:array} code HTTP et corps
     */
    public function activate(string $key, string $fingerprint, array $device, ?string $ip): array
    {
        $l = License::where('key', self::normalize($key))->first();
        if (! $l) {
            return [404, ['message' => 'Clé de licence inconnue. Vérifiez-la dans « Mes licences » de votre compte.']];
        }
        if ($l->status === 'revoked') {
            return [403, ['message' => 'Cette licence a été révoquée.']];
        }
        if ($l->fingerprint && ! hash_equals($l->fingerprint, $fingerprint)) {
            $l->log('refused', ['device' => $device['name'] ?? null], $ip);

            return [409, ['message' => 'Cette licence est déjà activée sur un autre appareil ('.($l->device_name ?: 'inconnu').'). Libérez-la depuis « Mes licences » de votre compte, ou générez une nouvelle licence.']];
        }
        $first = ! $l->fingerprint;
        $l->fill($this->deviceFields($device, $ip) + ['fingerprint' => $fingerprint]);
        if ($l->status === 'unused') {
            $l->status = 'active';
        }
        $l->activated_at ??= now();
        $l->save();
        if ($first) {
            $l->log('activated', ['device' => $l->device_name, 'url' => $l->install_url, 'company' => $l->installed_company], $ip);
        }

        return [200, ['license' => $this->payload($l)]];
    }

    /** @return array{0:int,1:array} */
    public function heartbeat(string $key, string $fingerprint, array $device, ?string $ip): array
    {
        $l = License::where('key', self::normalize($key))->first();
        if (! $l) {
            return [404, ['message' => 'Clé de licence inconnue.', 'invalid' => true]];
        }
        if (! $l->fingerprint) {
            return [409, ['message' => 'Cette licence a été libérée : réactivez-la sur cet appareil.', 'invalid' => true, 'reactivate' => true]];
        }
        if (! hash_equals($l->fingerprint, $fingerprint)) {
            $l->log('refused', ['device' => $device['name'] ?? null], $ip);

            return [409, ['message' => 'Cette licence est utilisée sur un autre appareil.', 'invalid' => true]];
        }
        $l->fill($this->deviceFields($device, $ip))->save();

        return [200, ['license' => $this->payload($l)]];
    }

    protected function deviceFields(array $d, ?string $ip): array
    {
        return array_filter([
            'device_name' => isset($d['name']) ? mb_substr((string) $d['name'], 0, 190) : null,
            'device' => array_intersect_key($d, array_flip(['name', 'os', 'os_version', 'php', 'db', 'host', 'arch'])),
            'install_url' => isset($d['url']) ? mb_substr((string) $d['url'], 0, 250) : null,
            'installed_company' => isset($d['company']) ? mb_substr((string) $d['company'], 0, 250) : null,
            'app_version' => isset($d['version']) ? mb_substr((string) $d['version'], 0, 30) : null,
            'users_count' => isset($d['users']) ? (int) $d['users'] : null,
            'last_seen_at' => now(),
            'last_ip' => $ip,
        ], fn ($v) => $v !== null);
    }

    /** Vue d'une licence pour son propriétaire ou le super admin. */
    public function present(License $l, bool $withEvents = false): array
    {
        $m = $this->modules($l);
        $names = Module::pluck('name', 'key');
        $core = Module::where('is_core', true)->pluck('key')->all();
        $paid = ModuleOrder::where('company_id', $l->company_id)->where('status', 'paid');
        $mods = collect($m['enabled'])->reject(fn ($k) => in_array($k, $core, true))->map(fn ($k) => [
            'key' => $k, 'name' => $names[$k] ?? $k, 'expires_at' => ((array) $m['expiries'])[$k] ?? null,
        ])->values();

        return [
            'id' => $l->id, 'key' => $l->key, 'masked_key' => $l->maskedKey(), 'company_name' => $l->company_name,
            'company_id' => $l->company_id, 'owner_company_id' => $l->owner_company_id, 'owner' => $l->ownerCompany?->name,
            'target' => $l->target, 'target_label' => License::TARGETS[$l->target] ?? $l->target,
            'status' => $l->status, 'status_label' => License::STATUSES[$l->status] ?? $l->status, 'online' => $l->isOnline(),
            'device_name' => $l->device_name, 'device' => $l->device, 'install_url' => $l->install_url, 'installed_company' => $l->installed_company,
            'app_version' => $l->app_version, 'users_count' => $l->users_count, 'resets' => $l->resets,
            'activated_at' => $l->activated_at?->toIso8601String(), 'last_seen_at' => $l->last_seen_at?->toIso8601String(),
            'last_ip' => $l->last_ip, 'notes' => $l->notes, 'created_at' => $l->created_at?->toIso8601String(),
            'modules' => $mods, 'modules_count' => $mods->count(),
            'next_expiry' => $mods->pluck('expires_at')->filter()->sort()->first(),
            'revenue' => round((float) $paid->sum('amount'), 2),
            'events' => $withEvents ? $l->events()->latest('id')->limit(40)->get()->map(fn ($e) => [
                'type' => $e->type, 'label' => \App\Models\LicenseEvent::LABELS[$e->type] ?? $e->type, 'data' => $e->data, 'ip' => $e->ip, 'at' => $e->created_at?->toIso8601String(),
            ]) : null,
        ];
    }
}
