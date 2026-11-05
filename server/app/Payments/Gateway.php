<?php

namespace App\Payments;

use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;

/**
 * Prestataire de paiement en ligne.
 *  - initiate() : crée le paiement chez le prestataire et renvoie l'URL où envoyer le payeur ;
 *  - verify()   : interroge le prestataire (on ne fait jamais confiance au seul retour navigateur).
 */
abstract class Gateway
{
    public function __construct(protected PaymentGateway $config) {}

    /**
     * Ce que le prestataire confirme lors de verify() : montant, devise, notre référence.
     * PaymentService::check() refuse un paiement dont le montant ou la devise ne correspondent pas.
     *
     * @var array{amount?: float, currency?: string, reference?: string}
     */
    public array $confirmed = [];

    /** Le prestataire ne renvoie pas le montant payé (page hébergée non signée) : validation manuelle des achats de modules. */
    public const AMOUNT_UNVERIFIABLE = false;

    protected function confirm(mixed $amount, mixed $currency = null, mixed $reference = null): void
    {
        $this->confirmed = array_filter([
            'amount' => is_numeric($amount) ? (float) $amount : null,
            'currency' => is_string($currency) && $currency !== '' ? strtoupper($currency) : null,
            'reference' => $reference !== null && $reference !== '' ? (string) $reference : null,
        ], fn ($v) => $v !== null);
    }

    /** @return array{url: string, reference: string, meta?: array} */
    abstract public function initiate(PaymentTransaction $tx, PaymentContext $ctx): array;

    /** @return 'paid'|'pending'|'failed' */
    abstract public function verify(PaymentTransaction $tx): string;

    /**
     * Vérifie les clés auprès du prestataire sans créer de paiement.
     * Renvoie un message de confirmation ; lève PaymentException si les clés sont refusées.
     */
    public function check(): string
    {
        return 'Clés enregistrées : elles seront vérifiées lors du premier paiement (ce prestataire ne propose pas de test sans paiement).';
    }

    protected function key(string $k): string
    {
        $v = (string) $this->config->credential($k, '');
        if ($v === '') {
            throw new PaymentException("Clé « $k » manquante dans la configuration de {$this->config->label}.");
        }

        return $v;
    }

    protected function test(): bool
    {
        return $this->config->mode === 'test';
    }

    /** Montant entier (francs CFA) ou en centimes selon la devise. */
    protected static function minor(float $amount, string $currency): int
    {
        $zero = ['XOF', 'XAF', 'GNF', 'RWF', 'UGX', 'KMF', 'JPY', 'BIF', 'DJF', 'CLP', 'PYG', 'VND'];

        return (int) round(in_array(strtoupper($currency), $zero, true) ? $amount : $amount * 100);
    }
}
