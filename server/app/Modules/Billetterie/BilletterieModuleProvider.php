<?php

namespace App\Modules\Billetterie;

use App\Modules\Billetterie\Models\TicketOrder;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\ServiceProvider;

class BilletterieModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        SalesDocument::updated(function (SalesDocument $doc) {
            if (! $doc->wasChanged(['payment_status', 'status']) || $doc->payment_status !== 'paid') {
                return;
            }
            if (TicketOrder::withoutGlobalScopes()->where('company_id', $doc->company_id)->where('invoice_id', $doc->id)->where('status', 'pending')->exists()) {
                app(TicketService::class)->onInvoicePaid($doc);
            }
        });
    }
}
