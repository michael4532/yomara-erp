<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Réglages du serveur saisis dans l'application (Super admin › Configuration du serveur) : prioritaires sur le .env
        \App\Support\EnvSettings::apply();

        // Les liens envoyés par e-mail (portail client, confirmations…) utilisent toujours l'adresse officielle du serveur,
        // jamais l'en-tête Host d'une requête (qui pourrait pointer vers un site pirate).
        if (! $this->app->runningUnitTests() && filled(config('app.url')) && ! $this->app->runningInConsole()) {
            \Illuminate\Support\Facades\URL::forceRootUrl(rtrim((string) config('app.url'), '/'));
            if (str_starts_with((string) config('app.url'), 'https://')) {
                \Illuminate\Support\Facades\URL::forceScheme('https');
            }
        }

        // Compatibilité anciens MySQL / MariaDB des hébergements mutualisés.
        Schema::defaultStringLength(191);

        // Connexion : par compte et adresse, plus un plafond par adresse IP (changement d'e-mail en boucle)
        // + plafond par compte quelle que soit l'adresse (devinette de mot de passe répartie) : 10 essais / 15 min
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(10)->by(strtolower((string) $r->input('email')).'|'.$r->ip()), Limit::perMinute(40)->by('lg-ip|'.$r->ip()),
            Limit::perMinutes(15, 10)->by('lg-acct|'.strtolower(trim((string) $r->input('email'))))]);
        // Code de double authentification : 5 essais par défi (vérifiés par le défi lui-même), plafond par adresse IP
        RateLimiter::for('twofactor', fn (Request $r) => [Limit::perMinute(10)->by('2fa|'.sha1((string) $r->input('challenge'))), Limit::perMinute(30)->by('2fa-ip|'.$r->ip())]);
        // Inscriptions : par adresse IP uniquement (évite les essais gratuits à répétition)
        RateLimiter::for('register', fn (Request $r) => [Limit::perHour(5)->by('rg|'.$r->ip()), Limit::perDay(20)->by('rg-d|'.$r->ip())]);
        // Boutique : vérification des paiements et commandes, par utilisateur
        RateLimiter::for('store', fn (Request $r) => Limit::perMinute(20)->by('st|'.($r->user()?->id ?: $r->ip())));
        RateLimiter::for('public-pay', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        // Signature : actions limitées par signataire (lien) et par adresse IP
        RateLimiter::for('signer', fn (Request $r) => [Limit::perMinutes(10, 20)->by('sg|'.$r->route('token').'|'.$r->ip()), Limit::perMinutes(10, 120)->by('sg-ip|'.$r->ip())]);
        RateLimiter::for('signer-code', fn (Request $r) => Limit::perMinutes(10, 5)->by('sgc|'.$r->route('token')));
        RateLimiter::for('portal', fn (Request $r) => Limit::perMinute(90)->by($r->ip()));
        // Salles de visioconférence : battement toutes les 1 à 5 s par participant (lien personnel), plafond par adresse IP pour un bureau entier
        RateLimiter::for('visio', fn (Request $r) => [Limit::perMinute(150)->by('vz|'.($r->header('X-Visio-Token') ?: $r->input('t')).'|'.$r->ip()), Limit::perMinute(6000)->by('vz-ip|'.$r->ip())]);
        RateLimiter::for('visio-join', fn (Request $r) => Limit::perMinute(20)->by('vj|'.$r->ip()));
        // Webhooks de réception (Mailgun, Meta…) : par société, séparés des pages publiques
        RateLimiter::for('inbound', fn (Request $r) => Limit::perMinute(240)->by('in|'.$r->route('token')));
        // Avis Google : demandes d'avis déclenchées par les événements métier (facture payée, vente, rendez-vous, ticket)
        \App\Integrations\Events::listen(fn (int $companyId, string $event, $model) => app(\App\Modules\AvisGoogle\ReviewRequests::class)->onEvent($companyId, $event, $model));
        // WooCommerce : paiement enregistré dans l'ERP (à la livraison, virement) → statut renvoyé au site
        \App\Integrations\Events::listen(fn (int $companyId, string $event, $model) => app(\App\Modules\Woocommerce\WooSync::class)->onEvent($companyId, $event, $model));
        // Accusés de réception et suivi des commandes (client + équipe)
        \App\Integrations\Events::listen(fn (int $companyId, string $event, $model) => app(\App\Modules\Ventes\OrderNotifier::class)->onEvent($companyId, $event, $model));
        \App\Integrations\Events::listen(fn (int $companyId, string $event, $model) => app(\App\Modules\Shopify\ShopifySync::class)->onEvent($companyId, $event, $model));

        // Paiements en ligne confirmés : activation d'un module acheté, encaissement d'une facture
        \App\Payments\PaymentService::onPaid('module_order', function (\App\Models\PaymentTransaction $tx) {
            $order = \App\Models\ModuleOrder::find($tx->purpose_id);
            // Le paiement doit couvrir le prix de la commande (dans sa devise)
            if ($order && (float) $tx->amount + 0.5 >= (float) $order->amount && (! $order->currency || ! $tx->currency || strtoupper($order->currency) === strtoupper($tx->currency))) {
                app(\App\Store\ModuleStore::class)->activate($order);
            } elseif ($order) {
                \Illuminate\Support\Facades\Log::warning('Commande non activée : paiement insuffisant', ['order' => $order->id, 'tx' => $tx->id]);
            }
        });
        \App\Payments\PaymentService::onPaid('invoice', [\App\Payments\InvoicePayments::class, 'record']);
        \App\Payments\PaymentService::onPaid('pay_link', [\App\Payments\PaymentLinks::class, 'record']);
    }
}
