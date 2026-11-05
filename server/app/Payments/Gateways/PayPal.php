<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

/** PayPal (API Orders v2) : le payeur approuve chez PayPal, le paiement est capturé à la vérification. */
class PayPal extends Gateway
{
    protected function base(): string
    {
        return $this->test() ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    protected function token(): string
    {
        $res = Http::asForm()->acceptJson()->timeout(20)->withBasicAuth($this->key('client_id'), $this->key('client_secret'))
            ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        if (! $res->successful() || ! $res->json('access_token')) {
            throw new PaymentException('PayPal refuse le Client ID ou le secret ('.($res->json('error_description') ?? 'HTTP '.$res->status()).').');
        }

        return (string) $res->json('access_token');
    }

    /** PayPal n'accepte pas le franc CFA : conversion au taux saisi dans la configuration (1 EUR = 655,957 XOF par défaut). */
    protected function convert(float $amount, string $currency): array
    {
        $cur = strtoupper($currency);
        if (in_array($cur, ['XOF', 'XAF'], true)) {
            $target = strtoupper((string) $this->config->credential('currency', 'EUR')) ?: 'EUR';
            $rate = (float) str_replace(',', '.', (string) $this->config->credential('rate', $target === 'EUR' ? '655.957' : '600'));

            return [$target, number_format($amount / max(0.0001, $rate), 2, '.', '')];
        }

        return [$cur, number_format($amount, 2, '.', '')];
    }

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        [$cur, $value] = $this->convert((float) $tx->amount, (string) $tx->currency);
        $res = Http::acceptJson()->timeout(30)->withToken($this->token())->post($this->base().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [['reference_id' => (string) $tx->id, 'description' => mb_substr($ctx->description, 0, 127), 'amount' => ['currency_code' => $cur, 'value' => $value]]],
            'application_context' => ['brand_name' => mb_substr($ctx->merchantName, 0, 127), 'return_url' => $ctx->returnUrl, 'cancel_url' => $ctx->cancelUrl, 'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING'],
        ]);
        $approve = collect($res->json('links') ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
        if (! $res->successful() || ! $approve) {
            throw new PaymentException('PayPal : '.($res->json('message') ?? 'commande refusée'));
        }

        return ['url' => $approve, 'reference' => (string) $res->json('id')];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $token = $this->token();
        $order = Http::acceptJson()->timeout(30)->withToken($token)->get($this->base().'/v2/checkout/orders/'.rawurlencode((string) $tx->reference))->json();
        $status = $order['status'] ?? null;
        if ($status === 'APPROVED') {
            $cap = Http::acceptJson()->timeout(30)->withToken($token)->withBody('{}', 'application/json')->post($this->base().'/v2/checkout/orders/'.rawurlencode((string) $tx->reference).'/capture')->json();
            $status = $cap['status'] ?? $status;
        }

        return match ($status) {
            'COMPLETED' => 'paid',
            'VOIDED' => 'failed',
            default => 'pending',
        };
    }

    public function check(): string
    {
        $this->token();

        return 'Identifiants PayPal valides ('.($this->test() ? 'sandbox' : 'production').').';
    }
}
