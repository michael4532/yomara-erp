<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Écritures rejouées par l'application (hors ligne, délai dépassé) : une même clé « Idempotency-Key »
 * renvoie la première réponse au lieu de recréer la fiche. Clé propre à chaque jeton de connexion, gardée 24 h.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next)
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        $token = $request->bearerToken();
        if ($key === '' || ! $token || strlen($key) > 100 || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }
        // Par personne (et non par jeton) : une saisie rejouée après une reconnexion n'est pas créée deux fois
        $u = rescue(fn () => $request->user('sanctum'), null, false);
        $who = $u ? 'u'.$u->getKey().'|'.$request->header('X-Company-Id', '') : $token;
        $ck = 'idem:'.hash('sha256', $who.'|'.$request->method().'|'.$request->path().'|'.$key);
        if ($hit = Cache::get($ck)) {
            return response($hit['body'], $hit['status'], ['Content-Type' => $hit['type'] ?: 'application/json', 'Idempotent-Replay' => 'true']);
        }
        $res = $next($request);
        $status = $res->getStatusCode();
        // On ne mémorise que les réponses définitives (pas les erreurs serveur ni « trop de requêtes »)
        if ($status < 500 && $status !== 429 && ! $res instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            && ! $res instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            Cache::put($ck, ['status' => $status, 'body' => $res->getContent(), 'type' => $res->headers->get('Content-Type')], now()->addDay());
        }

        return $res;
    }
}
