<?php

namespace App\Providers;

use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Charge les migrations et routes de chaque module de app/Modules.
 * Les routes d'un module sont protégées par 'module:<clé>' : si le super admin
 * désactive le module pour une société, ses API répondent 403.
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);

        // Fournisseurs de services propres aux modules (écouteurs d'événements…)
        foreach ($this->app->make(ModuleRegistry::class)->manifests() as $manifest) {
            if (! empty($manifest['provider'])) {
                $this->app->register($manifest['provider']);
            }
        }
    }

    public function boot(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        foreach ($registry->manifests() as $key => $manifest) {
            if (is_dir($manifest['path'].'/migrations')) {
                $this->loadMigrationsFrom($manifest['path'].'/migrations');
            }

            if (! $this->app->routesAreCached() && file_exists($manifest['path'].'/routes.php')) {
                Route::middleware(['api', 'installed', 'auth:sanctum', 'tenant', 'presence', 'twofactor', 'module:'.$key])
                    ->prefix('api/'.$key)
                    ->name($key.'.')
                    ->group($manifest['path'].'/routes.php');
            }
        }
    }
}
