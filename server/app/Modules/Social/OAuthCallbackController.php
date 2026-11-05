<?php

namespace App\Modules\Social;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Retour du réseau social après autorisation : page simple qui confirme la connexion et se ferme. */
class OAuthCallbackController extends Controller
{
    public function __invoke(Request $r, SocialOAuth $o, string $provider)
    {
        abort_unless(isset(Networks::PROVIDERS[$provider]), 404);
        $ok = false;
        $list = [];
        if ($r->filled('error') || $r->filled('error_code')) {
            $msg = in_array($r->query('error'), ['access_denied', 'user_denied'], true) ? 'Connexion annulée.'
                : 'Le réseau a refusé la connexion : '.e(mb_substr((string) ($r->query('error_description') ?: $r->query('error_message') ?: $r->query('error')), 0, 300));
        } else {
            try {
                $list = $o->complete($provider, (string) $r->query('state'), (string) $r->query('code'));
                $ok = true;
                $msg = count($list).' compte(s) connecté(s). Vous pouvez fermer cette page et revenir dans Yomara.';
            } catch (\DomainException $e) {
                $msg = e($e->getMessage());
            } catch (\Throwable $e) {
                report($e);
                $msg = 'La connexion n\'a pas abouti. Réessayez dans un instant.';
            }
        }
        $color = $ok ? '#12A150' : '#D93025';
        $items = collect($list)->map(fn ($a) => '<li style="margin:4px 0"><b>'.e($a['label']).'</b> — '.e($a['name']).'</li>')->implode('');

        return response('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Réseaux sociaux</title></head>'
            .'<body style="font-family:system-ui,Segoe UI,sans-serif;background:#F4F6FB;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px">'
            .'<div style="background:#fff;border-radius:18px;padding:32px;max-width:480px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.08)">'
            .'<div style="width:64px;height:64px;border-radius:50%;background:'.$color.'1A;color:'.$color.';font-size:34px;line-height:64px;margin:0 auto 14px">'.($ok ? '✓' : '!').'</div>'
            .'<h2 style="margin:0 0 8px;color:#0B1B3A">'.($ok ? 'Comptes connectés' : 'Connexion impossible').'</h2>'
            .'<p style="color:#5B6B85;line-height:1.5">'.$msg.'</p>'.($items ? '<ul style="text-align:left;color:#0B1B3A;padding-left:20px">'.$items.'</ul>' : '')
            .($ok ? '<script>setTimeout(function(){try{window.close()}catch(e){}},4000)</script>' : '')
            .'</div></body></html>', $ok ? 200 : 422)->header('Content-Type', 'text/html; charset=utf-8')->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'");
    }
}
