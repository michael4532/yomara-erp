<?php

namespace App\Modules\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\Click;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Liens publics des campagnes : ouverture, clic, désinscription, aperçu. */
class TrackingController extends Controller
{
    protected function message(string $token): ?CampaignMessage
    {
        return CampaignMessage::withoutGlobalScopes()->where('token', $token)->first();
    }

    public function open(string $token)
    {
        if ($m = $this->message($token)) {
            $first = ! $m->opened_at;
            $m->forceFill(['opened_at' => $m->opened_at ?? now(), 'opens' => $m->opens + 1])->saveQuietly();
            if ($first) {
                $m->campaign?->refreshStats();
                $m->contact?->bump(1);
            }
        }

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
    }

    public function click(Request $r, string $token)
    {
        $url = base64_decode(strtr((string) $r->query('u'), '-_', '+/'));
        $m = $this->message($token);
        // Redirection seulement pour un vrai message et un lien qu'il contient (pas de redirection ouverte)
        if (! preg_match('#^https?://#i', (string) $url) || ! $m) {
            abort(404);
        }
        // Liens signés depuis la version 0.99.3 ; les e-mails plus anciens restent valides sans signature
        $sig = (string) $r->query('s');
        if ($sig !== '' || $m->created_at?->gte('2026-11-03')) {
            abort_unless(hash_equals(EmailRenderer::linkSig($token, $url), $sig), 404);
        } else {
            // ancien e-mail sans signature : le lien doit figurer dans le contenu de sa campagne ou de son automatisation
            $src = $m->campaign ?? $m->automation;
            $content = str_replace('\\/', '/', json_encode($src?->getAttributes() ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            abort_unless($src && str_contains($content, $url), 404);
        }
        if ($m) {
            $first = ! $m->clicked_at;
            $firstOpen = ! $m->opened_at;
            $m->forceFill(['clicked_at' => $m->clicked_at ?? now(), 'clicks' => $m->clicks + 1, 'opened_at' => $m->opened_at ?? now()])->saveQuietly();
            Click::create(['company_id' => $m->company_id, 'message_id' => $m->id, 'campaign_id' => $m->campaign_id, 'automation_id' => $m->automation_id, 'url' => mb_substr($url, 0, 2000)]);
            if ($first) {
                $m->campaign?->refreshStats();
                $m->contact?->bump($firstOpen ? 4 : 3);
            }
        }

        return redirect()->away($url);
    }

    public function unsubscribe(Request $r, string $token)
    {
        $m = $this->message($token);
        abort_unless($m, 404);
        $company = Company::find($m->company_id);
        $contact = $m->contact;
        $done = false;
        if ($r->isMethod('post') && $contact) {
            $contact->forceFill(['unsubscribed_'.$m->channel.'_at' => now()])->saveQuietly();
            $m->forceFill(['unsubscribed_at' => now()])->saveQuietly();
            $m->campaign?->refreshStats();
            $contact->bump(-10);
            $done = true;
        }

        return view('marketing.unsubscribe', ['company' => $company, 'channel' => $m->channel, 'done' => $done || ($contact && $contact->unsubscribed($m->channel)), 'token' => $token]);
    }

    public function preview(string $key)
    {
        $html = Cache::get('mk_preview_'.$key);
        abort_unless($html, 404);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; sandbox allow-popups allow-popups-to-escape-sandbox"]);
    }
}
