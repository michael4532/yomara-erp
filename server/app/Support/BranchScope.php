<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agences (module Multi-agences) pour la requête en cours :
 *  - restriction : agences auxquelles l'utilisateur a accès (null = toutes) ;
 *  - agence de travail : choisie dans l'application (en-tête X-Branch-Id), sert de valeur par défaut aux créations ;
 *  - filtre : agence demandée explicitement pour un tableau de bord ou un rapport.
 */
class BranchScope
{
    /** Agences autorisées pour l'utilisateur, ou null s'il a accès à toutes (ou si le module n'est pas activé). */
    public static function allowedFor(?User $user, ?Company $company): ?array
    {
        if (! $user || ! $company || $user->is_super_admin || ! $company->hasModule('agences') || ! Schema::hasTable('branch_user')) {
            return null;
        }
        $ids = DB::table('branch_user')->join('branches', 'branches.id', '=', 'branch_user.branch_id')
            ->where('branch_user.user_id', $user->id)->where('branches.company_id', $company->id)->whereNull('branches.deleted_at')
            ->pluck('branch_user.branch_id')->all();

        return $ids ?: null;
    }

    /** À appeler une fois l'utilisateur et la société connus (middleware). */
    public static function bindFor(?User $user, ?Company $company, ?string $requested): void
    {
        $allowed = self::allowedFor($user, $company);
        app()->instance('branch_scope', $allowed);
        $current = null;
        if ($requested && $company?->hasModule('agences')) {
            $exists = DB::table('branches')->where('company_id', $company->id)->where('id', $requested)->whereNull('deleted_at')->exists();
            if ($exists && ($allowed === null || in_array($requested, $allowed, true))) {
                $current = $requested;
            }
        }
        if (! $current && $allowed && count($allowed) === 1) {
            $current = $allowed[0];
        }
        app()->instance('branch_current', $current);
    }

    public static function allowed(): ?array
    {
        return app()->bound('branch_scope') ? app('branch_scope') : null;
    }

    public static function current(): ?string
    {
        return app()->bound('branch_current') ? app('branch_current') : null;
    }

    /** Filtre explicite (tableau de bord, rapport) combiné à la restriction. */
    public static function filter(): ?array
    {
        $f = app()->bound('branch_filter') ? app('branch_filter') : null;
        $a = self::allowed();
        if ($f === null) {
            return $a;
        }

        return $a === null ? $f : array_values(array_intersect($f, $a));
    }

    /** Applique un filtre d'agence demandé (ignoré s'il n'est pas autorisé). */
    public static function withFilter(?string $branchId, callable $fn)
    {
        $had = app()->bound('branch_filter') ? app('branch_filter') : null;
        app()->instance('branch_filter', $branchId ? [$branchId] : null);
        try {
            return $fn();
        } finally {
            app()->instance('branch_filter', $had);
        }
    }

    public static function canUse(?string $branchId): bool
    {
        $a = self::allowed();

        return $branchId === null ? $a === null : ($a === null || in_array($branchId, $a, true));
    }
}
