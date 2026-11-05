<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use Illuminate\Support\Facades\Http;

/**
 * PayGate Global — Flooz (Moov) et T-Money (Togocel), Togo. Page de paiement hébergée, référence = notre identifiant.
 * Pas d'environnement de test chez PayGate.
 */
class PayGate extends Gateway
{
    /** La page PayGate prend le montant dans l'URL et le statut ne le renvoie pas : achats de modules validés à la main. */
    public const AMOUNT_UNVERIFIABLE = true;

    protected const BASE = 'https://paygateglobal.com/api';

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $url = self::BASE.'/v1/page?'.http_build_query(array_filter([
            'token' => $this->key('auth_token'),
            'auth_token' => $this->key('auth_token'),
            'amount' => (int) round($tx->amount),
            'description' => mb_strimwidth($ctx->description, 0, 120, ''),
            'identifier' => (string) $tx->id,
            'url' => $ctx->returnUrl,
            'phone' => $ctx->customerPhone ? preg_replace('/\D+/', '', $ctx->customerPhone) : null,
        ]));

        return ['url' => $url, 'reference' => (string) $tx->id];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $res = Http::acceptJson()->asJson()->timeout(30)->post(self::BASE.'/v2/status', ['auth_token' => $this->key('auth_token'), 'identifier' => $tx->reference])->json();

        return match (isset($res['status']) ? (int) $res['status'] : null) {
            0 => 'paid',
            4, 6 => 'failed',
            default => 'pending',
        };
    }
}
