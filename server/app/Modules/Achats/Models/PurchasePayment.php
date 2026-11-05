<?php

namespace App\Modules\Achats\Models;

use App\Modules\Core\Models\Payment;

class PurchasePayment extends Payment
{
    public const DIRECTION = 'purchase';

    public const DOCUMENT_CLASS = PurchaseDocument::class;

    public static string $syncEntity = 'purchase_payments';

    public static string $viewPermission = 'achats.view';

    public static string $managePermission = 'achats.manage';
}
