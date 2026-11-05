<?php

namespace App\Modules\Stock;

use App\Modules\Core\Events\DocumentCancelled;
use App\Modules\Core\Events\DocumentValidated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Le stock réagit aux documents validés / annulés, si le module est activé pour la société. */
class StockModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(DocumentValidated::class, function (DocumentValidated $e) {
            if ($e->document->company?->hasModule('stock')) {
                app(StockService::class)->postDocument($e->document->load('lines'));
            }
        });

        Event::listen(DocumentCancelled::class, function (DocumentCancelled $e) {
            if ($e->document->stock_posted) {
                app(StockService::class)->postDocument($e->document->load('lines'), reverse: true);
            }
        });
    }
}
