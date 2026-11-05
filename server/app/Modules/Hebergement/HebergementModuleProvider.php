<?php

namespace App\Modules\Hebergement;

use App\Modules\Hebergement\Models\HostedService;
use App\Modules\Hebergement\Models\Renewal;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\ServiceProvider;

class HebergementModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        SalesDocument::updated(function (SalesDocument $doc) {
            if (! $doc->wasChanged(['amount_paid', 'payment_status', 'status', 'number'])) {
                return;
            }
            $rows = Renewal::withoutGlobalScopes()->where('company_id', $doc->company_id)->where('document_id', $doc->id)->get();
            if ($rows->isEmpty()) {
                return;
            }
            $svc = app(HostingService::class);
            if ($doc->status === 'cancelled') {
                $svc->onInvoiceCancelled($doc);
            } elseif ($doc->payment_status === 'paid') {
                $svc->onInvoicePaid($doc);
            }
            foreach (HostedService::whereIn('id', $rows->pluck('service_id'))->get() as $s) {
                $s->save(); // facture à jour sur les appareils
            }
        });
    }
}
