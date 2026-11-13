<?php

namespace App\Support;

use App\Http\Controllers\LandingController;
use Illuminate\Http\Request;

/**
 * Lien intelligent « Télécharger l'application » (QR code de la boîte mail, e-mails, site) : envoie chaque appareil
 * vers la bonne boutique. iPhone/iPad → App Store, Android → Google Play, Mac → Mac App Store ;
 * ordinateur Windows ou Linux → choix entre Microsoft Store, Linux et l'édition libre (open source).
 */
class AppSmartLink
{
    /** Site officiel, utilisé quand cette installation n'héberge pas elle-même les fichiers. */
    public const OFFICIAL = 'https://erp.yomaratechnologies.com';

    public static function platform(Request $r): string
    {
        $ua = (string) $r->userAgent();

        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'ios',
            (bool) preg_match('/Android/i', $ua) => 'android',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua) => 'mac',
            (bool) preg_match('/Windows/i', $ua) => 'windows',
            (bool) preg_match('/Linux|X11|CrOS/i', $ua) => 'linux',
            default => 'other',
        };
    }

    /** Liens par plateforme : réglages de l'administration, sinon fiches officielles. */
    public static function links(): array
    {
        $stores = [];
        $files = [];
        $github = null;
        try {
            $stores = LandingController::stores();
            $files = LandingController::files();
            $github = LandingController::github();
        } catch (\Throwable) {
        }
        $official = config('erp.store_links', []);
        $mobile = config('erp.mobile', []);
        $saas = config('erp.mode') === 'saas';
        $site = $saas ? url('/') : self::OFFICIAL;

        return [
            'ios' => ($mobile['ios_url'] ?? null) ?: ($stores['ios'] ?? null) ?: ($official['ios'] ?? null),
            'android' => ($mobile['android_url'] ?? null) ?: ($stores['android'] ?? null) ?: ($official['android_store'] ?? null),
            'mac' => ($stores['mac'] ?? null) ?: ($official['mac_store'] ?? null),
            'windows' => ($stores['windows'] ?? null) ?: ($official['windows_store'] ?? null),
            'linux' => ($files['linux'] ?? null) ? ($saas ? url('/telecharger/linux') : $files['linux']) : $site.'/telecharger/linux',
            'source' => $saas ? url('/telecharger/source') : ($github ?: $site.'/telecharger/source'),
        ];
    }

    public static function handle(Request $r)
    {
        $os = self::platform($r);
        $links = self::links();
        $choice = $r->boolean('choix');
        if (! $choice && in_array($os, ['ios', 'android', 'mac'], true) && ! empty($links[$os])) {
            try {
                \App\Services\SiteTraffic::record($r, 'download', 'smartlink_'.$os);
            } catch (\Throwable) {
            }

            return redirect()->away($links[$os]);
        }

        return response(self::page($os, $links))->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Lien intelligent vers un écran de l'ERP (ex. /yomail) :
     * Android : ouvre l'application installée (sinon Google Play, sans message d'erreur) ;
     * iPhone, iPad, Mac : page « Ouvrir dans l'application » / store / version web ;
     * Windows, Linux : l'écran s'ouvre directement dans la version web.
     */
    public static function openScreen(Request $r, string $screen, string $label, string $deep)
    {
        $os = self::platform($r);
        $links = self::links();
        $web = url('/app/').'?ecran='.$screen;
        try {
            \App\Services\SiteTraffic::record($r, 'download', 'smartlink_'.$screen.'_'.$os);
        } catch (\Throwable) {
        }
        if (in_array($os, ['windows', 'linux', 'other'], true)) {
            return redirect()->away($web);
        }
        $store = $links[$os === 'mac' ? 'mac' : ($os === 'android' ? 'android' : 'ios')] ?? null;
        if ($os === 'android') {
            // Intent Android : l'application s'ouvre si elle est installée, sinon le navigateur suit l'adresse de secours
            $pkg = 'com.yomaratechnologies.yomara_erp';
            $intent = 'intent://'.$deep.'#Intent;scheme=yomara;package='.$pkg.';S.browser_fallback_url='.rawurlencode($store ?: $web).';end';

            return response(self::screenPage($label, 'yomara://'.$deep, $store, $web, 'Google Play', $intent))->header('Content-Type', 'text/html; charset=utf-8');
        }

        return response(self::screenPage($label, 'yomara://'.$deep, $store, $web, $os === 'mac' ? 'Mac App Store' : 'App Store'))->header('Content-Type', 'text/html; charset=utf-8');
    }

    protected static function screenPage(string $label, string $deep, ?string $store, string $web, string $storeName, ?string $auto = null): string
    {
        $l = e($label);
        $btn = fn ($href, $txt, $main = false) => '<a class="b'.($main ? ' m' : '').'" href="'.e($href).'" rel="noopener">'.e($txt).'</a>';

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>Ouvrir '.$l.'</title><style>'
            .'*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:system-ui,"Segoe UI",sans-serif;background:linear-gradient(160deg,#0B1B3A,#2E6BE6);display:flex;align-items:center;justify-content:center;padding:16px}'
            .'.c{background:#fff;border-radius:22px;padding:28px;max-width:420px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center}'
            .'.i{width:64px;height:64px;border-radius:18px;background:#2E6BE6;color:#fff;font-size:32px;line-height:64px;margin:0 auto 14px}'
            .'h1{margin:0 0 6px;color:#0B1B3A;font-size:22px}p{color:#5B6B85;margin:0 0 20px;line-height:1.45}'
            .'.b{display:block;text-decoration:none;border:1.5px solid #DCE3EF;border-radius:14px;padding:14px;margin:10px 0;color:#0B1B3A;font-weight:600}'
            .'.b.m{background:#2E6BE6;border-color:#2E6BE6;color:#fff}'
            .'</style></head><body><main class="c"><div class="i" aria-hidden="true">✉</div><h1>'.$l.'</h1>'
            .'<p>Ouvrez '.$l.' dans l\'application Yomara ERP, ou continuez dans le navigateur.</p>'
            .$btn($auto ?? $deep, 'Ouvrir dans l\'application', true)
            .($store ? $btn($store, 'Installer depuis '.$storeName) : '')
            .$btn($web, 'Continuer dans le navigateur')
            .'</main>'.($auto ? '<script>setTimeout(function(){location.href='.json_encode($auto).'},300)</script>' : '').'</body></html>';
    }

    protected static function page(string $os, array $links): string
    {
        $name = e((string) config('erp.name', 'Yomara ERP'));
        $pc = [
            'windows' => ['Microsoft Store', 'Pour Windows 10 et 11 : installation et mises à jour automatiques.', $links['windows']],
            'linux' => ['Linux', 'Application pour Ubuntu, Debian, Fedora et les autres distributions.', $links['linux']],
            'source' => ['Édition libre (open source)', 'Le code source complet, à installer sur votre propre serveur.', $links['source']],
        ];
        // Le système détecté passe en premier et ressort
        $order = $os === 'linux' ? ['linux', 'windows', 'source'] : ['windows', 'linux', 'source'];
        $cards = '';
        foreach ($order as $i => $k) {
            [$title, $desc, $href] = $pc[$k];
            if (! $href) {
                continue;
            }
            $main = $i === 0 && in_array($os, ['windows', 'linux'], true);
            $cards .= '<a class="opt'.($main ? ' main' : '').'" href="'.e($href).'" rel="noopener">'
                .'<span class="t">'.e($title).($main ? ' <em>Votre ordinateur</em>' : '').'</span><span class="d">'.e($desc).'</span></a>';
        }
        $phones = '';
        foreach (['ios' => 'iPhone et iPad', 'android' => 'Android', 'mac' => 'Mac'] as $k => $label) {
            if (! empty($links[$k])) {
                $phones .= '<a href="'.e($links[$k]).'" rel="noopener">'.e($label).'</a>';
            }
        }

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Télécharger '.$name.'</title><meta name="robots" content="noindex"><style>'
            .':root{--ink:#0B1B3A;--muted:#5B6B85;--blue:#2E6BE6;--line:#DCE3EF}'
            .'*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:system-ui,"Segoe UI",sans-serif;background:linear-gradient(160deg,#0B1B3A,#2E6BE6);display:flex;align-items:center;justify-content:center;padding:16px}'
            .'.card{background:#fff;border-radius:22px;padding:28px;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.25)}'
            .'.logo{width:56px;height:56px;border-radius:16px;background:#FFC93C;color:var(--ink);font-size:30px;font-weight:900;line-height:56px;text-align:center;margin-bottom:14px}'
            .'h1{margin:0 0 6px;color:var(--ink);font-size:22px}p{color:var(--muted);margin:0 0 18px;line-height:1.45}'
            .'.opt{display:block;text-decoration:none;border:1.5px solid var(--line);border-radius:14px;padding:14px 16px;margin:10px 0;color:var(--ink)}'
            .'.opt:hover,.opt:focus-visible{border-color:var(--blue);outline:none}.opt.main{border-color:var(--blue);background:#EEF3FD}'
            .'.t{display:block;font-weight:700;font-size:16px}.t em{font-style:normal;font-size:12px;font-weight:600;color:#fff;background:var(--blue);border-radius:20px;padding:2px 8px;margin-left:6px;vertical-align:2px}'
            .'.d{display:block;color:var(--muted);font-size:14px;margin-top:3px}'
            .'.other{margin-top:18px;padding-top:14px;border-top:1px solid var(--line);font-size:14px;color:var(--muted)}.other a{color:var(--blue);font-weight:600;margin-right:14px;text-decoration:none}'
            .'</style></head><body><main class="card"><div class="logo" aria-hidden="true">Y</div>'
            .'<h1>Télécharger '.$name.'</h1><p>Choisissez la version pour votre ordinateur. Le téléchargement démarre depuis la boutique ou le site officiel.</p>'
            .$cards
            .($phones !== '' ? '<div class="other">Autres appareils : '.$phones.'</div>' : '')
            .'</main></body></html>';
    }
}
