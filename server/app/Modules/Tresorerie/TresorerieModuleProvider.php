<?php

namespace App\Modules\Tresorerie;

use App\Models\Company;
use App\Modules\Achats\Models\PurchasePayment;
use App\Modules\Tresorerie\Models\StatementLine;
use App\Modules\Ventes\Models\SalesPayment;
use Illuminate\Support\ServiceProvider;

class TresorerieModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([SalesPayment::class, PurchasePayment::class] as $cls) {
            // Paiement sans compte précisé : compte par défaut de son moyen de paiement
            $cls::creating(function ($p) {
                if (! $p->treasury_account_id && Company::find($p->company_id ?? app('tenant')?->id)?->hasModule('tresorerie')) {
                    $p->treasury_account_id = app(TreasuryService::class)->defaults()[$p->method] ?? null;
                }
            });
            // Paiement supprimé : sa ligne de relevé redevient à rapprocher
            $cls::deleted(fn ($p) => StatementLine::withoutGlobalScopes()->where('match_type', 'payment')->where('match_id', $p->id)
                ->update(['status' => 'open', 'match_type' => null, 'match_id' => null, 'matched_at' => null, 'matched_by' => null]));
        }
    }
}
