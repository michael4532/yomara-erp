<?php

namespace App\Http\Controllers;

use App\Integrations\Events;
use App\Integrations\Feeds;
use Illuminate\Support\Facades\Route;

/** Documentation de l'API REST, des webhooks et des flux (générée à partir des routes). */
class ApiDocsController extends Controller
{
    public const GROUPS = [
        'core' => 'Contacts, articles, catégories', 'ventes' => 'Ventes : devis, commandes, factures, encaissements', 'achats' => 'Achats', 'stock' => 'Stock',
        'pos' => 'Caisse', 'crm' => 'CRM', 'tresorerie' => 'Banque & trésorerie', 'compta' => 'Comptabilité', 'rh' => 'Ressources humaines', 'paie' => 'Paie',
        'projets' => 'Projets', 'agenda' => 'Agenda', 'helpdesk' => 'Service client', 'ecommerce' => 'Boutique en ligne', 'logistique' => 'Livraisons',
        'sync' => 'Synchronisation (lecture / écriture groupée)', 'visio' => 'Réunions & webinaires', 'rapports' => 'Rapports', 'payments' => 'Paiements',
    ];

    public function __invoke()
    {
        $groups = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            $mw = $r->gatherMiddleware();
            if (! str_starts_with($uri, 'api/') || ! in_array('auth:sanctum', $mw, true)) {
                continue;
            }
            $seg = explode('/', substr($uri, 4))[0];
            if (! isset(self::GROUPS[$seg])) {
                continue;
            }
            foreach (array_diff($r->methods(), ['HEAD']) as $m) {
                $groups[$seg][] = ['method' => $m, 'uri' => '/'.$uri, 'perm' => collect($mw)->first(fn ($x) => str_starts_with($x, 'perm:')) ? str_replace(['perm:', ','], ['', ' ou '], collect($mw)->first(fn ($x) => str_starts_with($x, 'perm:'))) : null];
            }
        }
        foreach ($groups as &$g) {
            usort($g, fn ($a, $b) => strcmp($a['uri'].$a['method'], $b['uri'].$b['method']));
        }

        return response()->view('api.docs', ['groups' => $groups, 'labels' => self::GROUPS, 'events' => Events::catalog(), 'datasets' => Feeds::DATASETS, 'base' => url('/api'),
            'version' => config('erp.version')]);
    }
}
