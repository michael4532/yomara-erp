<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Module;
use Illuminate\Support\Facades\Schema;

/**
 * Registre des modules.
 *
 * Deux sources :
 *  - les modules livrés avec le code : app/Modules/<Nom>/module.php (is_available = true) ;
 *  - le catalogue complet (feuille de route) : config/erp_catalog.php.
 *
 * La table `modules` stocke ce que le super admin règle : activation globale, prix.
 */
class ModuleRegistry
{
    /** @var array<string,array>|null */
    protected ?array $manifests = null;

    /** @var array<int,array> */
    protected array $enabledCache = [];

    /** Manifestes des modules présents dans le code, indexés par clé. */
    public function manifests(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $this->manifests = [];
        foreach (glob(app_path('Modules/*/module.php')) ?: [] as $file) {
            $m = require $file;
            $m['path'] = dirname($file);
            $this->manifests[$m['key']] = $m;
        }
        uasort($this->manifests, fn ($a, $b) => ($a['sort'] ?? 100) <=> ($b['sort'] ?? 100));

        return $this->manifests;
    }

    public function manifest(string $key): ?array
    {
        return $this->manifests()[$key] ?? null;
    }

    /**
     * Inscrit / met à jour les modules en base (sans écraser les prix et
     * l'activation réglés par le super admin).
     */
    public function syncToDatabase(): void
    {
        $catalog = config('erp_catalog.modules', []);
        $sort = 0;

        foreach ($catalog as $key => $def) {
            $manifest = $this->manifest($key);
            $data = array_merge($def, $manifest ? array_intersect_key($manifest, array_flip(['name', 'description', 'category', 'icon', 'depends', 'is_core'])) : []);

            $module = Module::firstOrNew(['key' => $key]);
            $isNew = ! $module->exists;
            $module->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'icon' => $data['icon'] ?? null,
                'depends' => $data['depends'] ?? [],
                'is_core' => (bool) ($data['is_core'] ?? false),
                'is_available' => $manifest !== null,
                'sort' => $sort++,
            ]);
            $store = config('erp_store.modules.'.$key, []);
            $defaults = config('erp_store.defaults', []);
            if ($isNew) {
                $monthly = (float) ($data['price_monthly'] ?? 0);
                $module->is_active = true;
                $module->price_monthly = $monthly;
                $module->price_yearly = $data['price_yearly'] ?? ($monthly * 10);
                $module->price_one_time = $data['price_one_time'] ?? ($monthly * ($defaults['one_time_months'] ?? 24));
                $module->billing_mode = $monthly > 0 ? ($data['billing_mode'] ?? $defaults['billing_mode'] ?? 'subscription') : 'free';
                $module->trial_days = $data['trial_days'] ?? ($defaults['trial_days'] ?? 0);
                $module->currency = config('erp.defaults.currency');
            }
            // Fiche boutique : valeurs de départ, sans écraser les modifications du super admin
            foreach (['tagline', 'long_description', 'features', 'screenshots'] as $f) {
                if (empty($module->{$f}) && ! empty($store[$f])) {
                    $module->{$f} = $store[$f];
                }
            }
            $module->save();
            // Nouveau module : ajouté d'office aux formules « tous les modules » (Enterprise, Sur mesure)
            // Packs (espace en plus…) : achetés à part, jamais inclus d'office dans une formule
            if ($isNew && $manifest !== null && empty($data['addon']) && Schema::hasTable('plans')) {
                foreach (\App\Models\Plan::whereIn('code', ['entreprise', 'sur_mesure'])->get() as $plan) {
                    $mods = (array) ($plan->modules ?? []);
                    if ($mods && ! in_array($key, $mods, true)) {
                        $plan->modules = array_values(array_merge($mods, [$key]));
                        $plan->save();
                    }
                }
            }
        }

        // Modules présents dans le code mais absents du catalogue
        foreach ($this->manifests() as $key => $manifest) {
            if (! array_key_exists($key, $catalog)) {
                Module::updateOrCreate(['key' => $key], [
                    'name' => $manifest['name'],
                    'description' => $manifest['description'] ?? null,
                    'category' => $manifest['category'] ?? null,
                    'icon' => $manifest['icon'] ?? null,
                    'depends' => $manifest['depends'] ?? [],
                    'is_core' => (bool) ($manifest['is_core'] ?? false),
                    'is_available' => true,
                    'sort' => $sort++,
                ]);
            }
        }

        $this->flush();
    }

    /** Clés de modules utilisables par une société (dépendances résolues). */
    public function enabledFor(Company $company): array
    {
        if (isset($this->enabledCache[$company->id])) {
            return $this->enabledCache[$company->id];
        }

        if (! Schema::hasTable('modules')) {
            return [];
        }

        $modules = Module::query()->where('is_available', true)->get()->keyBy('key');
        $overrides = $company->moduleOverrides()->get()->keyBy('module_key');
        $planModules = $company->plan?->modules ?? [];
        $saas = config('erp.mode') === 'saas';
        // Installation chez le client : seuls les modules de sa licence (null = licence non exigée)
        $licensed = $saas ? null : \App\Licensing\LicenseClient::allowedModules();

        $candidate = [];
        foreach ($modules as $key => $module) {
            if ($module->is_core) {
                $candidate[$key] = true;
                continue;
            }
            if (! $module->is_active) {
                continue; // désactivé globalement par le super admin
            }

            if ($licensed !== null && ! in_array($key, $licensed, true)) {
                $candidate[$key] = false;
                continue;
            }

            $override = $overrides->get($key);
            if ($override) {
                $candidate[$key] = $override->isEffective();
                continue;
            }

            // SaaS : modules gratuits + formule. Installation unique : tout ce qui est actif (licence).
            $candidate[$key] = $saas ? ($module->billing_mode === 'free' || in_array($key, $planModules, true)) : true;
        }

        // Dépendances : un module dont une dépendance est coupée est coupé.
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($candidate as $key => $on) {
                if (! $on) {
                    continue;
                }
                foreach ($modules[$key]->depends ?? [] as $dep) {
                    if (empty($candidate[$dep])) {
                        $candidate[$key] = false;
                        $changed = true;
                        break;
                    }
                }
            }
        }

        return $this->enabledCache[$company->id] = array_keys(array_filter($candidate));
    }

    /** Entités synchronisables : ['products' => Product::class, ...] */
    public function syncEntities(?array $onlyModules = null): array
    {
        $map = [];
        foreach ($this->manifests() as $key => $m) {
            if ($onlyModules !== null && ! in_array($key, $onlyModules, true)) {
                continue;
            }
            foreach ($m['sync'] ?? [] as $entity => $class) {
                $map[$entity] = $class;
            }
        }

        return $map;
    }

    /** Toutes les permissions déclarées : ['ventes.view' => 'Voir les ventes', ...] */
    public function permissions(?array $onlyModules = null): array
    {
        $out = [];
        foreach ($this->manifests() as $key => $m) {
            if ($onlyModules !== null && ! in_array($key, $onlyModules, true)) {
                continue;
            }
            foreach ($m['permissions'] ?? [] as $perm => $label) {
                $out[$perm] = ['module' => $key, 'label' => $label];
            }
        }

        return $out;
    }

    /**
     * Initialisation propre à une société (données de départ d'un module : étapes du CRM…).
     * Le manifeste déclare 'setup' => Classe invocable (Company) et 'setup_version'.
     * Exécutée une fois par version, au premier usage du module (démarrage de l'app, routes, synchro).
     */
    public function ensureSetup(Company $company, ?array $modules = null): void
    {
        $modules ??= $this->enabledFor($company);
        $done = (array) data_get($company->settings, '_setup', []);
        $changed = false;
        foreach ($modules as $key) {
            $m = $this->manifest($key);
            if (! $m || empty($m['setup'])) {
                continue;
            }
            $version = (int) ($m['setup_version'] ?? 1);
            if ((int) ($done[$key] ?? 0) >= $version) {
                continue;
            }
            app()->call([app($m['setup']), '__invoke'], ['company' => $company]);
            $done[$key] = $version;
            $changed = true;
        }
        if ($changed) {
            $settings = $company->settings ?? [];
            $settings['_setup'] = $done;
            $company->forceFill(['settings' => $settings])->saveQuietly();
        }
    }

    public function flush(): void
    {
        $this->enabledCache = [];
    }
}
