<?php

namespace App\Modules\Production;

use App\Modules\Core\Events\DocumentCancelled;
use App\Modules\Core\Events\DocumentValidated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ProductionModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Recettes : les ingrédients des plats et kits vendus sortent du stock
        Event::listen(DocumentValidated::class, function (DocumentValidated $e) {
            if ($e->document->company?->hasModule('production')) {
                app(ProductionService::class)->consumeSale($e->document);
            }
        });
        Event::listen(DocumentCancelled::class, function (DocumentCancelled $e) {
            app(ProductionService::class)->consumeSale($e->document, reverse: true);
        });
    }
}
