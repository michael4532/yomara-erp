<?php

namespace App\Modules\Agences;

use App\Modules\Agences\Models\Branch;
use App\Support\BranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Multi-agences : portée des mouvements de stock, sessions de caisse et paiements selon les agences de l'utilisateur,
 * caisse de l'agence pour les encaissements, section analytique par agence.
 */
class AgencesModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        $visibleWarehouses = function (array $ids) {
            return DB::table('warehouses')->select('id')->where(fn ($w) => $w->whereIn('branch_id', $ids ?: ['__none__'])->orWhereNull('branch_id'));
        };
        if (class_exists(\App\Modules\Stock\Models\StockMove::class)) {
            \App\Modules\Stock\Models\StockMove::addGlobalScope('branch', function (Builder $q) use ($visibleWarehouses) {
                $ids = BranchScope::filter();
                if ($ids !== null) {
                    $q->whereIn('stock_moves.warehouse_id', $visibleWarehouses($ids));
                }
            });
        }
        if (class_exists(\App\Modules\Pos\Models\PosSession::class)) {
            \App\Modules\Pos\Models\PosSession::addGlobalScope('branch', function (Builder $q) {
                $ids = BranchScope::filter();
                if ($ids !== null) {
                    $q->whereIn('pos_sessions.register_id', DB::table('pos_registers')->select('id')->where(fn ($w) => $w->whereIn('branch_id', $ids ?: ['__none__'])->orWhereNull('branch_id')));
                }
            });
        }
        foreach ([\App\Modules\Ventes\Models\SalesPayment::class, \App\Modules\Achats\Models\PurchasePayment::class] as $cls) {
            if (! class_exists($cls)) {
                continue;
            }
            $cls::addGlobalScope('branch', function (Builder $q) {
                $ids = BranchScope::filter();
                if ($ids !== null) {
                    $q->whereIn('payments.document_id', DB::table('trade_documents')->select('id')->whereIn('branch_id', $ids ?: ['__none__']));
                }
            });
            // Encaissement en espèces d'une agence : sur la caisse de l'agence
            $cls::creating(function ($p) {
                if ($p->treasury_account_id || ! \Illuminate\Support\Facades\Schema::hasColumn('payments', 'treasury_account_id')) {
                    return;
                }
                $branch = DB::table('trade_documents')->where('id', $p->document_id)->value('branch_id');
                if (! $branch) {
                    return;
                }
                $accounts = DB::table('tr_accounts')->where('company_id', $p->company_id ?? app('tenant')->id)->where('branch_id', $branch)->where('active', true)->whereNull('deleted_at')->orderBy('sort')->get(['id', 'methods']);
                foreach ($accounts as $a) {
                    if (in_array($p->method, json_decode($a->methods ?: '[]', true) ?: [], true)) {
                        $p->treasury_account_id = $a->id;

                        return;
                    }
                }
                if ($p->method === 'cash' && ($cash = DB::table('branches')->where('id', $branch)->value('cash_account_id'))) {
                    $p->treasury_account_id = $cash;
                }
            });
        }
        // Section analytique de l'agence (axe « Agences »)
        Branch::saved(function (Branch $b) {
            if (app()->bound('tenant') && app('tenant')->hasModule('analytique') && class_exists(\App\Modules\Analytique\AnalyticService::class)) {
                app(\App\Modules\Analytique\AnalyticService::class)->branchSection($b);
            }
        });
    }
}
