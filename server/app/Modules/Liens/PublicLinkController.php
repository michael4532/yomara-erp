<?php

namespace App\Modules\Liens;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** Ouverture publique d'un lien court / smartlink : /l/{alias}. */
class PublicLinkController extends Controller
{
    protected function find(string $slug): object
    {
        $l = DB::table('lk_links')->where('slug', mb_strtolower($slug))->first();
        abort_unless($l, 404);

        return $l;
    }

    protected function unavailable(object $l, string $why)
    {
        return response()->view('liens.message', ['title' => 'Lien indisponible', 'text' => $why], 410)->header('X-Robots-Tag', 'noindex');
    }

    public function open(Request $r, string $slug)
    {
        $l = $this->find($slug);
        $company = DB::table('companies')->where('id', $l->company_id)->first(['id', 'name', 'status']);
        if (! $l->active || ($company->status ?? '') === 'suspended') {
            return $this->unavailable($l, 'Ce lien a été désactivé par son propriétaire.');
        }
        if ($l->expires_at && now()->gt($l->expires_at)) {
            return $this->unavailable($l, 'Ce lien a expiré.');
        }
        if ($l->max_clicks && $l->clicks_count >= $l->max_clicks) {
            return $this->unavailable($l, 'Ce lien a atteint son nombre maximum de visites.');
        }
        if ($l->password && ! $r->session()->get('lk_ok_'.$l->id)) {
            if ($r->isMethod('post')) {
                // contre la recherche du mot de passe par essais successifs : 10 essais par lien et par adresse IP toutes les 10 minutes
                $key = 'lk_pw:'.$l->id.':'.$r->ip();
                if (RateLimiter::tooManyAttempts($key, 10)) {
                    return response()->view('liens.password', ['l' => $l, 'error' => 'Trop d\'essais : réessayez dans quelques minutes.'], 429);
                }
                if (Hash::check((string) $r->input('password'), $l->password)) {
                    RateLimiter::clear($key);
                    $r->session()->put('lk_ok_'.$l->id, true);
                } else {
                    RateLimiter::hit($key, 600);

                    return response()->view('liens.password', ['l' => $l, 'error' => 'Mot de passe incorrect.'], 422);
                }
            } else {
                return response()->view('liens.password', ['l' => $l, 'error' => null])->header('X-Robots-Tag', 'noindex');
            }
        }
        $source = $r->query('q') ? 'qr' : 'link';
        $ua = (string) $r->userAgent();
        LinkService::track($l, $r, $source);

        if ($l->kind === 'page') {
            $page = json_decode($l->page ?? '{}', true) ?: [];

            return response()->view('liens.page', ['l' => $l, 'p' => $page, 'company' => $company, 'platforms' => LinkService::PLATFORMS,
                'src' => $source, 'preview' => LinkService::isBot($ua)]);
        }
        $to = LinkService::destination($l, $r, LinkService::country($r));
        abort_unless($to, 404);
        if ($l->cloak) {
            // Lien masqué : la destination s'affiche sous l'adresse courte (les sites qui l'interdisent s'ouvrent normalement)
            return response()->view('liens.cloak', ['l' => $l, 'to' => $to]);
        }

        return redirect()->away($to, 302)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    /** Bouton d'une page smartlink : clic compté puis redirection. */
    public function button(Request $r, string $slug, int $i)
    {
        $l = $this->find($slug);
        $buttons = json_decode($l->page ?? '{}', true)['buttons'] ?? [];
        abort_unless($l->active && isset($buttons[$i]), 404);
        if ($l->password && ! $r->session()->get('lk_ok_'.$l->id)) {
            return redirect(LinkService::publicUrl($l->slug));
        }
        LinkService::track($l, $r, $r->query('q') ? 'qr' : 'link', $i);

        return redirect()->away($buttons[$i]['url'], 302)->header('Cache-Control', 'no-store');
    }

    public function qr(Request $r, string $slug)
    {
        $l = $this->find($slug);
        $png = QrRenderer::png(LinkService::publicUrl($l->slug).'?q=1', ['fg' => '#0A1633'], min(1600, max(200, (int) $r->query('size', 600))));

        return response($png)->header('Content-Type', 'image/png')->header('Cache-Control', 'public, max-age=86400')
            ->header('Content-Disposition', 'inline; filename="qr-'.$l->slug.'.png"');
    }
}
