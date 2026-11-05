<?php

namespace App\Modules\Evenements;

use App\Modules\Evenements\Models\Booking;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\ServiceProvider;

class EvenementsModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Paiement ou annulation d'une facture : la réservation est resynchronisée (montant payé à jour)
        SalesDocument::updated(function (SalesDocument $doc) {
            if (! $doc->wasChanged(['amount_paid', 'payment_status', 'status', 'number'])) {
                return;
            }
            foreach (Booking::withoutGlobalScopes()->where('company_id', $doc->company_id)->where(fn ($q) => $q->where('deposit_invoice_id', $doc->id)->orWhere('balance_invoice_id', $doc->id))->get() as $b) {
                if ($doc->wasChanged('status') && $doc->status === 'cancelled' && $b->balance_invoice_id === $doc->id && $b->status === 'confirmed') {
                    $b->balance_invoice_id = null; // solde annulé : il pourra être refacturé
                }
                $b->save();
                if ($doc->payment_status === 'paid' && in_array($b->status, ['option', 'expired'], true)) {
                    app()->instance('tenant', \App\Models\Company::find($doc->company_id));
                    app(EventService::class)->onDepositPaid($b->fresh());
                }
            }
        });
    }
}
