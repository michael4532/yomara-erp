<?php

namespace App\Modules\Acquisition;

use App\Models\Company;
use App\Support\ServiceRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Droits par défaut et source « Facebook / Instagram » prête à recevoir les prospects des publicités. */
class AcquisitionSetup
{
    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, [
            'Direction' => ['acquisition.view', 'acquisition.manage'],
            'Marketing & communication' => ['acquisition.view', 'acquisition.manage'],
            'Commercial' => ['acquisition.view'],
        ]);
        if (! DB::table('ac_sources')->where('company_id', $company->id)->exists()) {
            DB::table('ac_sources')->insert([
                ['company_id' => $company->id, 'title' => 'Publicités Facebook / Instagram', 'platform' => 'meta', 'token' => Str::random(40), 'assign' => 'round_robin', 'notify' => true, 'active' => true,
                    'reply_channel' => null, 'reply_text' => 'Bonjour {{prenom}}, merci pour votre demande à {{entreprise}} ! {{commercial}} vous contacte très vite.', 'created_at' => now(), 'updated_at' => now()],
                ['company_id' => $company->id, 'title' => 'Pages de capture', 'platform' => 'landing', 'token' => Str::random(40), 'assign' => 'round_robin', 'notify' => true, 'active' => true,
                    'reply_channel' => null, 'reply_text' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }
}
