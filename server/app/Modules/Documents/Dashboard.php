<?php

namespace App\Modules\Documents;

use App\Models\Company;
use App\Modules\Documents\Models\DocFile;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $p = 'documents.view|documents.manage|documents.admin';

        return [
            ['key' => 'dc_expiring', 'label' => 'Documents arrivant à échéance (30 j)', 'value' => DocFile::whereNotNull('expires_at')->whereBetween('expires_at', [today()->subYears(5), today()->addDays(30)])->count(), 'format' => 'number', 'icon' => 'event_busy', 'permission' => $p],
        ];
    }
}
