<?php

namespace App\Modules\Projets;

use App\Modules\Projets\Models\Milestone;
use App\Modules\Projets\Models\Timesheet;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\ServiceProvider;

/** Une facture de projet supprimée (brouillon) ou annulée libère les temps et jalons, qui redeviennent facturables. */
class ProjetsModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        $release = function (SalesDocument $doc) {
            foreach (Timesheet::withoutGlobalScopes()->where('company_id', $doc->company_id)->where('invoice_id', $doc->id)->get() as $t) {
                $t->invoice_id = null;
                $t->save();
            }
            foreach (Milestone::withoutGlobalScopes()->where('company_id', $doc->company_id)->where('invoice_id', $doc->id)->get() as $m) {
                $m->invoice_id = null;
                $m->status = 'reached';
                $m->save();
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
