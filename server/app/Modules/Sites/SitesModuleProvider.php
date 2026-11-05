<?php

namespace App\Modules\Sites;

use App\Modules\Sites\Models\SiteOrder;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\ServiceProvider;

class SitesModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Commande de la boutique payée en ligne (facture soldée)
        SalesDocument::updated(function (SalesDocument $doc) {
            if (! $doc->wasChanged(['payment_status', 'status']) || $doc->payment_status !== 'paid') {
                return;
            }
            if (SiteOrder::withoutGlobalScopes()->where('company_id', $doc->company_id)->where(fn ($q) => $q->where('document_id', $doc->id)->orWhere('invoice_id', $doc->id))->where('payment_status', 'unpaid')->exists()) {
                app(ShopService::class)->onInvoicePaid($doc);
            }
        });
    }
}
