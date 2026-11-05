<?php

namespace App\Http\Middleware;

use App\Modules\Sites\Models\Site;
use App\Modules\Sites\PublicSiteController;
use App\Modules\Sites\SiteService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Sites vitrines servis par nom d'hôte : {adresse}.{domaine des sites} (DNS joker) ou domaine du client vérifié.
 * Les autres hôtes (l'application elle-même) passent sans changement.
 */
class ServeSiteByHost
{
    /** Avant l'installation (base absente ou injoignable), on laisse passer : l'assistant /install doit s'ouvrir. */
    protected function sitesReady(): bool
    {
        try {
            return Schema::hasTable('ws_sites');
        } catch (\Throwable) {
            return false;
        }
    }

    public function handle(Request $request, Closure $next)
    {
        $host = strtolower($request->getHost());
        $app = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === $app || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || filter_var($host, FILTER_VALIDATE_IP)
            || ! (\App\Support\Installation::isInstalled() || app()->runningUnitTests()) || ! $this->sitesReady()) {
            return $next($request);
        }
        $site = null;
        $base = SiteService::settings()['base_domain'];
        if ($base && str_ends_with($host, '.'.$base)) {
            $slug = substr($host, 0, -strlen('.'.$base));
            $site = str_contains($slug, '.') ? null : Site::withoutGlobalScopes()->where('slug', $slug)->first();
            if (! $site) {
                abort(404); // sous-domaine de site inconnu : pas l'application
            }
        } elseif ($base && $host === $base) {
            return $next($request);
        } else {
            $d = preg_replace('/^www\./', '', $host);
            $site = Site::withoutGlobalScopes()->where('custom_domain', $d)->first();
            if ($site && ! in_array($site->domain_status, ['verified', 'active'], true)) {
                abort(404); // domaine relié mais pas encore vérifié
            }
        }
        if (! $site) {
            return $next($request);
        }
        // Domaine reconnu (site enregistré) : ses liens restent sur son propre domaine
        \Illuminate\Support\Facades\URL::forceRootUrl($request->getScheme().'://'.$request->getHttpHost());
        // Contact, boutique, commande, blog : même routeur que /site/{adresse}
        return app(PublicSiteController::class)->dispatch($request, $site, '', $request->path() === '/' ? '' : $request->path());
    }
}
