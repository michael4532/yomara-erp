<?php

namespace App\Models\Concerns;

use App\Support\BranchScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rattachement à une agence (module Multi-agences) :
 *  - un utilisateur limité à certaines agences ne voit que leurs enregistrements (portée globale, synchronisation comprise) ;
 *  - à la création, l'agence est déduite (entrepôt, caisse, compte…) ou prise de l'agence de travail ;
 *  - un utilisateur limité ne peut pas enregistrer pour une autre agence.
 * Les modèles déclarant $branchShared = true restent visibles de tous quand ils ne sont rattachés à aucune agence (ex. entrepôt central).
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $q) {
            $ids = BranchScope::filter();
            if ($ids === null) {
                return;
            }
            $col = $q->getModel()->getTable().'.branch_id';
            $shared = property_exists(static::class, 'branchShared') && static::$branchShared && ! (app()->bound('branch_filter') && app('branch_filter'));
            $q->where(fn ($w) => $w->whereIn($col, $ids ?: ['__none__'])->when($shared, fn ($w) => $w->orWhereNull($col)));
        });
        // « saving » précède « creating » : l'agence par défaut est posée ici, avant le contrôle d'accès
        static::saving(function ($m) {
            if (! $m->exists && ! $m->branch_id) {
                if (method_exists($m, 'branchFallback')) {
                    $m->branch_id = $m->branchFallback();
                }
                $m->branch_id ??= BranchScope::current();
            }
            $allowed = BranchScope::allowed();
            if ($allowed !== null && (! $m->exists || $m->isDirty('branch_id')) && ! in_array($m->branch_id, $allowed, true)) {
                throw new \DomainException($m->branch_id ? 'Vous n\'avez pas accès à cette agence.' : 'Choisissez l\'agence de travail (en haut du menu) avant d\'enregistrer.');
            }
        });
    }
}
