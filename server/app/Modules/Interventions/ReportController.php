<?php

namespace App\Modules\Interventions;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Interventions\Models\Equipment;
use App\Modules\Interventions\Models\Intervention;

/** Rapport d'intervention public (lien envoyé au client). */
class ReportController extends Controller
{
    public function show(string $token)
    {
        abort_unless(strlen($token) >= 32, 404);
        $i = Intervention::withoutGlobalScopes()->where('report_token', $token)->whereNull('deleted_at')->first();
        abort_unless($i, 404);
        $company = Company::find($i->company_id);
        abort_unless($company && $company->hasModule('interventions'), 404);
        app()->instance('tenant', $company);

        return response()->view('interventions.report', [
            'i' => $i, 'company' => $company, 'partner' => $i->partner_id ? Partner::find($i->partner_id) : null,
            'equipment' => $i->equipment_id ? Equipment::find($i->equipment_id) : null,
            'technician' => $i->technician_id ? User::find($i->technician_id)?->name : null,
        ])->header('X-Robots-Tag', 'noindex');
    }
}
