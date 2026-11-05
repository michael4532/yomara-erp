<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Payments\Gateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * MTN Mobile Money — API Collections « Request to Pay » : la demande de paiement arrive directement sur le
 * téléphone du client, qui valide avec son code secret (aucune page web, idéal en caisse).
 */
class MtnMomo extends Gateway
{
    public const PUSH = true;

    protected function base(): string
    {
        return $this->test() ? 'https://sandbox.momodeveloper.mtn.com' : 'https://proxy.momoapi.mtn.com';
    }

    protected function environment(): string
    {
        return $this->test() ? 'sandbox' : ((string) $this->config->credential('target_environment', '') ?: 'mtnivorycoast');
    }

    protected function token(): string
    {
        $key = 'mtn-momo-token-'.$this->config->id.'-'.md5($this->key('api_user'));

        return Cache::remember($key, 3000, function () {
            $r = Http::acceptJson()->timeout(30)->withBasicAuth($this->key('api_user'), $this->key('api_key'))
                ->withHeaders(['Ocp-Apim-Subscription-Key' => $this->key('subscription_key')])->post($this->base().'/collection/token/');
            if (! $r->successful() || empty($r['access_token'])) {
                throw new PaymentException('MTN MoMo : authentification refusée ('.$r->status().'). Vérifiez l\'API User, l\'API Key et la clé d\'abonnement.');
            }

            return (string) $r['access_token'];
        });
    }

    protected function headers(): array
    {
        return ['Ocp-Apim-Subscription-Key' => $this->key('subscription_key'), 'X-Target-Environment' => $this->environment()];
    }

    /** Numéro au format international sans « + » (Côte d'Ivoire par défaut : 07 07 00 00 00 → 2250707000000). */
    public static function msisdn(string $phone, string $country = '225'): string
    {
        $d = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 10 && $country === '225') {
            $d = '225'.$d;
        } elseif (strlen($d) <= 9) {
            $d = $country.$d;
        }

        return $d;
    }

    public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array
    {
        if (! $ctx->customerPhone) {
            throw new PaymentException('Indiquez le numéro MTN Mobile Money du client.');
        }
        $ref = (string) Str::uuid();
        $headers = $this->headers() + ['X-Reference-Id' => $ref];
        if (str_starts_with($ctx->notifyUrl, 'https://')) {
            $headers['X-Callback-Url'] = $ctx->notifyUrl;
        }
        $r = Http::acceptJson()->timeout(30)->withToken($this->token())->withHeaders($headers)->post($this->base().'/collection/v1_0/requesttopay', [
            'amount' => (string) self::minor($tx->amount, $tx->currency),
            'currency' => $this->test() ? 'EUR' : strtoupper($tx->currency),
            'externalId' => substr($tx->id, 0, 36),
            'payer' => ['partyIdType' => 'MSISDN', 'partyId' => self::msisdn($ctx->customerPhone)],
            'payerMessage' => mb_substr($ctx->description, 0, 160),
            'payeeNote' => mb_substr($ctx->merchantName, 0, 160),
        ]);
        if ($r->status() !== 202) {
            throw new PaymentException('MTN MoMo : demande refusée ('.$r->status().') '.($r['message'] ?? ''));
        }

        return ['url' => null, 'reference' => $ref, 'meta' => ['push' => true, 'phone' => self::msisdn($ctx->customerPhone)]];
    }

    public function verify(PaymentTransaction $tx): string
    {
        $r = Http::acceptJson()->timeout(30)->withToken($this->token())->withHeaders($this->headers())->get($this->base().'/collection/v1_0/requesttopay/'.$tx->reference);
        if ($r->status() === 404) {
            return 'pending';
        }

        return match ($r['status'] ?? null) {
            'SUCCESSFUL' => 'paid',
            'FAILED', 'REJECTED', 'TIMEOUT' => 'failed',
            default => 'pending',
        };
    }

    public function check(): string
    {
        Cache::forget('mtn-momo-token-'.$this->config->id.'-'.md5($this->key('api_user')));
        $this->token();

        return 'Identifiants MTN MoMo valides ('.($this->test() ? 'bac à sable' : 'production').').';
    }
}
