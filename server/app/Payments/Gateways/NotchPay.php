<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

/** Notch Pay — Mobile Money (MTN, Orange) et cartes, Cameroun et Afrique centrale. https://developer.notchpay.co */
class NotchPay extends Gateway
{
    protected const BASE = 'https://api.notchpay.co';

    protected function http()
    {
        return Http::acceptJson()->asJson()->timeout(30)->withHeaders(['Authorization' => $this->key('public_key')]);
    }

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $res = $this->http()->post(self::BASE.'/payments', array_filter([
            'amount' => self::minor($tx->amount, $tx->currency),
            'currency' => strtoupper($tx->currency),
            'description' => mb_strimwidth($ctx->description, 0, 250, ''),
            'reference' => (string) $tx->id,
            'callback' => $ctx->returnUrl,
            'customer' => array_filter(['name' => $ctx->customerName, 'email' => $ctx->customerEmail, 'phone' => $ctx->customerPhone]) ?: null,
            'email' => $ctx->customerEmail ?: ($ctx->customerPhone ? null : 'client@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'example.com')),
        ], fn ($v) => $v !== null))->json();
        $url = $res['authorization_url'] ?? null;
        $ref = $res['transaction']['reference'] ?? null;
        if (! $url || ! $ref) {
            throw new PaymentException('Notch Pay : '.($res['message'] ?? 'paiement refusé'));
        }

        return ['url' => $url, 'reference' => (string) $ref];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $res = $this->http()->get(self::BASE.'/payments/'.rawurlencode($tx->reference))->json();
        if (($res['transaction']['status'] ?? null) === 'complete') {
            $this->confirm($res['transaction']['amount'] ?? null, $res['transaction']['currency'] ?? null);
        }

        return match ($res['transaction']['status'] ?? null) {
            'complete' => 'paid',
            'failed', 'canceled', 'cancelled', 'expired' => 'failed',
            default => 'pending',
        };
    }
}
