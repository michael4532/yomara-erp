<?php

namespace App\Modules\Archives;

use App\Modules\Core\Events\DocumentValidated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ArchivesProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Facture validée : archivée automatiquement (instantané scellé) si sa série le prévoit. Jamais bloquant.
        Event::listen(DocumentValidated::class, function (DocumentValidated $e) {
            try {
                app(ArchiveService::class)->onValidated($e->document);
            } catch (\Throwable $ex) {
                report($ex);
            }
        });
    }
}
