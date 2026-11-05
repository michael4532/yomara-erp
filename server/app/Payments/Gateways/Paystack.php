<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

class Paystack extends Gateway
{
    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $res = Http::acceptJson()->timeout(30)->withToken($this->key('secret_key'))->post('https://api.paystack.co/transaction/initialize', [
            'email' => $ctx->customerEmail ?: 'client@example.com',
            'amount' => (int) round($tx->amount * 100), // Paystack : toujours en sous-unités
            'currency' => strtoupper($tx->currency),
            'reference' => $tx->id,
            'callback_url' => $ctx->returnUrl,
            'metadata' => ['description' => $ctx->description],
        ])->json();
        if (empty($res['data']['authorization_url'])) {
            throw new PaymentException('Paystack : '.($res['message'] ?? 'initialisation refusée'));
        }

        return ['url' => $res['data']['authorization_url'], 'reference' => $res['data']['reference'] ?? $tx->id];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $res = Http::acceptJson()->timeout(30)->withToken($this->key('secret_key'))->get('https://api.paystack.co/transaction/verify/'.rawurlencode($tx->reference))->json();

        return match ($res['data']['status'] ?? null) {
            'success' => 'paid',
            'failed', 'abandoned', 'reversed' => 'failed',
            default => 'pending',
        };
    }

    public function check(): string
    {
        $res = Http::acceptJson()->timeout(20)->withToken($this->key('secret_key'))->get('https://api.paystack.co/balance');
        if (! $res->successful() || ! ($res->json('status') ?? false)) {
            throw new PaymentException('Paystack refuse la clé : '.($res->json('message') ?? 'HTTP '.$res->status()));
        }

        return 'Clé Paystack valide ('.(str_starts_with($this->key('secret_key'), 'sk_test') ? 'test' : 'production').').';
    }
}
