<?php

namespace App\Modules\Portail;

use App\Models\Company;
use App\Modules\Portail\Models\PortalEvent;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        return [
            ['key' => 'portal_messages', 'label' => 'Messages clients non lus (portail)', 'value' => PortalEvent::where('type', 'message')->whereNull('read_at')->count(), 'format' => 'number', 'icon' => 'mark_email_unread'],
        ];
    }
}
