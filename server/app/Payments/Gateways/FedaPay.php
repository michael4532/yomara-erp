<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Http;

/** FedaPay — création de transaction puis jeton de paiement. */
class FedaPay extends Gateway
{
    protected function base(): string
    {
        return $this->test() ? 'https://sandbox-api.fedapay.com/v1' : 'https://api.fedapay.com/v1';
    }

    protected function client()
    {
        return Http::acceptJson()->timeout(30)->withToken($this->key('secret_key'));
    }

    protected static function unwrap(array $res): array
    {
        return $res['v1/transaction'] ?? $res['transaction'] ?? $res;
    }

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $customer = array_filter(['firstname' => $ctx->customerName, 'email' => $ctx->customerEmail]);
        $t = self::unwrap((array) $this->client()->post($this->base().'/transactions', array_filter([
            'description' => $ctx->description,
            'amount' => (int) round($tx->amount),
            'currency' => ['iso' => strtoupper($tx->currency)],
            'callback_url' => $ctx->returnUrl,
            'customer' => $customer ?: null,
        ]))->json());
        if (empty($t['id'])) {
            throw new PaymentException('FedaPay : '.($t['message'] ?? 'transaction refusée'));
        }
        $token = (array) $this->client()->post($this->base().'/transactions/'.$t['id'].'/token')->json();
        if (empty($token['url'])) {
            throw new PaymentException('FedaPay : jeton de paiement non obtenu.');
        }

        return ['url' => $token['url'], 'reference' => (string) $t['id']];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $t = self::unwrap((array) $this->client()->get($this->base().'/transactions/'.$tx->reference)->json());

        return match ($t['status'] ?? null) {
            'approved', 'transferred' => 'paid',
            'declined', 'canceled', 'refunded' => 'failed',
            default => 'pending',
        };
    }

    public function check(): string
    {
        $res = $this->client()->get($this->base().'/accounts');
        if ($res->status() === 401 || $res->status() === 403) {
            throw new PaymentException('FedaPay refuse la clé secrète ('.($this->test() ? 'mode test' : 'production').').');
        }
        if (! $res->successful()) {
            throw new PaymentException('FedaPay : réponse inattendue (HTTP '.$res->status().').');
        }

        return 'Clé FedaPay valide ('.($this->test() ? 'test' : 'production').').';
    }
}
