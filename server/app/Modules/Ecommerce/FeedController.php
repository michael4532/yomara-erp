<?php

namespace App\Modules\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\DB;

/** Flux catalogue publics (adresse secrète) : Meta Commerce (Facebook, Instagram, WhatsApp) et Google Merchant Center. */
class FeedController extends Controller
{
    public function show(EcommerceService $svc, string $token, string $format)
    {
        $row = DB::table('ec_settings')->where('feed_token', $token)->first();
        $c = $row ? Company::find($row->company_id) : null;
        abort_unless($c && $c->hasModule('ecommerce'), 404);
        app()->instance('tenant', $c);
        $s = Site::first();
        abort_unless($s && $s->status === 'published', 404);

        return $format === 'meta.csv'
            ? response($svc->metaCsv($c, $s), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'public, max-age=900'])
            : response($svc->googleXml($c, $s), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }
}
