<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

/** Orange Money Web Payment (API Orange Developer). En mode test, la devise est « OUV ». */
class OrangeMoney extends Gateway
{
    protected function token(): string
    {
        $res = Http::asForm()->acceptJson()->timeout(30)
            ->withBasicAuth($this->key('client_id'), $this->key('client_secret'))
            ->post('https://api.orange.com/oauth/v3/token', ['grant_type' => 'client_credentials'])->json();
        if (empty($res['access_token'])) {
            throw new PaymentException('Orange Money : authentification refusée.');
        }

        return $res['access_token'];
    }

    protected function base(): string
    {
        $country = strtolower((string) $this->config->credential('country', 'ci')) ?: 'ci';

        return 'https://api.orange.com/orange-money-webpay/'.($this->test() ? 'dev' : $country).'/v1';
    }

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $orderId = substr(str_replace('-', '', $tx->id), 0, 30);
        $res = Http::acceptJson()->timeout(30)->withToken($this->token())->post($this->base().'/webpayment', [
            'merchant_key' => $this->key('merchant_key'),
            'currency' => $this->test() ? 'OUV' : strtoupper($tx->currency),
            'order_id' => $orderId,
            'amount' => (int) round($tx->amount),
            'return_url' => $ctx->returnUrl,
            'cancel_url' => $ctx->cancelUrl,
            'notif_url' => $ctx->notifyUrl,
            'lang' => 'fr',
            'reference' => mb_substr($ctx->merchantName, 0, 30),
        ])->json();
        if (empty($res['payment_url'])) {
            throw new PaymentException('Orange Money : '.($res['message'] ?? 'paiement refusé'));
        }

        return ['url' => $res['payment_url'], 'reference' => $orderId, 'meta' => ['pay_token' => $res['pay_token'] ?? null, 'notif_token' => $res['notif_token'] ?? null]];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $res = Http::acceptJson()->timeout(30)->withToken($this->token())->post($this->base().'/transactionstatus', [
            'order_id' => $tx->reference, 'amount' => (int) round($tx->amount), 'pay_token' => $tx->meta['pay_token'] ?? null,
        ])->json();

        return match (strtoupper((string) ($res['status'] ?? ''))) {
            'SUCCESS' => 'paid',
            'FAILED', 'EXPIRED' => 'failed',
            default => 'pending',
        };
    }

    public function check(): string
    {
        $this->token();
        $this->key('merchant_key');

        return 'Identifiants Orange Developer valides (jeton obtenu). La clé marchand sera vérifiée au premier paiement.';
    }
}
