<?php

namespace App\Modules\Marketing;

use App\Modules\Marketing\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Envoie un lot puis se relance tant qu'il reste des destinataires. */
class SendCampaignBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public function __construct(public int $campaignId) {}

    public function handle(CampaignService $service): void
    {
        $c = Campaign::withoutGlobalScopes()->find($this->campaignId);
        if (! $c || $c->status !== 'sending') {
            return;
        }
        if ($service->sendBatch($c) > 0) {
            static::dispatch($this->campaignId);
        }
    }
}
