<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

class Flutterwave extends Gateway
{
    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $res = Http::acceptJson()->timeout(30)->withToken($this->key('secret_key'))->post('https://api.flutterwave.com/v3/payments', [
            'tx_ref' => $tx->id,
            'amount' => round($tx->amount, 2),
            'currency' => strtoupper($tx->currency),
            'redirect_url' => $ctx->returnUrl,
            'customer' => array_filter(['email' => $ctx->customerEmail ?: 'client@example.com', 'name' => $ctx->customerName, 'phonenumber' => $ctx->customerPhone]),
            'customizations' => ['title' => $ctx->merchantName, 'description' => $ctx->description],
        ])->json();
        if (empty($res['data']['link'])) {
            throw new PaymentException('Flutterwave : '.($res['message'] ?? 'initialisation refusée'));
        }

        return ['url' => $res['data']['link'], 'reference' => $tx->id];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $res = Http::acceptJson()->timeout(30)->withToken($this->key('secret_key'))
            ->get('https://api.flutterwave.com/v3/transactions/verify_by_reference', ['tx_ref' => $tx->reference])->json();
        $data = $res['data'] ?? [];
        if (($data['status'] ?? null) === 'successful' && (float) ($data['amount'] ?? 0) >= $tx->amount - 0.01) {
            return 'paid';
        }

        return ($data['status'] ?? null) === 'failed' ? 'failed' : 'pending';
    }

    public function check(): string
    {
        $res = Http::acceptJson()->timeout(20)->withToken($this->key('secret_key'))->get('https://api.flutterwave.com/v3/balances');
        if (! $res->successful() || $res->json('status') !== 'success') {
            throw new PaymentException('Flutterwave refuse la clé : '.($res->json('message') ?? 'HTTP '.$res->status()));
        }

        return 'Clé Flutterwave valide.';
    }
}
