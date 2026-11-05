<?php

namespace App\Modules\Veille;

use App\Models\Company;
use App\Support\ServiceRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Droits par défaut (la direction et le marketing pilotent, les commerciaux remontent le terrain)
 * et reprise des concurrents et observations déjà saisis dans « Stratégie marketing & communication ».
 */
class VeilleSetup
{
    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, [
            'Direction' => ['veille.view', 'veille.manage'],
            'Marketing & communication' => ['veille.view', 'veille.manage'],
            'Commercial' => ['veille.view', 'veille.report'],
        ]);
        $this->importFromContenus($company);
    }

    public function importFromContenus(Company $company): int
    {
        if (! Schema::hasTable('mc_competitors') || DB::table('vc_competitors')->where('company_id', $company->id)->exists()) {
            return 0;
        }
        $map = [];
        foreach (DB::table('mc_competitors')->where('company_id', $company->id)->get() as $m) {
            $map[$m->id] = DB::table('vc_competitors')->insertGetId([
                'company_id' => $company->id, 'created_by' => $m->created_by ?? null, 'owner_id' => $m->owner_id ?? null, 'title' => $m->title, 'sector' => $m->sector ?? null,
                'threat' => in_array($m->threat ?? null, ['low', 'medium', 'high'], true) ? $m->threat : 'medium', 'positioning' => $m->positioning ?? null,
                'website' => $m->website ?? null, 'offer' => trim(($m->offer ?? '')."\n".(($m->prices ?? null) ? 'Prix relevés : '.$m->prices : '')) ?: null,
                'strengths' => $m->strengths ?? null, 'weaknesses' => $m->weaknesses ?? null, 'notes' => ($m->socials ?? null) ? 'Réseaux : '.$m->socials : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('mc_observations')) {
            foreach (DB::table('mc_observations')->where('company_id', $company->id)->get() as $o) {
                DB::table('vc_observations')->insert([
                    'company_id' => $company->id, 'created_by' => $o->created_by ?? null, 'owner_id' => $o->owner_id ?? null, 'competitor_id' => $map[$o->competitor_id] ?? null,
                    'title' => mb_substr($o->title, 0, 250), 'observed_on' => $o->observed_on, 'kind' => array_key_exists($o->kind, VeilleHooks::OBS_KINDS) ? $o->kind : 'other',
                    'impact' => in_array($o->impact, ['low', 'medium', 'high'], true) ? $o->impact : 'medium', 'status' => $o->response ? 'action' : 'analyzed', 'source' => 'manual',
                    'details' => $o->details, 'source_url' => $o->source_url, 'response' => $o->response, 'created_at' => $o->created_at ?? now(), 'updated_at' => now(),
                ]);
            }
        }

        return count($map);
    }
}
