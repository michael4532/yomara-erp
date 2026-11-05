<?php

namespace App\Modules\Achats\Models;

use App\Modules\Core\Models\TradeDocument;

class PurchaseDocument extends TradeDocument
{
    public const DIRECTION = 'purchase';

    public const TYPES = [
        'rfq' => ['Demande de prix', 'rfq_prefix', 'DP'],
        'purchase_order' => ['Bon de commande', 'purchase_prefix', 'BCF'],
        'supplier_invoice' => ['Facture fournisseur', 'supplier_invoice_prefix', 'FF'],
    ];

    public const PAYABLE_TYPES = ['supplier_invoice'];

    public static string $syncEntity = 'purchase_documents';

    public static string $viewPermission = 'achats.view';

    public static string $managePermission = 'achats.manage';

    /** Droit de suppression (distinct de la modification). */
    public static string $deletePermission = 'achats.delete';

    /** Seuil au-delà duquel un bon de commande doit être approuvé (0 = pas d'approbation). */
    public function approvalLimit(): float
    {
        return (float) ($this->company()->first()?->setting('purchase_approval_limit') ?? 0);
    }

    public function needsApproval(): bool
    {
        $limit = $this->approvalLimit();

        return $this->type === 'purchase_order' && $limit > 0 && $this->subtotal > $limit && ! $this->approved_at;
    }

    public function validateDocument(): void
    {
        if ($this->needsApproval()) {
            $user = auth()->user();
            if ($user && $user->hasPermission('achats.approve')) {
                // L'approbateur valide lui-même : l'approbation est implicite
                $this->approved_by = $user->id;
                $this->approved_at = now();
            } else {
                throw new \DomainException('Ce bon de commande dépasse le seuil d\'approbation (HT : '.number_format($this->approvalLimit(), 0, ',', ' ').' '.$this->currency.') : demandez l\'approbation avant de le valider.');
            }
        }
        if ($this->type === 'purchase_order') {
            $this->receipt_status = 'none';
        }
        parent::validateDocument();
    }

    public function cancelDocument(): void
    {
        if ($this->type === 'purchase_order' && PurchaseReceipt::where('order_id', $this->id)->where('status', 'done')->exists()) {
            throw new \DomainException('Des réceptions existent pour ce bon de commande : annulez-les d\'abord.');
        }
        parent::cancelDocument();
    }

    public function toSyncArray(): array
    {
        return parent::toSyncArray() + ($this->type === 'purchase_order' ? ['received' => PurchaseReceipt::receivedByLine($this->id)] : []);
    }
}
