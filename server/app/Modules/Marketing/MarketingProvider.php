<?php

namespace App\Modules\Marketing;

use App\Modules\Core\Events\DocumentValidated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Une vente validée (facture ou commande) lance les scénarios « Achat » du client. */
class MarketingProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(DocumentValidated::class, function (DocumentValidated $e) {
            $doc = $e->document;
            if ($doc->direction !== 'sale' || ! in_array($doc->type, ['invoice', 'order'], true) || ! $doc->partner_id || ! $doc->company?->hasModule('marketing')) {
                return;
            }
            $previous = app()->bound('tenant') ? app('tenant') : null;
            rescue(function () use ($doc) {
                app()->instance('tenant', $doc->company); // toujours la société de la vente
                $partner = DB::table('partners')->where('id', $doc->partner_id)->first();
                if (! $partner) {
                    return;
                }
                $first = ! DB::table('trade_documents')->where('company_id', $doc->company_id)->where('partner_id', $doc->partner_id)->where('direction', 'sale')
                    ->whereIn('type', ['invoice', 'order'])->where('status', 'validated')->where('id', '!=', $doc->id)->whereNull('deleted_at')->exists();
                app(AutomationEngine::class)->purchased($partner, (float) $doc->total, $first);
            }, null, false);
            $previous ? app()->instance('tenant', $previous) : app()->forgetInstance('tenant');
        });
    }
}
