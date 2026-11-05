<?php

namespace App\Payments;

use App\Models\Company;
use App\Models\PaymentTransaction;
use App\Modules\Core\Models\Partner;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use App\Services\EntityWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Liens de paiement libres : un montant (fixe ou au choix du payeur) à régler en ligne sans facture préalable
 * (acompte, prestation, commande Instagram, cotisation, don). Le paiement confirmé crée la facture réglée,
 * enregistrée sur le compte de trésorerie du prestataire.
 */
class PaymentLinks
{
    public function __construct(protected PaymentService $payments) {}

    public static function url(object $link): string
    {
        return url('/pay/l/'.$link->token);
    }

    public function create(Company $c, array $d, ?int $userId): object
    {
        $id = DB::table('pay_links')->insertGetId([
            'company_id' => $c->id, 'token' => Str::random(40), 'label' => Str::limit(trim($d['label']), 160, ''), 'description' => $d['description'] ?? null,
            'amount' => isset($d['amount']) && $d['amount'] !== null ? round((float) $d['amount'], 2) : null, 'min_amount' => $d['min_amount'] ?? null,
            'currency' => $c->currency, 'tax_rate' => (float) ($d['tax_rate'] ?? 0), 'partner_id' => $d['partner_id'] ?? null,
            'customer_name' => $d['customer_name'] ?? null, 'customer_phone' => $d['customer_phone'] ?? null, 'customer_email' => $d['customer_email'] ?? null,
            'reusable' => (bool) ($d['reusable'] ?? false), 'expires_on' => $d['expires_on'] ?? null, 'status' => 'open', 'user_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('pay_links')->find($id);
    }

    public function toApi(object $l): array
    {
        return ['id' => $l->id, 'label' => $l->label, 'description' => $l->description, 'amount' => $l->amount !== null ? (float) $l->amount : null,
            'min_amount' => $l->min_amount !== null ? (float) $l->min_amount : null, 'currency' => $l->currency, 'tax_rate' => (float) $l->tax_rate,
            'customer_name' => $l->customer_name, 'customer_phone' => $l->customer_phone, 'customer_email' => $l->customer_email, 'partner_id' => $l->partner_id,
            'reusable' => (bool) $l->reusable, 'status' => $this->status($l), 'expires_on' => $l->expires_on, 'payments_count' => (int) $l->payments_count,
            'collected' => (float) $l->collected, 'paid_at' => $l->paid_at, 'url' => self::url($l), 'created_at' => $l->created_at,
            'last_document' => $l->last_document_id ? DB::table('trade_documents')->where('id', $l->last_document_id)->first(['id', 'number']) : null];
    }

    public function status(object $l): string
    {
        return $l->status === 'open' && $l->expires_on && $l->expires_on < now()->toDateString() ? 'expired' : $l->status;
    }

    /** @return array{0: Company, 1: object} */
    public function resolve(string $token): array
    {
        $l = DB::table('pay_links')->where('token', $token)->first();
        abort_unless($l, 404);
        $c = Company::findOrFail($l->company_id);
        app()->instance('tenant', $c);

        return [$c, $l];
    }

    public function start(string $token, int $gatewayId, ?float $amount, array $payer): PaymentTransaction
    {
        [$c, $l] = $this->resolve($token);
        if ($this->status($l) !== 'open') {
            throw new PaymentException($l->status === 'paid' ? 'Ce paiement a déjà été effectué. Merci !' : 'Ce lien de paiement n\'est plus actif.');
        }
        $due = $l->amount !== null ? (float) $l->amount : round((float) $amount, 2);
        if ($due <= 0 || ($l->min_amount !== null && $due < (float) $l->min_amount)) {
            throw new PaymentException('Indiquez un montant'.($l->min_amount !== null ? ' d\'au moins '.number_format((float) $l->min_amount, 0, ',', ' ') : '').'.');
        }
        $gw = InvoicePayments::gateways($c)->firstWhere('id', $gatewayId) ?? throw new PaymentException('Moyen de paiement indisponible.');
        $name = trim((string) ($payer['name'] ?? '')) ?: $l->customer_name;
        $phone = trim((string) ($payer['phone'] ?? '')) ?: $l->customer_phone;
        $email = filter_var($payer['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: $l->customer_email;

        $tx = $this->payments->start($gw, 'pay_link', (string) $l->id, $due, $l->currency ?: $c->currency, $c->id, fn (PaymentTransaction $tx) => new PaymentContext(
            description: Str::limit($l->label.' — '.$c->name, 120, ''), returnUrl: url('/payments/'.$tx->id.'/return'), notifyUrl: url('/api/payments/'.$tx->id.'/notify'),
            cancelUrl: url('/payments/'.$tx->id.'/return?cancel=1'), customerName: $name, customerEmail: $email, customerPhone: $phone, merchantName: $c->name,
        ));
        $tx->forceFill(['meta' => array_merge((array) $tx->meta, ['payer' => array_filter(['name' => $name, 'phone' => $phone, 'email' => $email])])])->save();

        return $tx;
    }

    /** Paiement confirmé : facture réglée au nom du payeur, sur le compte de trésorerie du prestataire. */
    public static function record(PaymentTransaction $tx): void
    {
        $l = DB::table('pay_links')->find((int) $tx->purpose_id);
        $c = $l ? Company::find($l->company_id) : null;
        if (! $c) {
            return;
        }
        app()->instance('tenant', $c);
        $payer = (array) data_get($tx->meta, 'payer', []);
        $partner = $l->partner_id ? Partner::find($l->partner_id) : null;
        if (! $partner) {
            $email = $payer['email'] ?? null;
            $phone = $payer['phone'] ?? null;
            $digits = substr(preg_replace('/\D/', '', (string) $phone), -8);
            $partner = ($email ? Partner::whereRaw('lower(email) = ?', [mb_strtolower($email)])->first() : null)
                ?? (strlen($digits) === 8 ? Partner::whereNotNull('phone')->where('phone', 'like', '%'.substr($digits, -4))->get()->first(fn ($x) => substr(preg_replace('/\D/', '', (string) $x->phone), -8) === $digits) : null)
                ?? Partner::create(['name' => Str::limit(($payer['name'] ?? '') ?: 'Client en ligne', 160, ''), 'type' => 'customer', 'phone' => $phone, 'email' => $email,
                    'notes' => 'Client créé par le lien de paiement « '.$l->label.' »']);
        }
        $rate = (float) $l->tax_rate;
        $docId = (string) Str::uuid();
        SalesDocument::$systemWrite = true;
        SalesPayment::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(SalesDocument::class, $docId, [
                'type' => 'invoice', 'partner_id' => $partner->id, 'date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'status' => 'validated',
                'reference' => Str::limit('Lien '.$l->id.' · '.$tx->reference, 60, ''), 'notes' => 'Payé en ligne par le lien « '.$l->label.' »',
                'lines' => [['id' => (string) Str::uuid(), 'description' => Str::limit($l->label, 250, ''), 'quantity' => 1, 'unit_price' => round((float) $tx->amount / (1 + $rate / 100), 4), 'tax_rate' => $rate, 'discount_percent' => 0]],
            ]);
            if ($res['status'] !== EntityWriter::APPLIED) {
                throw new \RuntimeException('Facture du lien de paiement refusée : '.($res['message'] ?? ''));
            }
            $doc = SalesDocument::find($docId);
            app(EntityWriter::class)->upsert(SalesPayment::class, (string) Str::uuid(), array_filter([
                'document_id' => $docId, 'date' => now()->toDateString(), 'amount' => $doc->balance(), 'method' => GatewayRegistry::paymentMethod($tx->gateway->driver),
                'reference' => $tx->gateway->label.' · '.$tx->reference, 'notes' => 'Paiement en ligne', 'treasury_account_id' => $tx->gateway->treasury_account_id,
            ], fn ($v) => $v !== null));
        } finally {
            SalesDocument::$systemWrite = false;
            SalesPayment::$systemWrite = false;
        }
        DB::table('pay_links')->where('id', $l->id)->update(['payments_count' => $l->payments_count + 1, 'collected' => (float) $l->collected + (float) $tx->amount,
            'last_document_id' => $docId, 'status' => $l->reusable ? 'open' : 'paid', 'paid_at' => now(), 'updated_at' => now()]);
        if ($l->user_id) {
            \App\Models\AppNotification::send($c->id, [$l->user_id], 'payment', 'Paiement reçu : '.number_format((float) $tx->amount, 0, ',', ' ').' '.($l->currency === 'XOF' ? 'F' : $l->currency),
                ($payer['name'] ?? $partner->name).' · '.$l->label, 'ventes', $docId);
        }
    }
}
