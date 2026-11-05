<?php

namespace App\Http\Controllers;

use App\Models\ModuleOrder;
use App\Models\PaymentTransaction;
use App\Payments\InvoicePayments;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use Illuminate\Http\Request;

/** Pages publiques : paiement d'une facture par le client final, retour et notifications des prestataires. */
class PublicPaymentController extends Controller
{
    public function __construct(protected InvoicePayments $invoices, protected PaymentService $payments) {}

    public function show(string $token)
    {
        [$company, $doc] = $this->invoices->resolve($token);

        return view('pay.invoice', [
            'company' => $company, 'doc' => $doc, 'due' => $doc->balance(), 'due_now' => $this->invoices->amountFor($doc, 'due'),
            'installments' => $doc->schedule ? $doc->unpaidInstallments() : [],
            'gateways' => InvoicePayments::gateways($company),
            'bank_accounts' => collect((array) data_get($company->settings, 'bank_accounts', []))->filter(fn ($b) => $b['on_invoices'] ?? true)->values(),
            'instructions' => data_get($company->settings, 'payment_instructions'),
            'error' => session('error'),
        ]);
    }

    public function showLink(string $token, \App\Payments\PaymentLinks $links)
    {
        [$company, $link] = $links->resolve($token);

        return view('pay.link', ['company' => $company, 'link' => $link, 'status' => $links->status($link), 'gateways' => InvoicePayments::gateways($company), 'error' => session('error')]);
    }

    public function startLink(Request $request, string $token, int $gateway, \App\Payments\PaymentLinks $links)
    {
        $d = $request->validate(['amount' => 'nullable|numeric|min:1|max:1000000000', 'name' => 'nullable|string|max:160', 'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:160']);
        try {
            $tx = $links->start($token, $gateway, isset($d['amount']) ? (float) $d['amount'] : null, $d);
        } catch (PaymentException $e) {
            return redirect('/pay/l/'.$token)->withInput()->with('error', $e->getMessage());
        }

        return redirect()->away($tx->payment_url);
    }

    public function start(Request $request, string $token, int $gateway)
    {
        try {
            $tx = $this->invoices->start($token, $gateway, $request->input('mode') === 'all' ? 'all' : 'due');
        } catch (PaymentException $e) {
            return redirect('/pay/'.$token)->with('error', $e->getMessage());
        }

        return redirect()->away($tx->payment_url);
    }

    public function back(Request $request, string $tx)
    {
        $tx = PaymentTransaction::with('gateway')->findOrFail($tx);
        $this->attachKkiapay($tx, $request->query('transaction_id'));
        $tx = $this->payments->check($tx);
        $back = null;
        $title = null;
        if ($tx->purpose === 'invoice') {
            $link = \App\Models\InvoicePayLink::where('document_id', $tx->purpose_id)->first();
            $back = $link ? url('/pay/'.$link->token) : null;
        } elseif ($tx->purpose === 'pay_link') {
            $tok = \Illuminate\Support\Facades\DB::table('pay_links')->where('id', (int) $tx->purpose_id)->value('token');
            $back = $tok ? url('/pay/l/'.$tok) : null;
        } else {
            $order = ModuleOrder::find($tx->purpose_id);
            $title = $order?->module?->name;
            $back = url('/app/');
        }

        return view('pay.result', ['tx' => $tx, 'cancelled' => $request->boolean('cancel') && $tx->status !== 'paid', 'back' => $back, 'title' => $title]);
    }

    /** Page qui ouvre le widget KKiaPay (pas de page hébergée chez ce prestataire). */
    public function kkiapay(string $tx)
    {
        $tx = PaymentTransaction::with('gateway')->findOrFail($tx);
        abort_unless($tx->gateway?->driver === 'kkiapay', 404);
        if ($tx->status !== 'pending') {
            return redirect('/payments/'.$tx->id.'/return');
        }
        $gw = \App\Payments\GatewayRegistry::make($tx->gateway);

        return view('pay.kkiapay', ['tx' => $tx, 'key' => $gw->publicKey(), 'sandbox' => $tx->gateway->mode === 'test', 'meta' => (array) $tx->meta]);
    }

    /** KKiaPay : identifiant de transaction reçu au retour du widget ou par notification (jamais réutilisable pour un autre paiement). */
    protected function attachKkiapay(PaymentTransaction $tx, $transactionId): void
    {
        if ($tx->gateway?->driver !== 'kkiapay' || $tx->status !== 'pending' || ! is_string($transactionId) || ! preg_match('/^[A-Za-z0-9_-]{2,80}$/', $transactionId)) {
            return;
        }
        if ($tx->reference !== 'kkiapay-pending' && $tx->reference) {
            return;
        }
        // Verrou par identifiant KKiaPay : deux retours simultanés ne peuvent pas rattacher le même paiement à deux commandes
        \Illuminate\Support\Facades\Cache::lock('kkiapay-ref-'.$transactionId, 15)->block(10, function () use ($tx, $transactionId) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($tx, $transactionId) {
                $locked = PaymentTransaction::lockForUpdate()->find($tx->id);
                if (! $locked || $locked->status !== 'pending' || ($locked->reference && $locked->reference !== 'kkiapay-pending')) {
                    return;
                }
                if (PaymentTransaction::where('gateway_id', $locked->gateway_id)->where('reference', $transactionId)->where('id', '!=', $locked->id)->exists()) {
                    return;
                }
                $locked->update(['reference' => $transactionId]);
                $tx->setRawAttributes($locked->getAttributes(), true);
            });
        });
    }

    /** Notification serveur à serveur : on revérifie toujours auprès du prestataire. */
    public function notify(string $tx)
    {
        $tx = PaymentTransaction::with('gateway')->find($tx);
        if ($tx) {
            $this->payments->check($tx);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Webhook global d'un prestataire (Wave : checkout.session.completed, configuré une fois dans le tableau de bord Wave).
     * La transaction est retrouvée par sa référence ; le statut est toujours revérifié auprès du prestataire.
     */
    public function providerWebhook(Request $request, string $driver)
    {
        // Référence de la transaction selon le prestataire (Wave, Stripe, Paystack, Flutterwave, CinetPay…)
        $ref = $request->input('data.client_reference') ?? $request->input('client_reference') ?? $request->input('data.object.client_reference_id')
            ?? $request->input('data.reference') ?? $request->input('data.tx_ref') ?? $request->input('tx_ref') ?? $request->input('cpm_trans_id');
        $sessionId = $request->input('data.object.id') ?? $request->input('data.id') ?? $request->input('id') ?? $request->input('token') ?? $request->input('data.token')
            ?? $request->input('entity.id') ?? $request->input('cpm_trans_id');
        $sessionId = is_scalar($sessionId) ? (string) $sessionId : null;
        $tx = null;
        if (is_string($ref) && \Illuminate\Support\Str::isUuid($ref)) {
            $tx = PaymentTransaction::with('gateway')->find($ref);
        }
        if (! $tx && is_string($sessionId) && $sessionId !== '') {
            $tx = PaymentTransaction::with('gateway')->where('reference', $sessionId)->first();
        }
        if (! $tx && $driver === 'kkiapay' && is_string($p = $request->input('partnerId')) && \Illuminate\Support\Str::isUuid($p)) {
            $tx = PaymentTransaction::with('gateway')->find($p);
        }
        if ($tx && $tx->gateway?->driver === $driver) {
            if ($driver === 'kkiapay') {
                $this->attachKkiapay($tx, $request->input('transactionId'));
                $tx->refresh();
            }
            $this->payments->check($tx);
        }

        return response()->json(['ok' => true]);
    }
}
