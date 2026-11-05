<?php

namespace App\Modules\Acquisition;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Réception des prospects envoyés par un formulaire externe, à l'adresse secrète de la source :
 * formulaires Google Ads (format Google, clé vérifiée), TikTok, LinkedIn, Zapier, Make, WordPress (Contact Form 7, Elementor…), JSON ou formulaire classique.
 */
class PublicLeadController extends Controller
{
    public function receive(Request $r, string $token)
    {
        $source = DB::table('ac_sources')->where('token', $token)->first();
        abort_unless($source && $source->active, 404, 'Adresse de réception inconnue ou désactivée.');
        $c = Company::find($source->company_id);
        abort_unless($c && $c->hasModule('acquisition'), 404);
        $in = $r->all();
        $meta = [];
        if (isset($in['user_column_data']) && is_array($in['user_column_data'])) { // formulaire de prospects Google Ads
            abort_unless(! $source->secret || hash_equals((string) $source->secret, (string) ($in['google_key'] ?? '')), 403, 'Clé Google incorrecte.');
            $answers = [];
            foreach ($in['user_column_data'] as $col) {
                $answers[strtolower((string) ($col['column_id'] ?? $col['column_name'] ?? 'champ'))] = $col['string_value'] ?? '';
            }
            $meta = ['external_id' => $in['lead_id'] ?? null, 'campaign_id' => isset($in['campaign_id']) ? (string) $in['campaign_id'] : null, 'form_name' => isset($in['form_id']) ? 'Formulaire Google '.$in['form_id'] : null,
                'utm_source' => 'google', 'utm_medium' => 'paid'];
            if (! empty($in['is_test'])) {
                $meta['external_id'] = 'test-'.($in['lead_id'] ?? uniqid());
            }
            $platform = 'google';
        } else {
            if ($source->secret) {
                abort_unless(hash_equals((string) $source->secret, (string) ($r->header('X-Lead-Key') ?? $in['key'] ?? $in['google_key'] ?? '')), 403, 'Clé incorrecte.');
            }
            $flat = isset($in['data']) && is_array($in['data']) ? $in['data'] + $in : $in;
            $answers = [];
            foreach ($flat as $k => $v) {
                if (in_array($k, ['key', 'google_key', 'data', 'website_hp'], true) || str_starts_with((string) $k, 'utm_') || in_array($k, ['lead_id', 'campaign_id', 'campaign_name', 'ad_name', 'form_name', 'adgroup_name'], true)) {
                    continue;
                }
                if (is_scalar($v) || (is_array($v) && array_is_list($v) && count(array_filter($v, 'is_scalar')) === count($v))) {
                    $answers[(string) $k] = $v;
                }
            }
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'campaign_id', 'campaign_name', 'ad_name', 'form_name'] as $k) {
                if (! empty($flat[$k]) && is_scalar($flat[$k])) {
                    $meta[$k] = (string) $flat[$k];
                }
            }
            if (! empty($flat['lead_id']) && is_scalar($flat['lead_id'])) {
                $meta['external_id'] = (string) $flat['lead_id'];
            }
            $platform = $source->platform === 'landing' ? 'other' : $source->platform;
        }
        try {
            $res = app(LeadIntake::class)->receive($c, $source, $platform, $answers, $meta);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'status' => $res['status']]);
    }
}
