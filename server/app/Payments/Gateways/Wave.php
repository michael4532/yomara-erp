<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

/** Wave Business — Checkout API (URLs de retour en https obligatoires). */
class Wave extends Gateway
{
    protected const BASE = 'https://api.wave.com/v1/checkout/sessions';

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $res = Http::acceptJson()->timeout(30)->withToken($this->key('api_key'))->post(self::BASE, [
            'amount' => (string) (int) round($tx->amount),
            'currency' => strtoupper($tx->currency),
            'success_url' => $ctx->returnUrl,
            'error_url' => $ctx->cancelUrl,
            'client_reference' => $tx->id,
        ])->json();
        if (empty($res['wave_launch_url']) || empty($res['id'])) {
            throw new PaymentException('Wave : '.($res['message'] ?? $res['code'] ?? 'session refusée'));
        }

        return ['url' => $res['wave_launch_url'], 'reference' => $res['id']];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $res = Http::acceptJson()->timeout(30)->withToken($this->key('api_key'))->get(self::BASE.'/'.$tx->reference)->json();
        if (($res['payment_status'] ?? null) === 'succeeded') {
            $this->confirm($res['amount'] ?? null, $res['currency'] ?? null, $res['client_reference'] ?? null);

            return 'paid';
        }

        return ($res['checkout_status'] ?? null) === 'expired' ? 'failed' : 'pending';
    }
}
