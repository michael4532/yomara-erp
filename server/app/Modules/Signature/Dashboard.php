<?php

namespace App\Modules\Signature;

use App\Models\Company;
use App\Modules\Signature\Models\SignRequest;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $p = 'signature.view|signature.manage';

        return [
            ['key' => 'sg_pending', 'label' => 'Signatures en attente', 'value' => SignRequest::where('status', 'pending')->count(), 'format' => 'number', 'icon' => 'draw', 'permission' => $p],
            ['key' => 'sg_done', 'label' => 'Documents signés ce mois', 'value' => SignRequest::where('status', 'completed')->where('completed_at', '>=', now()->startOfMonth())->count(), 'format' => 'number', 'icon' => 'task_alt', 'permission' => $p],
        ];
    }
}
