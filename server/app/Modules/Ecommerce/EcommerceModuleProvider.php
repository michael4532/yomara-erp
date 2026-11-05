<?php

namespace App\Modules\Ecommerce;

use App\Modules\Logistique\Models\Delivery;
use Illuminate\Support\ServiceProvider;

class EcommerceModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        // La livraison d'une commande en ligne (Logistique) fait avancer la commande
        Delivery::saved(function (Delivery $d) {
            if ($d->wasRecentlyCreated || ! $d->wasChanged('status')) {
                return;
            }
            app(EcommerceService::class)->onDelivery($d);
        });
    }
}
