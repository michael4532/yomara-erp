<?php

namespace App\Payments;

use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cycle d'un paiement en ligne : création → redirection du payeur → vérification chez le prestataire
 * (au retour du navigateur, sur notification, ou à la demande) → traitement idempotent.
 */
class PaymentService
{
    /** Gestionnaires par objet payé : purpose => callable(PaymentTransaction) */
    protected static array $handlers = [];

    public static function onPaid(string $purpose, callable $handler): void
    {
        static::$handlers[$purpose] = $handler;
    }

    public function start(PaymentGateway $gw, string $purpose, string $purposeId, float $amount, string $currency, ?int $companyId, callable $context): PaymentTransaction
    {
        if (! $gw->enabled || ! $gw->isOnline()) {
            throw new PaymentException('Ce moyen de paiement n\'est pas disponible en ligne.');
        }
        if ($amount <= 0) {
            throw new PaymentException('Montant à payer nul.');
        }
        $tx = PaymentTransaction::create([
            'company_id' => $companyId, 'gateway_id' => $gw->id, 'purpose' => $purpose, 'purpose_id' => $purposeId,
            'amount' => $amount, 'currency' => strtoupper($currency), 'status' => 'pending',
        ]);
        /** @var PaymentContext $ctx */
        $ctx = $context($tx);
        try {
            $r = GatewayRegistry::make($gw)->initiate($tx, $ctx);
        } catch (PaymentException $e) {
            $tx->update(['status' => 'failed', 'meta' => ['error' => $e->getMessage()]]);
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Paiement : initialisation impossible', ['gateway' => $gw->driver, 'error' => $e->getMessage()]);
            $tx->update(['status' => 'failed', 'meta' => ['error' => $e->getMessage()]]);
            throw new PaymentException('Le prestataire de paiement ne répond pas. Réessayez dans un instant.');
        }
        $tx->update(['payment_url' => $r['url'], 'reference' => $r['reference'], 'meta' => $r['meta'] ?? null]);

        return $tx->fresh();
    }

    /** Interroge le prestataire et applique le résultat (sans effet si déjà traité). */
    public function check(PaymentTransaction $tx): PaymentTransaction
    {
        if ($tx->status !== 'pending' || ! $tx->reference) {
            return $tx;
        }
        try {
            $gw = GatewayRegistry::make($tx->gateway);
            $status = $gw->verify($tx);
        } catch (\Throwable $e) {
            Log::warning('Paiement : vérification impossible', ['tx' => $tx->id, 'error' => $e->getMessage()]);

            return $tx;
        }
        if ($status === 'pending') {
            return $tx;
        }
        // Le prestataire doit confirmer le montant (et la devise) attendus : sinon le paiement est refusé
        $review = false;
        if ($status === 'paid') {
            $c = $gw->confirmed;
            if (isset($c['amount']) && abs($c['amount'] - (float) $tx->amount) > 0.5) {
                Log::warning('Paiement refusé : montant différent', ['tx' => $tx->id, 'attendu' => (float) $tx->amount, 'reçu' => $c['amount']]);
                $status = 'failed';
            } elseif (isset($c['currency']) && $tx->currency && strtoupper((string) $tx->currency) !== $c['currency'] && ! ($c['currency'] === 'CFA' && in_array(strtoupper((string) $tx->currency), ['XOF', 'XAF'], true))) {
                Log::warning('Paiement refusé : devise différente', ['tx' => $tx->id, 'attendu' => $tx->currency, 'reçu' => $c['currency']]);
                $status = 'failed';
            } elseif (isset($c['reference']) && $c['reference'] !== (string) $tx->id && $c['reference'] !== (string) $tx->reference) {
                Log::warning('Paiement refusé : référence différente', ['tx' => $tx->id, 'reçu' => $c['reference']]);
                $status = 'failed';
            } elseif (! isset($c['amount']) && $gw::AMOUNT_UNVERIFIABLE && $tx->purpose === 'module_order') {
                $review = true;
            }
        }

        return DB::transaction(function () use ($tx, $status, $review) {
            $locked = PaymentTransaction::lockForUpdate()->find($tx->id);
            if ($locked->status !== 'pending') {
                return $locked;
            }
            $locked->update(['status' => $status, 'paid_at' => $status === 'paid' ? now() : null]);
            if ($review) {
                // Montant non confirmable chez ce prestataire : la commande attend la validation de l'éditeur
                \App\Models\ModuleOrder::where('id', $locked->purpose_id)->where('status', 'pending')
                    ->update(['status' => 'awaiting_validation', 'payer_reference' => (string) $locked->reference, 'notes' => 'Paiement confirmé par le prestataire ; montant à contrôler avant activation.']);

                return $locked->fresh();
            }
            if ($status === 'paid' && isset(static::$handlers[$locked->purpose])) {
                (static::$handlers[$locked->purpose])($locked);
            }
            if ($status === 'paid' && $locked->company_id && $locked->purpose !== 'module_order') {
                \App\Integrations\Webhooks::emit((int) $locked->company_id, 'payment.online', ['id' => $locked->id, 'amount' => (float) $locked->amount, 'currency' => $locked->currency,
                    'purpose' => $locked->purpose, 'document_id' => $locked->purpose === 'invoice' ? $locked->purpose_id : null, 'reference' => $locked->reference, 'gateway' => $locked->gateway?->driver]);
            }

            return $locked->fresh();
        });
    }
}
