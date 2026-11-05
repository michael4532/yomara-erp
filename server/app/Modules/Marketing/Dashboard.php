<?php

namespace App\Modules\Marketing;

use App\Models\Company;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\Contact;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $last = Campaign::where('status', 'sent')->latest('sent_at')->first();

        return [
            ['key' => 'mk_contacts', 'label' => 'Contacts marketing', 'value' => Contact::count(), 'format' => 'number', 'icon' => 'campaign'],
            ['key' => 'mk_open_rate', 'label' => 'Ouverture dernière campagne', 'value' => $last ? ($last->stats['open_rate'] ?? 0) : 0, 'format' => 'percent', 'icon' => 'mail'],
        ];
    }
}
