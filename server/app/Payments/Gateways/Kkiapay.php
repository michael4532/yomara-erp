<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use Illuminate\Support\Facades\Http;

/**
 * KKiaPay — MTN, Moov, Orange, Wave, cartes (Bénin, Côte d'Ivoire, Togo, Sénégal…).
 * KKiaPay n'a pas de page de paiement hébergée : le payeur passe par notre page qui ouvre le widget officiel ;
 * l'identifiant de transaction KKiaPay est enregistré au retour puis vérifié côté serveur (montant compris).
 */
class Kkiapay extends Gateway
{
    public function base(): string
    {
        return $this->test() ? 'https://api-sandbox.kkiapay.me' : 'https://api.kkiapay.me';
    }

    public function publicKey(): string
    {
        return $this->key('public_key');
    }

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        $this->key('private_key');
        $this->key('secret_key');

        // La référence KKiaPay n'existe qu'après le paiement : elle sera enregistrée au retour du widget
        return ['url' => url('/payments/'.$tx->id.'/kkiapay'), 'reference' => null, 'meta' => ['return_url' => $ctx->returnUrl, 'description' => $ctx->description,
            'name' => $ctx->customerName, 'email' => $ctx->customerEmail, 'phone' => $ctx->customerPhone]];
    }

    public function verify(PaymentTransaction $tx): string
    {
        if (! $tx->reference || $tx->reference === 'kkiapay-pending') {
            return 'pending';
        }
        $res = Http::acceptJson()->asJson()->timeout(30)->withHeaders([
            'X-API-KEY' => $this->key('public_key'), 'X-PRIVATE-KEY' => $this->key('private_key'), 'X-SECRET-KEY' => $this->key('secret_key'),
        ])->post($this->base().'/api/v1/transactions/status', ['transactionId' => $tx->reference])->json();
        $status = $res['status'] ?? null;
        if ($status === 'SUCCESS') {
            // Une transaction KKiaPay rattachée à un autre paiement (identifiant transmis par le widget) ne règle pas celui-ci
            $partner = $res['partnerId'] ?? ($res['data'] ?? null);
            if (is_scalar($partner) && (string) $partner !== '' && (string) $partner !== (string) $tx->id) {
                return 'failed';
            }
            $this->confirm($res['amount'] ?? 0, $res['currency'] ?? null);

            return 'paid';
        }

        return in_array($status, ['FAILED', 'REVERTED', 'INSUFFICIENT_FUND', 'TRANSACTION_NOT_ELIGIBLE'], true) ? 'failed' : 'pending';
    }
}
