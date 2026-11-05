<?php

namespace App\Modules\Immobilisations;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $s = app(AssetService::class)->stats($company);
        $p = 'immobilisations.view|immobilisations.manage';
        $out = [
            ['key' => 'im_nbv', 'label' => 'Valeur nette des immobilisations', 'value' => $s['nbv'], 'format' => 'money', 'icon' => 'domain', 'permission' => $p],
        ];
        if ($s['due_count'] > 0) {
            $out[] = ['key' => 'im_due', 'label' => 'Dotations aux amortissements à comptabiliser', 'value' => $s['due_amount'], 'format' => 'money', 'icon' => 'schedule', 'permission' => $p];
        }

        return $out;
    }
}
