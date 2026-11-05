<?php

namespace App\Modules\Appro;

use App\Models\Company;
use App\Modules\Appro\Models\ImportFile;
use App\Modules\Appro\Models\Rental;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        return [
            ['key' => 'imports_transit', 'label' => 'Importations en cours', 'value' => ImportFile::whereIn('status', ['ordered', 'in_transit', 'customs'])->count(), 'format' => 'number', 'icon' => 'local_shipping'],
            ['key' => 'rentals_overdue', 'label' => 'Locations dépassées', 'value' => Rental::where('status', 'active')->where('end_date', '<', now()->toDateString())->count(), 'format' => 'number', 'icon' => 'event_busy',
                'alert' => Rental::where('status', 'active')->where('end_date', '<', now()->toDateString())->exists()],
        ];
    }
}
