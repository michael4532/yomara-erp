<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Payments\Collect;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use Illuminate\Http\Request;

/** Encaissement Mobile Money en direct (caisse, facture) : demande sur le téléphone ou QR code, suivi de l'état. */
class CollectController extends Controller
{
    public function __construct(protected Collect $collect, protected PaymentService $payments) {}

    public function gateways()
    {
        return ['data' => Collect::gateways(app('tenant'))->map(fn ($g) => ['id' => $g->id, 'label' => $g->label, 'driver' => $g->driver, 'mode' => GatewayRegistry::isPush($g->driver) ? 'push' : 'qr',
            'test' => $g->mode === 'test', 'channels' => GatewayRegistry::meta($g->driver)['channels'] ?? null])->values()];
    }

    public function start(Request $r)
    {
        $d = $r->validate(['gateway_id' => 'required|integer', 'amount' => 'nullable|numeric|min:0', 'phone' => 'nullable|string|max:30', 'document_id' => 'nullable|uuid', 'label' => 'nullable|string|max:120']);
        try {
            $tx = $this->collect->start(app('tenant'), (int) $d['gateway_id'], (float) ($d['amount'] ?? 0), $d['phone'] ?? null, $d['document_id'] ?? null, $d['label'] ?? null, $r->user()->id);
        } catch (PaymentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->collect->view($tx)], 201);
    }

    public function status(string $id)
    {
        $tx = PaymentTransaction::where('company_id', app('tenant')->id)->whereIn('purpose', ['collect', 'invoice'])->findOrFail($id);
        $tx = $this->payments->check($tx);

        return ['data' => $this->collect->view($tx)];
    }

    public function cancel(string $id)
    {
        $tx = PaymentTransaction::where('company_id', app('tenant')->id)->whereIn('purpose', ['collect', 'invoice'])->findOrFail($id);
        if ($tx->status === 'pending') {
            $tx->update(['status' => 'cancelled']);
        }

        return ['data' => $this->collect->view($tx->fresh())];
    }
}
