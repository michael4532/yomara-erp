<?php

namespace App\Modules\Mailbox;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Retour de Microsoft / Google après la connexion d'un compte : page simple à fermer. */
class OAuthCallbackController extends Controller
{
    public function __invoke(Request $r, string $provider)
    {
        abort_unless(in_array($provider, ['microsoft', 'google'], true), 404);
        $ok = false;
        $msg = '';
        if ($r->filled('error')) {
            $msg = $r->query('error') === 'access_denied' ? 'Connexion annulée.' : 'Le fournisseur a refusé la connexion : '.mb_substr((string) ($r->query('error_description') ?: $r->query('error')), 0, 300);
        } else {
            try {
                $a = OAuth::complete($provider, (string) $r->query('state'), (string) $r->query('code'));
                $ok = true;
                $msg = 'La boîte '.e($a->email).' est connectée. La première synchronisation démarre : vous pouvez fermer cette page et revenir dans Yomara.';
                try {
                    app(MailboxService::class)->sync($a, true);
                } catch (\Throwable) {
                }
            } catch (\DomainException $e) {
                $msg = e($e->getMessage());
            } catch (\Throwable $e) {
                report($e);
                $msg = 'La connexion n\'a pas abouti : '.e(mb_substr($e->getMessage(), 0, 300));
            }
        }
        $color = $ok ? '#12A150' : '#D93025';
        $name = $provider === 'microsoft' ? 'Microsoft 365' : 'Google';

        return response('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion '.$name.'</title></head>'
            .'<body style="font-family:system-ui,Segoe UI,sans-serif;background:#F4F6FB;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
            .'<div style="background:#fff;border-radius:18px;padding:32px;max-width:460px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.08)">'
            .'<div style="width:64px;height:64px;border-radius:50%;background:'.$color.'1A;color:'.$color.';font-size:34px;line-height:64px;margin:0 auto 14px">'.($ok ? '✓' : '!').'</div>'
            .'<h2 style="margin:0 0 8px;color:#0B1B3A">'.($ok ? 'Compte connecté' : 'Connexion impossible').'</h2>'
            .'<p style="color:#5B6B85;line-height:1.5">'.$msg.'</p>'
            .($ok ? '<script>setTimeout(function(){try{window.close()}catch(e){}},2500)</script>' : '')
            .'</div></body></html>', $ok ? 200 : 422)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
