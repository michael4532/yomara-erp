<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/** Règle de validation : l'identifiant doit appartenir à la société en cours (jamais à une autre). */
class TenantRef
{
    public static function of(string $table, string $column = 'id')
    {
        return Rule::exists($table, $column)->where('company_id', app()->bound('tenant') ? app('tenant')->id : 0);
    }
}
