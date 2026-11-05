<?php

namespace App\Http\Controllers;

use App\Models\PlatformDelivery;
use App\Platform\ClientComms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Suivi des e-mails de l'éditeur à ses clients : ouverture, clic (lien signé), désinscription. */
class ClientCommsTrackingController extends Controller
{
    protected function delivery(string $token): ?PlatformDelivery
    {
        return preg_match('/^[A-Za-z0-9]{40}$/', $token) ? PlatformDelivery::where('token', $token)->first() : null;
    }

    public function open(string $token)
    {
        if (($d = $this->delivery($token)) && ! $d->opened_at) {
            $d->forceFill(['opened_at' => now()])->saveQuietly();
        }

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
    }

    public function click(Request $r, string $token)
    {
        $url = (string) base64_decode(strtr((string) $r->query('u'), '-_', '+/'));
        // Lien signé à l'envoi : pas de redirection vers une adresse choisie par un tiers
        abort_unless(preg_match('#^https?://#i', $url) && ClientComms::validClick($token, $url, (string) $r->query('s')), 404);
        if ($d = $this->delivery($token)) {
            $d->forceFill(['clicked_at' => $d->clicked_at ?? now(), 'opened_at' => $d->opened_at ?? now(), 'clicks' => $d->clicks + 1])->saveQuietly();
        }

        return redirect()->away($url);
    }

    public function unsubscribe(Request $r, string $token)
    {
        $d = $this->delivery($token);
        abort_unless($d && $d->email, 404);
        $done = DB::table('platform_unsubscribes')->where('email', $d->email)->exists();
        if ($r->isMethod('post') && ! $done) {
            DB::table('platform_unsubscribes')->insertOrIgnore(['email' => $d->email, 'company_id' => $d->company_id, 'created_at' => now()]);
            PlatformDelivery::where('email', $d->email)->where('status', 'pending')->update(['status' => 'skipped', 'error' => 'Désinscrit']);
            $done = true;
        }

        return response()->view('comms.unsubscribe', ['done' => $done, 'email' => $d->email, 'token' => $token, 'name' => config('erp.name')])
            ->header('X-Robots-Tag', 'noindex');
    }
}
