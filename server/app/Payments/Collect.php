<?php

namespace App\Payments;

use App\Models\Company;
use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Str;

/**
 * Encaissement Mobile Money au comptoir ou depuis une facture :
 *  - prestataire « direct » (MTN MoMo) : demande envoyée sur le téléphone du client, qui valide avec son code ;
 *  - autres prestataires (Wave, Orange Money, CinetPay…) : QR code / lien de paiement que le client scanne.
 * La caisse suit l'état toutes les 3 secondes ; un paiement de facture est enregistré automatiquement.
 */
class Collect
{
    public function __construct(protected PaymentService $payments) {}

    public static function gateways(Company $c)
    {
        return PaymentGateway::where('company_id', $c->id)->where('enabled', true)->whereIn('driver', InvoicePayments::allowedDrivers())->orderBy('sort')->orderBy('id')->get()
            ->filter(fn ($g) => $g->isOnline())->values();
    }

    public function start(Company $c, int $gatewayId, float $amount, ?string $phone, ?string $documentId, ?string $label, ?int $userId): PaymentTransaction
    {
        $gw = self::gateways($c)->firstWhere('id', $gatewayId);
        if (! $gw) {
            throw new PaymentException('Moyen de paiement indisponible.');
        }
        $doc = $documentId ? SalesDocument::find($documentId) : null;
        if ($documentId && ! $doc) {
            throw new PaymentException('Facture introuvable.');
        }
        if ($doc) {
            if ($doc->status !== 'validated') {
                throw new PaymentException('Validez la facture avant de l\'encaisser.');
            }
            $amount = min($amount ?: $doc->balance(), $doc->balance());
        }
        if ($amount <= 0) {
            throw new PaymentException('Montant à encaisser nul.');
        }
        if (GatewayRegistry::isPush($gw->driver) && ! $phone) {
            throw new PaymentException('Indiquez le numéro de téléphone du client.');
        }
        $purpose = $doc ? 'invoice' : 'collect';
        $tx = $this->payments->start($gw, $purpose, $doc?->id ?? (string) Str::uuid(), round($amount, 2), $doc?->currency ?: $c->currency, $c->id, fn (PaymentTransaction $tx) => new PaymentContext(
            description: $doc ? 'Facture '.$doc->number.' — '.$c->name : ($label ?: 'Achat chez '.$c->name),
            returnUrl: url('/payments/'.$tx->id.'/return'),
            notifyUrl: url('/api/payments/'.$tx->id.'/notify'),
            cancelUrl: url('/payments/'.$tx->id.'/return?cancel=1'),
            customerName: $doc?->partner_name, customerEmail: $doc?->partner?->email, customerPhone: $phone ?: $doc?->partner?->phone,
            merchantName: $c->name,
        ));
        $tx->forceFill(['meta' => array_merge(['phone' => $phone], $tx->meta ?? [], ['user_id' => $userId, 'label' => $label])])->save();

        return $tx;
    }

    public function view(PaymentTransaction $tx): array
    {
        $push = GatewayRegistry::isPush($tx->gateway->driver);

        return [
            'id' => $tx->id, 'status' => $tx->status, 'amount' => (float) $tx->amount, 'currency' => $tx->currency, 'reference' => $tx->reference,
            'gateway' => $tx->gateway->label, 'driver' => $tx->gateway->driver, 'mode' => $push ? 'push' : 'qr', 'url' => $tx->payment_url,
            'method' => GatewayRegistry::paymentMethod($tx->gateway->driver), 'paid_at' => $tx->paid_at?->toIso8601String(),
            'error' => $tx->meta['error'] ?? null, 'phone' => $tx->meta['phone'] ?? null,
        ];
    }
}
