<?php

namespace App\Modules\Crm;

use App\Models\Company;
use App\Modules\Crm\Models\Stage;

/** Étapes du pipeline par défaut, créées au premier usage du module. */
class CrmSetup
{
    public const STAGES = [
        ['Nouveau', 10, 'open', '#5B6B85'],
        ['Qualifié', 25, 'open', '#1F6FEB'],
        ['Proposition envoyée', 50, 'open', '#8E44AD'],
        ['Négociation', 75, 'open', '#E8A317'],
        ['Gagné', 100, 'won', '#12A150'],
        ['Perdu', 0, 'lost', '#D93025'],
    ];

    public function __invoke(Company $company): void
    {
        if (Stage::withoutGlobalScopes()->withTrashed()->where('company_id', $company->id)->exists()) {
            return;
        }
        foreach (self::STAGES as $i => [$name, $prob, $kind, $color]) {
            $s = new Stage(['name' => $name, 'probability' => $prob, 'kind' => $kind, 'color' => $color, 'sequence' => ($i + 1) * 10]);
            $s->company_id = $company->id;
            $s->save();
        }
    }
}
