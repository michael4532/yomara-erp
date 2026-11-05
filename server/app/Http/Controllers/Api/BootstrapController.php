<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Support\ModuleRegistry;
use Illuminate\Http\Request;

/**
 * Tout ce dont l'app a besoin au démarrage (et met en cache pour le hors ligne) :
 * société, modules activés, permissions, entités synchronisables.
 */
class BootstrapController extends Controller
{
    public function __invoke(Request $request, ModuleRegistry $registry)
    {
        $company = app('tenant');
        $user = $request->user();
        $companyModules = $company->enabledModules();
        $registry->ensureSetup($company, $companyModules);
        $enabled = $user->allowedModules($companyModules); // modules masqués pour cet utilisateur retirés

        $modules = Module::orderBy('sort')->get()->map(fn (Module $m) => [
            'key' => $m->key,
            'name' => $m->name,
            'category' => $m->category,
            'icon' => $m->icon,
            'description' => $m->description,
            'available' => $m->is_available,
            'enabled' => in_array($m->key, $enabled, true),
        ]);

        return [
            'stamp' => self::stamp($company, $user),
            'subscription' => rescue(fn () => \App\Platform\SubscriptionLifecycle::banner($company), null, false),
            'app' => ['name' => config('erp.name'), 'version' => config('erp.version'), 'mode' => config('erp.mode'),
                // logiciels à télécharger (bandeau « Nouvelle application » des versions installées)
                'downloads' => (object) array_filter(rescue(fn () => \App\Http\Controllers\LandingController::downloads(), [], false)),
                'source_url' => url('/telecharger/source'),
                // Super admin : mises à jour de la base en attente (fichiers déposés, pas encore « Appliquer ») → bandeau partout
                'pending_migrations' => $user->is_super_admin ? rescue(fn () => app(\App\Platform\SetupChecklist::class)->pendingMigrations(), 0, false) : 0,
                'push' => \App\Support\Push::enabled(),
                'google_signin' => (bool) config('erp.google_signin.enabled'),
                // Installation sous licence : état affiché en bandeau aux administrateurs
                'license' => config('erp.mode') === 'single' && \App\Licensing\LicenseClient::enforced() ? rescue(fn () => \Illuminate\Support\Arr::only(\App\Licensing\LicenseClient::info(), ['status', 'error', 'next_expiry', 'valid_until']), null, false) : null],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'ui_prefs' => (object) ($user->ui_prefs ?? []),
                'role' => $user->role?->name,
                'role_id' => $user->role_id,
                'is_super_admin' => $user->is_super_admin,
                'onboarded' => (bool) $user->onboarded_at,
                'permissions' => $user->permissions(),
                'denied_permissions' => $user->deniedPermissions(),
                // Multi-agences : agences accessibles (null = toutes) et agence de travail par défaut
                'branch_ids' => \App\Support\BranchScope::allowedFor($user, $company),
            ],
            'branches' => $company->hasModule('agences') ? \App\Modules\Agences\Models\Branch::where('active', true)->orderByDesc('is_head_office')->orderBy('name')
                ->get(['id', 'name', 'code', 'city', 'warehouse_id', 'cash_account_id', 'is_head_office'])->values() : [],
            'company' => array_replace($company->only(['id', 'name', 'email', 'phone', 'address', 'city', 'country', 'currency', 'tax_id', 'trade_register', 'logo', 'status', 'trial_ends_at', 'subscription_ends_at', 'settings']), ['settings' => $company->settingsFor($user)]),
            'modules' => $modules,
            'enabled_modules' => $enabled,
            // Modules achetés ou à l'essai qui arrivent à échéance dans les 14 jours (bandeau « Renouveler »)
            'expiring_modules' => \App\Models\CompanyModule::where('company_id', $company->id)->where('enabled', true)->whereIn('source', ['purchase', 'trial'])
                ->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(14))->orderBy('expires_at')->get()
                ->map(fn ($cm) => ['key' => $cm->module_key, 'name' => $modules->firstWhere('key', $cm->module_key)['name'] ?? $cm->module_key, 'expires_at' => $cm->expires_at->toIso8601String(),
                    'source' => $cm->source, 'billing' => $cm->billing, 'days_left' => max(0, (int) now()->startOfDay()->diffInDays($cm->expires_at->copy()->startOfDay(), false))])->values(),
            'sync_entities' => array_keys($registry->syncEntities($enabled)),
            'server_time' => now()->toIso8601String(),
            // Décalage horaire de la société (minutes) : l'appareil calcule l'heure locale de la société, quel que soit son propre fuseau
            'utc_offset' => now($company->settings['timezone'] ?? config('app.timezone'))->utcOffset(),
        ];
    }

    /** Empreinte de ce qui change le menu : modules, formule, abonnement, droits. L'app se recharge quand elle change. */
    public static function stamp(\App\Models\Company $company, \App\Models\User $user): string
    {
        $mods = $user->allowedModules($company->enabledModules());
        sort($mods);

        return substr(sha1(json_encode([$mods, $company->plan_id, $company->status, (string) $company->subscription_ends_at, $user->role_id, $user->permissions(), $user->deniedPermissions()])), 0, 16);
    }
}
