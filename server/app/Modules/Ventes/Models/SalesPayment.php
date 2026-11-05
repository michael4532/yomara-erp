<?php

namespace App\Modules\Ventes\Models;

use App\Modules\Core\Models\Payment;

class SalesPayment extends Payment
{
    public const DIRECTION = 'sale';

    public const DOCUMENT_CLASS = SalesDocument::class;

    public static string $syncEntity = 'sales_payments';

    public static string $viewPermission = 'ventes.view';

    public static string $managePermission = 'ventes.payments.manage|pos.use';

    /** Encaissement enregistré par le système (ex. paiement à la livraison saisi par un livreur). */
    public static bool $systemWrite = false;

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        if ($user && ! static::$systemWrite && ! $user->hasPermission('ventes.payments.manage')) {
            $doc = SalesDocument::find($data['document_id'] ?? $this->document_id);
            if ($doc && $doc->type !== 'receipt') {
                throw new \DomainException('Votre rôle permet seulement d\'encaisser des tickets de caisse.');
            }
        }

        parent::beforeEntityWrite($data, $creating);
    }
}
