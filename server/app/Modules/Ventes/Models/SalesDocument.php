<?php

namespace App\Modules\Ventes\Models;

use App\Modules\Core\Models\TradeDocument;

class SalesDocument extends TradeDocument
{
    public const DIRECTION = 'sale';

    public const TYPES = [
        'quote' => ['Devis', 'quote_prefix', 'DEV'],
        'proforma' => ['Facture proforma', 'proforma_prefix', 'PRO'],
        'order' => ['Commande client', 'order_prefix', 'CMD'],
        'invoice' => ['Facture', 'invoice_prefix', 'FAC'],
        'deposit' => ['Facture d\'acompte', 'deposit_prefix', 'ACP'],
        'credit_note' => ['Avoir', 'credit_prefix', 'AV'],
        'receipt' => ['Ticket de caisse', 'receipt_prefix', 'TK'], // module Caisse (POS)
    ];

    public const PAYABLE_TYPES = ['invoice', 'receipt', 'deposit'];

    public static string $syncEntity = 'sales_documents';

    public static string $viewPermission = 'ventes.view';

    /** Un caissier (pos.use) ne peut écrire que des tickets de caisse. */
    public static string $managePermission = 'ventes.manage|pos.use';

    /** Brouillon préparé par un autre module (intervention…) pour un utilisateur sans droit de vente. */
    public static bool $systemWrite = false;

    /** Suppression d'un document de vente (distincte de la modification ; les tickets de caisse suivent la caisse). */
    public static string $deletePermission = 'ventes.delete|pos.manage';

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $type = $data['type'] ?? $this->type;
        if ($user && ! static::$systemWrite && ! $user->hasPermission('ventes.manage') && $type !== 'receipt') {
            throw new \DomainException('Votre rôle permet seulement d\'enregistrer des tickets de caisse.');
        }
        // Validation réservée aux personnes désignées (droit « Valider les devis, commandes et factures »)
        $status = $data['status'] ?? null;
        if ($user && ! static::$systemWrite && $type !== 'receipt' && $status === 'validated' && ($creating || $this->status !== 'validated') && ! $user->hasPermission('ventes.validate')) {
            throw new \DomainException('Vous n\'avez pas le droit de valider ce document : enregistrez-le en brouillon, une personne habilitée le validera.');
        }
        if ($type === 'receipt' && ! app('tenant')->hasModule('pos')) {
            throw new \DomainException('Le module Caisse n\'est pas activé.');
        }

        parent::beforeEntityWrite($data, $creating);
    }
}
