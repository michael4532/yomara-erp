<?php

namespace App\Modules\Fne;

use App\Modules\Core\Events\DocumentValidated;
use App\Modules\Fne\Models\FneCertification;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class FneModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        // La certification ne doit jamais empêcher la validation : en cas d'erreur, la facture reste « à certifier ».
        Event::listen(DocumentValidated::class, function (DocumentValidated $e) {
            try {
                app(FneService::class)->onValidated($e->document);
            } catch (\Throwable $ex) {
                report($ex);
            }
        });

        // Une facture certifiée ne s'annule plus : la DGI impose un avoir.
        SalesDocument::saving(function (SalesDocument $d) {
            if (! $d->exists || ! $d->isDirty('status') || $d->status !== 'cancelled') {
                return;
            }
            $cert = FneCertification::where('document_id', $d->id)->first();
            if (! $cert) {
                return;
            }
            if ($cert->status === 'certified') {
                throw new \DomainException('Ce document est certifié FNE : il ne peut plus être annulé. Émettez un avoir.');
            }
            $cert->delete(); // pas encore transmis : on retire la demande
        });
    }
}
