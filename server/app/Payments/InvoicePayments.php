<?php

namespace App\Payments;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\InvoicePayLink;
use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use App\Models\PlatformSetting;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use Illuminate\Support\Str;

/** Paiement en ligne des factures d'une société, avec ses propres comptes (CinetPay, Wave…). */
class InvoicePayments
{
    public function __construct(protected PaymentService $payments) {}

    /** Prestataires que les sociétés ont le droit de configurer (choisis par le super admin). */
    public static function allowedDrivers(): array
    {
        return (array) PlatformSetting::get('payments.company_drivers', array_keys(GatewayRegistry::DRIVERS));
    }

    public function link(SalesDocument $doc): InvoicePayLink
    {
        return InvoicePayLink::firstOrCreate(['document_id' => $doc->id], ['company_id' => $doc->company_id, 'token' => Str::random(40)]);
    }

    public static function url(InvoicePayLink $link): string
    {
        return url('/pay/'.$link->token);
    }

    /** @return array{0: Company, 1: SalesDocument} */
    public function resolve(string $token): array
    {
        $link = InvoicePayLink::where('token', $token)->firstOrFail();
        $company = Company::findOrFail($link->company_id);
        app()->instance('tenant', $company);
        $doc = SalesDocument::with('lines')->findOrFail($link->document_id);

        return [$company, $doc];
    }

    public static function gateways(Company $company)
    {
        // Les paiements « directs » (demande sur le téléphone) se lancent depuis la caisse ou la facture, pas depuis la page publique
        return PaymentGateway::where('company_id', $company->id)->where('enabled', true)
            ->whereIn('driver', self::allowedDrivers())->orderBy('sort')->orderBy('id')->get()->reject(fn ($g) => GatewayRegistry::isPush($g->driver))->values();
    }

    /** Montant proposé : l'échéance (échéancier) ou le solde. */
    public function amountFor(SalesDocument $doc, string $mode = 'due'): float
    {
        return $mode === 'all' || ! $doc->schedule ? $doc->balance() : $doc->amountDueNow();
    }

    public function start(string $token, int $gatewayId, string $mode = 'due'): PaymentTransaction
    {
        [$company, $doc] = $this->resolve($token);
        if (! in_array($doc->type, ['invoice', 'receipt', 'deposit'], true) || $doc->status !== 'validated') {
            throw new PaymentException('Ce document ne peut pas être réglé en ligne.');
        }
        $due = $this->amountFor($doc, $mode);
        if ($due <= 0) {
            throw new PaymentException('Cette facture est déjà réglée. Merci !');
        }
        $gw = self::gateways($company)->firstWhere('id', $gatewayId);
        if (! $gw) {
            throw new PaymentException('Moyen de paiement indisponible.');
        }

        return $this->payments->start($gw, 'invoice', $doc->id, $due, $doc->currency ?: $company->currency, $company->id, fn (PaymentTransaction $tx) => new PaymentContext(
            description: 'Facture '.$doc->number.' — '.$company->name,
            returnUrl: url('/payments/'.$tx->id.'/return'),
            notifyUrl: url('/api/payments/'.$tx->id.'/notify'),
            cancelUrl: url('/payments/'.$tx->id.'/return?cancel=1'),
            customerName: $doc->partner_name, customerEmail: $doc->partner?->email, customerPhone: $doc->partner?->phone,
            merchantName: $company->name,
        ));
    }

    /** Paiement confirmé : encaissement enregistré sur la facture (et comptabilisé si la compta est active). */
    public static function record(PaymentTransaction $tx): void
    {
        $company = Company::find($tx->company_id);
        if (! $company) {
            return;
        }
        app()->instance('tenant', $company);
        $doc = SalesDocument::find($tx->purpose_id);
        if (! $doc) {
            return;
        }
        $p = new SalesPayment([
            'document_id' => $doc->id, 'date' => now()->toDateString(), 'amount' => $tx->amount,
            'method' => GatewayRegistry::paymentMethod($tx->gateway->driver),
            'reference' => $tx->gateway->label.' · '.$tx->reference,
            'notes' => 'Paiement en ligne',
            'treasury_account_id' => $tx->gateway->treasury_account_id, // rapprochement : le paiement arrive sur le compte du prestataire
        ]);
        $p->partner_id = $doc->partner_id;
        $p->save();
        $doc->refreshPaymentStatus();
        ActivityLog::record('invoice.paid_online', $doc, ['amount' => $tx->amount, 'gateway' => $tx->gateway->driver]);
    }
}
