<?php

namespace App\Modules\Dev;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/** Indicateurs de l'accueil : bugs ouverts et tâches en revue. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $q = DB::table('dev_items')->where('company_id', $company->id)->whereNotIn('status', ['done', 'cancelled']);

        return [
            ['key' => 'dev_bugs', 'label' => 'Bugs ouverts', 'value' => (clone $q)->where('kind', 'bug')->count(), 'format' => 'number', 'icon' => 'bug_report'],
            ['key' => 'dev_review', 'label' => 'Tâches en revue ou recette', 'value' => (clone $q)->whereIn('status', ['review', 'testing'])->count(), 'format' => 'number', 'icon' => 'code'],
        ];
    }
}
