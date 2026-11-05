<?php

namespace App\Modules\Compta;

use App\Modules\Achats\Models\PurchasePayment;
use App\Modules\Core\Events\DocumentCancelled;
use App\Modules\Core\Events\DocumentValidated;
use App\Modules\Ventes\Models\SalesPayment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Branche la comptabilité sur les événements métier (si le module est activé pour la société). */
class ComptaModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(DocumentValidated::class, fn (DocumentValidated $e) => app(Posting::class)->postDocument($e->document));
        Event::listen(DocumentCancelled::class, fn (DocumentCancelled $e) => app(Posting::class)->cancelDocument($e->document));

        foreach ([SalesPayment::class, PurchasePayment::class] as $cls) {
            $cls::saved(fn ($p) => app(Posting::class)->postPayment($p));
            $cls::deleted(fn ($p) => app(Posting::class)->removePayment($p));
        }
    }
}
