<?php

namespace App\Modules\Fidelite;

use App\Modules\Core\Events\DocumentCancelled;
use App\Modules\Core\Events\DocumentValidated;
use App\Modules\Ventes\Models\SalesPayment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class FideliteModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        $svc = fn () => app(LoyaltyService::class);
        Event::listen(DocumentValidated::class, fn (DocumentValidated $e) => $svc()->earn($e->document));
        Event::listen(DocumentCancelled::class, fn (DocumentCancelled $e) => $svc()->reverseDocument($e->document));
        SalesPayment::creating(fn (SalesPayment $p) => $svc()->checkPayment($p));
        SalesPayment::created(function (SalesPayment $p) use ($svc) {
            if (in_array($p->method, ['loyalty', 'gift_card'], true)) {
                $svc()->recordPayment($p);
            }
        });
        SalesPayment::deleted(function (SalesPayment $p) use ($svc) {
            if (in_array($p->method, ['loyalty', 'gift_card'], true)) {
                $svc()->refundPayment($p);
            }
        });
    }
}
