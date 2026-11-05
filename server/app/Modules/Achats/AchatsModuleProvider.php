<?php

namespace App\Modules\Achats;

use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\PurchaseReceipt;
use App\Modules\Achats\Models\PurchaseRequest;
use App\Modules\Core\Events\DocumentValidated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AchatsModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        PurchaseReceipt::saved(fn (PurchaseReceipt $r) => app(AchatsService::class)->afterReceipt($r));
        PurchaseRequest::saved(fn (PurchaseRequest $r) => app(AchatsService::class)->afterRequest($r));
        Event::listen(DocumentValidated::class, function (DocumentValidated $e) {
            if ($e->document instanceof PurchaseDocument || $e->document->direction === 'purchase') {
                $doc = $e->document instanceof PurchaseDocument ? $e->document : PurchaseDocument::find($e->document->id);
                if ($doc) {
                    app(AchatsService::class)->learnPrices($doc->load('lines'));
                }
            }
        });
    }
}
