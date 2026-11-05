<?php

namespace App\Modules\Interventions;

use App\Modules\Interventions\Models\Intervention;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\ServiceProvider;

class InterventionsModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        Intervention::saved(fn (Intervention $i) => app(InterventionService::class)->afterSave($i));

        // Facture supprimée (brouillon) ou annulée : l'intervention redevient facturable
        $release = function (SalesDocument $doc) {
            foreach (Intervention::withoutGlobalScopes()->where('company_id', $doc->company_id)->where('invoice_id', $doc->id)->get() as $i) {
                $i->forceFill(['invoice_id' => null])->save();
            }
        };
        SalesDocument::deleted($release);
        SalesDocument::updated(function (SalesDocument $doc) use ($release) {
            if ($doc->wasChanged('status') && $doc->status === 'cancelled') {
                $release($doc);
            }
        });
    }
}
