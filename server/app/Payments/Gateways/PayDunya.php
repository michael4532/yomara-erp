<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

/** PayDunya — Checkout Invoice (HTTP/JSON). */
class PayDunya extends Gateway
{
    protected function base(): string
    {
        return 'https://app.paydunya.com/'.($this->test() ? 'sandbox-api' : 'api').'/v1/checkout-invoice';
    }

    protected function client()
    {
        return Http::acceptJson()->timeout(30)->withHeaders([
            'PAYDUNYA-MASTER-KEY' => $this->key('master_key'),
            'PAYDUNYA-PRIVATE-KEY' => $this->key('private_key'),
            'PAYDUNYA-TOKEN' => $this->key('token'),
        ]);
    }

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $res = $this->client()->post($this->base().'/create', [
            'invoice' => ['total_amount' => (int) round($tx->amount), 'description' => $ctx->description],
            'store' => ['name' => $ctx->merchantName],
            'actions' => ['cancel_url' => $ctx->cancelUrl, 'return_url' => $ctx->returnUrl, 'callback_url' => $ctx->notifyUrl],
            'custom_data' => ['transaction' => $tx->id],
        ])->json();

        if (($res['response_code'] ?? null) !== '00' || empty($res['token'])) {
            throw new PaymentException('PayDunya : '.($res['response_text'] ?? 'initialisation refusée'));
        }

        return ['url' => $res['response_text'], 'reference' => $res['token']];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $res = $this->client()->get($this->base().'/confirm/'.$tx->reference)->json();

        return match (strtolower((string) ($res['status'] ?? ''))) {
            'completed' => 'paid',
            'cancelled', 'canceled', 'failed' => 'failed',
            default => 'pending',
        };
    }
}
