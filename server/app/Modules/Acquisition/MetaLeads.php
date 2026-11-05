<?php

namespace App\Modules\Acquisition;

use App\Models\Company;
use App\Modules\Social\SocialOAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Prospect d'un formulaire de publicité Facebook / Instagram signalé par le webhook : lecture du formulaire puis arrivée dans le CRM. */
class MetaLeads
{
    public function receive(Company $c, object $pageAccount, array $v): array
    {
        $leadgen = (string) $v['leadgen_id'];
        if (DB::table('ac_leads')->where('company_id', $c->id)->where('platform', 'meta')->where('external_id', $leadgen)->exists()) {
            return ['status' => 'already'];
        }
        $source = DB::table('ac_sources')->where('company_id', $c->id)->where('platform', 'meta')->where('active', true)
            ->where(fn ($q) => $q->whereIn('match_ref', array_filter([(string) ($v['form_id'] ?? ''), (string) ($v['page_id'] ?? '')])))->first()
            ?? DB::table('ac_sources')->where('company_id', $c->id)->where('platform', 'meta')->where('active', true)->where(fn ($q) => $q->whereNull('match_ref')->orWhere('match_ref', ''))->orderBy('id')->first();
        try {
            $data = app(AdPlatforms::class)->metaLead($leadgen, app(SocialOAuth::class)->token($pageAccount));

            return app(LeadIntake::class)->receive($c, $source, 'meta', $data['answers'], $data['meta']);
        } catch (\Throwable $e) {
            DB::table('ac_leads')->insertOrIgnore(['company_id' => $c->id, 'source_id' => $source?->id, 'platform' => 'meta', 'external_id' => $leadgen, 'title' => 'Prospect Facebook '.$leadgen,
                'status' => 'error', 'note' => Str::limit('Lecture impossible : '.$e->getMessage(), 300, ''), 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
