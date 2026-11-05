<?php

namespace App\Modules\Acquisition;

use App\Models\Company;
use App\Modules\Crm\Models\Lead;
use Illuminate\Support\ServiceProvider;

/** Une affaire issue d'une publicité Meta gagnée est renvoyée à Meta comme achat (API Conversions). */
class AcquisitionProvider extends ServiceProvider
{
    public function boot(): void
    {
        Lead::saved(function (Lead $l) {
            if (! $l->wasChanged('status') || $l->status !== 'won' || ! $l->ad_platform) {
                return;
            }
            $c = Company::find($l->company_id);
            if (! $c?->hasModule('acquisition')) {
                return;
            }
            rescue(fn () => app(AdPlatforms::class)->metaConversion($c, 'Purchase', ['email' => $l->email, 'phone' => $l->phone, 'lead_id' => $l->ad_platform === 'meta' ? $l->ad_ref : null],
                (float) $l->expected_revenue, 'crm-lead-'.$l->id), null, false);
        });
    }
}
