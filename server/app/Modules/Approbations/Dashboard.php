<?php

namespace App\Modules\Approbations;

use App\Models\Company;
use App\Modules\Approbations\Models\ApprovalRequest;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $user = auth()->user();
        $mine = $user ? app(ApprovalService::class)->inbox($user)->count() : 0;

        return [
            ['key' => 'wf_inbox', 'label' => 'Demandes à approuver', 'value' => $mine, 'format' => 'number', 'icon' => 'approval', 'alert' => $mine > 0],
            ['key' => 'wf_pending', 'label' => 'Approbations en attente (société)', 'value' => ApprovalRequest::where('status', 'pending')->count(), 'format' => 'number', 'icon' => 'hourglass_bottom', 'permission' => 'approbations.view|approbations.manage'],
        ];
    }
}
