<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

/** CinetPay Checkout v2 — montant multiple de 5 (XOF, XAF, CDF, GNF, USD). */
class CinetPay extends Gateway
{
    protected const BASE = 'https://api-checkout.cinetpay.com/v2';

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $amount = (int) (ceil($tx->amount / 5) * 5);
        $ref = substr(str_replace('-', '', $tx->id), 0, 20);
        $res = Http::acceptJson()->timeout(30)->post(self::BASE.'/payment', array_filter([
            'apikey' => $this->key('apikey'),
            'site_id' => $this->key('site_id'),
            'transaction_id' => $ref,
            'amount' => $amount,
            'currency' => strtoupper($tx->currency),
            'description' => mb_substr(preg_replace('/[^\pL\pN ._-]/u', ' ', $ctx->description), 0, 120),
            'notify_url' => $ctx->notifyUrl,
            'return_url' => $ctx->returnUrl,
            'channels' => 'ALL',
            'lang' => 'fr',
            'customer_name' => $ctx->customerName,
            'customer_email' => $ctx->customerEmail,
            'customer_phone_number' => $ctx->customerPhone,
        ], fn ($v) => $v !== null))->json();

        $url = $res['data']['payment_url'] ?? null;
        if (! $url) {
            throw new PaymentException('CinetPay : '.($res['description'] ?? $res['message'] ?? 'initialisation refusée'));
        }

        return ['url' => $url, 'reference' => $ref, 'meta' => ['payment_token' => $res['data']['payment_token'] ?? null]];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $res = Http::acceptJson()->timeout(30)->post(self::BASE.'/payment/check', [
            'apikey' => $this->key('apikey'), 'site_id' => $this->key('site_id'), 'transaction_id' => $tx->reference,
        ])->json();
        if (($res['data']['status'] ?? null) === 'ACCEPTED') {
            $this->confirm($res['data']['amount'] ?? null, $res['data']['currency'] ?? null);
        }

        return match ($res['data']['status'] ?? null) {
            'ACCEPTED' => 'paid',
            'REFUSED', 'CANCELED', 'CANCELLED' => 'failed',
            default => 'pending',
        };
    }

    public function check(): string
    {
        // Une transaction inconnue répond « introuvable » si les clés sont bonnes, « authentification » sinon
        $res = Http::acceptJson()->timeout(20)->post(self::BASE.'/payment/check', ['apikey' => $this->key('apikey'), 'site_id' => $this->key('site_id'), 'transaction_id' => 'yomara-test-'.time()])->json();
        $code = (string) ($res['code'] ?? '');
        if (in_array($code, ['608', '609', '613'], true) || str_contains((string) ($res['message'] ?? ''), 'AUTH')) {
            throw new PaymentException('CinetPay refuse la clé API ou le Site ID ('.($res['message'] ?? $code).').');
        }

        return 'Clé API et Site ID CinetPay reconnus.';
    }
}
