<?php

namespace App\Payments;

/** Informations transmises au prestataire : libellé, payeur, URL de retour et de notification. */
class PaymentContext
{
    public function __construct(
        public string $description,
        public string $returnUrl,
        public string $notifyUrl,
        public string $cancelUrl,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerPhone = null,
        public string $merchantName = 'Yomara ERP',
    ) {}
}
