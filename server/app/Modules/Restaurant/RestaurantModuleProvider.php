<?php

namespace App\Modules\Restaurant;

use App\Modules\Restaurant\Models\Order;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\ServiceProvider;

class RestaurantModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Ticket d'addition enregistré, validé ou annulé : la commande se clôt ou se rouvre.
        $refresh = function (SalesDocument $doc) {
            if ($doc->rs_order_id && ($doc->wasRecentlyCreated || $doc->wasChanged(['status', 'rs_bill', 'rs_order_id']))) {
                if ($order = Order::withoutGlobalScopes()->where('company_id', $doc->company_id)->find($doc->rs_order_id)) {
                    app(RestaurantService::class)->refresh($order);
                }
            }
        };
        SalesDocument::saved($refresh);
        SalesDocument::deleted(function (SalesDocument $doc) {
            if ($doc->rs_order_id && $order = Order::withoutGlobalScopes()->where('company_id', $doc->company_id)->find($doc->rs_order_id)) {
                app(RestaurantService::class)->refresh($order);
            }
        });
    }
}
