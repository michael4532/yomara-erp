<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolation multi-sociétés : toutes les requêtes sont filtrées sur la société
 * courante (app('tenant')) et company_id est rempli automatiquement.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            if (app()->bound('tenant')) {
                $query->where($query->getModel()->getTable().'.company_id', app('tenant')->id);
            }
        });

        static::creating(function ($model) {
            if (! $model->company_id && app()->bound('tenant')) {
                $model->company_id = app('tenant')->id;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
