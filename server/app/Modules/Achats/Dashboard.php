<?php

namespace App\Modules\Achats;

use App\Models\Company;
use App\Modules\Achats\Models\PurchaseDocument;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $month = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
        $q = fn () => PurchaseDocument::where('status', 'validated')->where('type', 'supplier_invoice');

        return [
            ['key' => 'purchases_month', 'label' => 'Achats du mois', 'value' => round((float) $q()->whereBetween('date', $month)->sum(\DB::raw('total * exchange_rate')), 2), 'format' => 'money', 'icon' => 'shopping_cart', 'permission' => 'achats.view'],
            ['key' => 'payables', 'label' => 'Dettes fournisseurs', 'value' => round((float) $q()->where('payment_status', '!=', 'paid')->selectRaw('COALESCE(SUM((total - amount_paid) * exchange_rate),0) as v')->value('v'), 2), 'format' => 'money', 'icon' => 'account_balance_wallet', 'permission' => 'achats.view'],
            ['key' => 'purchase_requests', 'label' => 'Demandes d\'achat à approuver', 'value' => \App\Modules\Achats\Models\PurchaseRequest::where('status', 'submitted')->count(), 'format' => 'number', 'icon' => 'approval', 'permission' => 'achats.approve|achats.manage'],
            ['key' => 'purchase_late', 'label' => 'Livraisons fournisseurs en retard', 'value' => PurchaseDocument::where('type', 'purchase_order')->where('status', 'validated')->whereIn('receipt_status', ['none', 'partial'])->whereNotNull('due_date')->whereDate('due_date', '<', today())->count(), 'format' => 'number', 'icon' => 'local_shipping', 'permission' => 'achats.view'],
        ];
    }
}
