<?php

namespace App\Payments;

use App\Models\PaymentGateway;
use App\Payments\Gateways;

/** Prestataires pris en charge et champs de configuration attendus. */
class GatewayRegistry
{
    public const DRIVERS = [
        // ---- En ligne ----
        'cinetpay' => [
            'label' => 'CinetPay', 'online' => true, 'class' => Gateways\CinetPay::class,
            'channels' => 'Orange Money, MTN, Moov, Wave, cartes bancaires', 'countries' => 'CI, SN, BF, ML, TG, BJ, CM, NE, GN, CD',
            'fields' => ['apikey' => 'Clé API', 'site_id' => 'Site ID', 'secret_key' => 'Clé secrète (notifications)'],
            'docs' => 'https://docs.cinetpay.com',
        ],
        'paydunya' => [
            'label' => 'PayDunya', 'online' => true, 'class' => Gateways\PayDunya::class,
            'channels' => 'Orange Money, MTN, Moov, Wave, Free Money, cartes', 'countries' => 'SN, CI, BF, BJ, TG, ML',
            'fields' => ['master_key' => 'Clé principale (master key)', 'private_key' => 'Clé privée', 'token' => 'Token'],
            'docs' => 'https://developers.paydunya.com',
        ],
        'fedapay' => [
            'label' => 'FedaPay', 'online' => true, 'class' => Gateways\FedaPay::class,
            'channels' => 'MTN, Moov, Orange, Wave, cartes', 'countries' => 'BJ, CI, SN, TG, NE, BF, GN',
            'fields' => ['secret_key' => 'Clé secrète (sk_…)'],
            'docs' => 'https://docs.fedapay.com',
        ],
        'wave' => [
            'label' => 'Wave', 'online' => true, 'class' => Gateways\Wave::class,
            'channels' => 'Wave', 'countries' => 'CI, SN, ML, BF, UG, GM',
            'fields' => ['api_key' => 'Clé API Wave Business (Checkout)'],
            'docs' => 'https://docs.wave.com/checkout',
        ],
        'orange_money' => [
            'label' => 'Orange Money (Web Payment)', 'online' => true, 'class' => Gateways\OrangeMoney::class,
            'channels' => 'Orange Money', 'countries' => 'CI, SN, ML, CM, GN, MG',
            'fields' => ['client_id' => 'Client ID', 'client_secret' => 'Client secret', 'merchant_key' => 'Merchant key', 'country' => 'Pays (ci, sn, ml…)'],
            'docs' => 'https://developer.orange.com/apis/om-webpay',
        ],
        'mtn_momo' => [
            'label' => 'MTN Mobile Money (paiement direct)', 'online' => true, 'push' => true, 'class' => Gateways\MtnMomo::class,
            'channels' => 'MTN MoMo : demande de paiement sur le téléphone du client', 'countries' => 'CI, CM, BJ, CG, GH, UG, ZM, RW, GN',
            'fields' => ['subscription_key' => 'Clé d\'abonnement Collections (Ocp-Apim-Subscription-Key)', 'api_user' => 'API User (UUID)', 'api_key' => 'API Key', 'target_environment' => 'Environnement (mtnivorycoast, mtncameroon, mtnbenin…)'],
            'docs' => 'https://momodeveloper.mtn.com',
        ],
        'paystack' => [
            'label' => 'Paystack', 'online' => true, 'class' => Gateways\Paystack::class,
            'channels' => 'Cartes, Mobile Money, virement', 'countries' => 'CI, GH, NG, KE, ZA',
            'fields' => ['secret_key' => 'Clé secrète (sk_…)'],
            'docs' => 'https://paystack.com/docs',
        ],
        'flutterwave' => [
            'label' => 'Flutterwave', 'online' => true, 'class' => Gateways\Flutterwave::class,
            'channels' => 'Cartes, Mobile Money, virement', 'countries' => 'CI, SN, CM, GH, NG, KE…',
            'fields' => ['secret_key' => 'Clé secrète (FLWSECK-…)'],
            'docs' => 'https://developer.flutterwave.com',
        ],
        'stripe' => [
            'label' => 'Stripe (cartes internationales)', 'online' => true, 'class' => Gateways\Stripe::class,
            'channels' => 'Visa, Mastercard, Apple Pay, Google Pay', 'countries' => 'International',
            'fields' => ['secret_key' => 'Clé secrète (sk_…)'],
            'docs' => 'https://stripe.com/docs/payments/checkout',
        ],
        'paypal' => [
            'label' => 'PayPal', 'online' => true, 'class' => Gateways\PayPal::class,
            'channels' => 'Compte PayPal, cartes bancaires', 'countries' => 'International (diaspora, clients étrangers)',
            'fields' => ['client_id' => 'Client ID', 'client_secret' => 'Secret', 'currency' => 'Devise de conversion pour le franc CFA (EUR ou USD)', 'rate' => 'Taux : 1 EUR (ou USD) = … F CFA'],
            'docs' => 'https://developer.paypal.com/docs/checkout/',
        ],
        'moneroo' => [
            'label' => 'Moneroo', 'online' => true, 'class' => Gateways\Moneroo::class,
            'channels' => 'Orange Money, MTN, Moov, Wave, Airtel, M-Pesa, cartes (un seul compte pour 30+ pays)', 'countries' => 'CI, SN, BJ, TG, BF, ML, CM, GA, CG, NE, GN, CD, GH, NG, KE…',
            'fields' => ['secret_key' => 'Clé secrète (Moneroo → Développeurs)'],
            'docs' => 'https://docs.moneroo.io',
        ],
        'notchpay' => [
            'label' => 'Notch Pay', 'online' => true, 'class' => Gateways\NotchPay::class,
            'channels' => 'MTN Mobile Money, Orange Money, cartes', 'countries' => 'CM, CI, SN, BJ, TG, GA, CG…',
            'fields' => ['public_key' => 'Clé publique (pk_… ou pk_test_…)'],
            'docs' => 'https://developer.notchpay.co',
        ],
        'kkiapay' => [
            'label' => 'KKiaPay', 'online' => true, 'class' => Gateways\Kkiapay::class,
            'channels' => 'MTN, Moov, Orange, Wave, Free, cartes', 'countries' => 'BJ, CI, TG, SN, BF, NE',
            'fields' => ['public_key' => 'Clé publique', 'private_key' => 'Clé privée', 'secret_key' => 'Clé secrète'],
            'docs' => 'https://docs.kkiapay.me',
        ],
        'paygate' => [
            'label' => 'PayGate Global (Flooz, T-Money)', 'online' => true, 'class' => Gateways\PayGate::class,
            'channels' => 'Flooz (Moov Africa), T-Money (Togocel)', 'countries' => 'TG',
            'fields' => ['auth_token' => 'Clé API (auth_token)'],
            'docs' => 'https://paygateglobal.com',
        ],
        // ---- Hors ligne (validation manuelle) ----
        'merchant_link' => ['label' => 'Lien de paiement marchand (Wave, Orange Money…)', 'online' => false, 'offline_method' => 'mobile_money', 'public_fields' => ['url'],
            'fields' => ['url' => 'Votre lien de paiement (ex. https://pay.wave.com/m/…)'],
            'hint' => 'Le client est envoyé sur votre lien marchand ; vous confirmez le paiement à réception. Indiquez s\'il doit mettre le numéro de facture en référence.'],
        'bank_transfer' => ['label' => 'Virement bancaire', 'online' => false, 'offline_method' => 'bank_transfer', 'fields' => [], 'hint' => 'Indiquez la banque, le titulaire, le RIB / IBAN et le code SWIFT.'],
        'mobile_money' => ['label' => 'Mobile Money (dépôt manuel)', 'online' => false, 'offline_method' => 'mobile_money', 'fields' => [], 'hint' => 'Indiquez les numéros Orange, MTN, Moov ou Wave et le nom du bénéficiaire.'],
        'cash' => ['label' => 'Espèces', 'online' => false, 'offline_method' => 'cash', 'fields' => [], 'hint' => 'Indiquez l\'adresse et les horaires où régler.'],
        'cheque' => ['label' => 'Chèque', 'online' => false, 'offline_method' => 'cheque', 'fields' => [], 'hint' => 'Indiquez l\'ordre et l\'adresse d\'envoi.'],
    ];

    /**
     * Prestataires qui envoient leurs notifications à une adresse unique, déclarée une fois dans leur tableau de bord
     * (événement à cocher). Les autres reçoivent l'adresse de notification avec chaque paiement.
     */
    public const WEBHOOKS = [
        'wave' => 'checkout.session.completed',
        'stripe' => 'checkout.session.completed',
        'paystack' => 'charge.success',
        'flutterwave' => 'charge.completed',
        'fedapay' => 'transaction.approved',
        'moneroo' => 'payment.success',
        'notchpay' => 'payment.complete',
        'kkiapay' => 'transaction.success',
    ];

    public static function meta(string $driver): array
    {
        return self::DRIVERS[$driver] ?? [];
    }

    public static function exists(string $driver): bool
    {
        return isset(self::DRIVERS[$driver]);
    }

    /** Prestataire qui envoie la demande de paiement directement sur le téléphone du client (sans page web). */
    public static function isPush(string $driver): bool
    {
        return (bool) (self::DRIVERS[$driver]['push'] ?? false);
    }

    /** Liste publique (sans la classe PHP). */
    public static function catalog(?array $only = null): array
    {
        $out = [];
        foreach (self::DRIVERS as $key => $m) {
            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }
            unset($m['class']);
            if (isset(self::WEBHOOKS[$key])) {
                $m['webhook_url'] = url('/api/payments/webhook/'.$key);
                $m['webhook_event'] = self::WEBHOOKS[$key];
            }
            $out[] = ['driver' => $key] + $m;
        }

        return $out;
    }

    public static function make(PaymentGateway $config): Gateway
    {
        $class = self::DRIVERS[$config->driver]['class'] ?? null;
        if (! $class) {
            throw new PaymentException('Ce moyen de paiement se règle hors ligne.');
        }

        return app()->makeWith($class, ['config' => $config]);
    }

    /** Moyen d'encaissement enregistré dans les ventes / la comptabilité. */
    public static function paymentMethod(string $driver): string
    {
        return match ($driver) {
            'stripe' => 'card',
            'paystack', 'flutterwave' => 'card',
            'bank_transfer' => 'bank_transfer',
            'cash' => 'cash',
            'cheque' => 'cheque',
            default => 'mobile_money',
        };
    }
}
