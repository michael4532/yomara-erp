<?php

namespace App\Modules\Pointage;

use App\Models\Company;
use App\Modules\Rh\Models\Employee;
use Illuminate\Support\ServiceProvider;

class PointageModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Le badge de pointage suit la fiche salarié (nom, service, compte relié, présence dans les effectifs)
        $sync = function (Employee $e) {
            if (Company::find($e->company_id)?->hasModule('pointage')) {
                app(PointageService::class)->syncBadge($e);
            }
        };
        Employee::saved($sync);
        Employee::deleted($sync);
    }
}
