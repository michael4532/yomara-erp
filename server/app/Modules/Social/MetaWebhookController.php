<?php

namespace App\Modules\Social;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Webhook unique de l'application Meta de la plateforme : prospects des publicités (leadgen),
 * commentaires et messages des pages Facebook et des comptes Instagram de toutes les sociétés.
 */
class MetaWebhookController extends Controller
{
    public static function verifyToken(): string
    {
        return substr(hash_hmac('sha256', 'meta-webhook', (string) config('app.key')), 0, 32);
    }

    public function verify(Request $r)
    {
        abort_unless($r->query('hub_mode') === 'subscribe' && hash_equals(self::verifyToken(), (string) $r->query('hub_verify_token')), 403);

        return response((string) $r->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $r)
    {
        $secret = Networks::app('meta')['client_secret'];
        $sig = (string) $r->header('X-Hub-Signature-256');
        abort_unless($secret && $sig && hash_equals('sha256='.hash_hmac('sha256', $r->getContent(), $secret), $sig), 403);
        $object = (string) $r->input('object');
        $done = 0;
        foreach ((array) $r->input('entry', []) as $entry) {
            $id = (string) ($entry['id'] ?? '');
            $network = $object === 'instagram' ? 'instagram' : 'facebook';
            // Une page peut être reliée par plusieurs sociétés : chacune reçoit l'événement.
            foreach (DB::table('so_accounts')->where('network', $network)->where('external_id', $id)->get() as $acc) {
                $c = Company::find($acc->company_id);
                if (! $c) {
                    continue;
                }
                app()->instance('tenant', $c);
                try {
                    if ($c->hasModule('social') && $acc->inbox_enabled) {
                        $done += app(Inbox::class)->webhook($acc, $entry);
                    }
                    if ($network === 'facebook' && $c->hasModule('acquisition')) {
                        foreach ((array) ($entry['changes'] ?? []) as $ch) {
                            if (($ch['field'] ?? '') === 'leadgen' && ! empty($ch['value']['leadgen_id'])) {
                                app(\App\Modules\Acquisition\MetaLeads::class)->receive($c, $acc, (array) $ch['value']);
                                $done++;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
                app()->forgetInstance('tenant');
            }
        }

        return response()->json(['ok' => true, 'handled' => $done]);
    }
}
