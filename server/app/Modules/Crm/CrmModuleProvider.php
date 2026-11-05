<?php

namespace App\Modules\Crm;

use App\Modules\Core\Events\DocumentValidated;
use App\Modules\Core\Models\TradeDocument;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Stage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Le pipeline avance tout seul avec les ventes :
 *  - devis lié validé  → étape « proposition » (si l'opportunité était avant) ;
 *  - commande ou facture issue de ce devis validée → opportunité gagnée.
 */
class CrmModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(DocumentValidated::class, function (DocumentValidated $e) {
            $doc = $e->document;
            if ($doc->direction !== 'sale' || ! $doc->company?->hasModule('crm')) {
                return;
            }
            if (! app()->bound('tenant')) {
                app()->instance('tenant', $doc->company);
            }
            if ($doc->type === 'quote') {
                $this->quoteSent($doc);
            } elseif (in_array($doc->type, ['order', 'invoice'], true)) {
                $this->won($doc);
            }
        });
    }

    protected function quoteSent(TradeDocument $doc): void
    {
        $proposal = Stage::where('kind', 'open')->where('probability', '>=', 50)->orderBy('sequence')->first();
        foreach (Lead::where('quote_id', $doc->id)->where('status', 'open')->get() as $lead) {
            $current = $lead->stage;
            if ($proposal && (! $current || $current->sequence < $proposal->sequence)) {
                $lead->stage_id = $proposal->id;
            }
            if (! $lead->expected_revenue) {
                $lead->expected_revenue = $doc->subtotal ?? $doc->total;
            }
            $lead->save();
        }
    }

    protected function won(TradeDocument $doc): void
    {
        // Remonte la chaîne devis → commande → facture
        $ids = [$doc->id];
        $cur = $doc;
        for ($i = 0; $i < 5 && $cur->source_id; $i++) {
            $ids[] = $cur->source_id;
            $cur = $cur::withoutGlobalScopes()->find($cur->source_id);
            if (! $cur) {
                break;
            }
        }
        $wonStage = Stage::where('kind', 'won')->orderBy('sequence')->first();
        if (! $wonStage) {
            return;
        }
        foreach (Lead::whereIn('quote_id', $ids)->where('status', 'open')->get() as $lead) {
            $lead->stage_id = $wonStage->id;
            $lead->save();
        }
    }
}
