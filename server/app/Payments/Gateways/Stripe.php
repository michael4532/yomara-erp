<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

/** Stripe Checkout (le franc CFA est une devise sans décimales chez Stripe). */
class Stripe extends Gateway
{
    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $res = Http::asForm()->acceptJson()->timeout(30)->withToken($this->key('secret_key'))->post('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'payment',
            'client_reference_id' => $tx->id,
            'success_url' => $ctx->returnUrl,
            'cancel_url' => $ctx->cancelUrl,
            'customer_email' => $ctx->customerEmail,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($tx->currency),
            'line_items[0][price_data][unit_amount]' => self::minor($tx->amount, $tx->currency),
            'line_items[0][price_data][product_data][name]' => mb_substr($ctx->description, 0, 250),
        ])->json();
        if (empty($res['url']) || empty($res['id'])) {
            throw new PaymentException('Stripe : '.($res['error']['message'] ?? 'session refusée'));
        }

        return ['url' => $res['url'], 'reference' => $res['id']];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $res = Http::acceptJson()->timeout(30)->withToken($this->key('secret_key'))->get('https://api.stripe.com/v1/checkout/sessions/'.$tx->reference)->json();
        if (($res['payment_status'] ?? null) === 'paid') {
            // Montants Stripe en plus petite unité, sauf devises sans décimales (XOF, XAF…)
            $cur = strtoupper((string) ($res['currency'] ?? ''));
            $zero = in_array($cur, ['XOF', 'XAF', 'JPY', 'KRW', 'GNF', 'RWF', 'UGX', 'VND', 'BIF', 'CLP', 'DJF', 'KMF', 'MGA', 'PYG', 'VUV', 'XPF'], true);
            $this->confirm(isset($res['amount_total']) ? $res['amount_total'] / ($zero ? 1 : 100) : null, $cur, $res['client_reference_id'] ?? null);

            return 'paid';
        }

        return ($res['status'] ?? null) === 'expired' ? 'failed' : 'pending';
    }

    public function check(): string
    {
        $res = Http::acceptJson()->timeout(20)->withToken($this->key('secret_key'))->get('https://api.stripe.com/v1/balance');
        if (! $res->successful()) {
            throw new PaymentException('Stripe refuse la clé : '.($res->json('error.message') ?? 'HTTP '.$res->status()));
        }

        return 'Clé Stripe valide ('.(($res->json('livemode') ?? false) ? 'production' : 'test').').';
    }
}
