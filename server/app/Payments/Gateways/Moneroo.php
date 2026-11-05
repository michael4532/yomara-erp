<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

/**
 * Moneroo — agrégateur (Mobile Money, cartes) de plus de 30 pays africains. https://docs.moneroo.io
 * Mode test : clés de test (même adresse d'API).
 */
class Moneroo extends Gateway
{
    protected const BASE = 'https://api.moneroo.io/v1';

    protected function http()
    {
        return Http::acceptJson()->asJson()->timeout(30)->withToken($this->key('secret_key'));
    }

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $name = trim((string) $ctx->customerName) ?: 'Client';
        $parts = preg_split('/\s+/', $name, 2);
        $res = $this->http()->post(self::BASE.'/payments/initialize', [
            'amount' => self::minor($tx->amount, $tx->currency),
            'currency' => strtoupper($tx->currency),
            'description' => mb_strimwidth($ctx->description, 0, 250, ''),
            'return_url' => $ctx->returnUrl,
            'customer' => array_filter([
                'email' => $ctx->customerEmail ?: 'client@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'example.com'),
                'first_name' => $parts[0],
                'last_name' => $parts[1] ?? $parts[0],
                'phone' => $ctx->customerPhone ? preg_replace('/\D+/', '', $ctx->customerPhone) : null,
            ]),
            'metadata' => ['tx' => (string) $tx->id],
        ])->json();
        $url = $res['data']['checkout_url'] ?? $res['checkout_url'] ?? null;
        $id = $res['data']['id'] ?? $res['id'] ?? null;
        if (! $url || ! $id) {
            throw new PaymentException('Moneroo : '.($res['message'] ?? 'paiement refusé'));
        }

        return ['url' => $url, 'reference' => (string) $id];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $res = $this->http()->get(self::BASE.'/payments/'.rawurlencode($tx->reference).'/verify')->json();
        if (($res['data']['status'] ?? null) === 'success') {
            $cur = $res['data']['currency'] ?? null;
            $this->confirm($res['data']['amount'] ?? null, is_array($cur) ? ($cur['code'] ?? null) : $cur);
        }

        return match ($res['data']['status'] ?? null) {
            'success' => 'paid',
            'failed', 'cancelled' => 'failed',
            default => 'pending',
        };
    }
}
