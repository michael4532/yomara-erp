<?php

namespace App\Modules\Achats;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\PurchaseRequest;
use Illuminate\Http\Request;

class AchatsController extends Controller
{
    public function __construct(protected AchatsService $service) {}

    protected function fail(\DomainException $e)
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    /** Demande d'approbation d'un bon de commande au-delà du seuil. */
    public function requestApproval(string $id)
    {
        $doc = PurchaseDocument::findOrFail($id);
        if (! $doc->needsApproval()) {
            return response()->json(['message' => 'Ce bon de commande n\'a pas besoin d\'approbation.'], 422);
        }
        $doc->approval_requested_at = now();
        $doc->save();
        AppNotification::send($doc->company_id, $this->service->approvers($doc->company_id), 'purchase', 'Bon de commande à approuver',
            ($doc->partner_name ?: 'Fournisseur').' — '.number_format($doc->total, 0, ',', ' ').' '.$doc->currency, 'achats', $doc->id, auth()->id());

        return ['data' => $doc->fresh()->toSyncArray()];
    }

    public function approve(string $id)
    {
        $doc = PurchaseDocument::findOrFail($id);
        if ($doc->type !== 'purchase_order' || $doc->status !== 'draft') {
            return response()->json(['message' => 'Seul un bon de commande brouillon s\'approuve.'], 422);
        }
        $doc->approved_by = auth()->id();
        $doc->approved_at = now();
        $doc->save();
        $buyers = \App\Models\User::where('company_id', $doc->company_id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('achats.manage'))->pluck('id');
        AppNotification::send($doc->company_id, $buyers, 'purchase', 'Bon de commande approuvé', ($doc->partner_name ?: '').' — '.number_format($doc->total, 0, ',', ' ').' '.$doc->currency.' : vous pouvez le valider.', 'achats', $doc->id, auth()->id());

        return ['data' => $doc->fresh()->toSyncArray()];
    }

    public function replenishment()
    {
        return ['data' => $this->service->replenishment()];
    }

    public function orderReplenishment(Request $r)
    {
        $data = $r->validate([
            'items' => 'required|array|min:1|max:300',
            'items.*.product_id' => 'required|uuid',
            'items.*.partner_id' => 'nullable|uuid',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);
        try {
            $docs = $this->service->createOrders($data['items'], 'Réapprovisionnement du '.now()->format('d/m/Y'));
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return ['data' => collect($docs)->map->toSyncArray()];
    }

    public function orderRequest(Request $r, string $id)
    {
        $supplier = $r->validate(['partner_id' => 'nullable|uuid'])['partner_id'] ?? null;
        try {
            $docs = $this->service->orderRequest(PurchaseRequest::findOrFail($id), $supplier);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return ['data' => collect($docs)->map->toSyncArray()];
    }
}
