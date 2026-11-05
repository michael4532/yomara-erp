<?php

use App\Http\Controllers\Api\DocumentActionController;
use App\Http\Controllers\Api\EntityController;
use App\Modules\Achats\AchatsController;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\PurchasePayment;
use App\Modules\Achats\Models\PurchaseReceipt;
use App\Modules\Achats\Models\PurchaseRequest;
use App\Modules\Achats\Models\SupplierPrice;
use Illuminate\Support\Facades\Route;

EntityController::routes('documents', PurchaseDocument::class);
DocumentActionController::routes('documents', PurchaseDocument::class);
EntityController::routes('payments', PurchasePayment::class);
EntityController::routes('requests', PurchaseRequest::class);
EntityController::routes('receipts', PurchaseReceipt::class);
EntityController::routes('supplier-prices', SupplierPrice::class);

Route::middleware('perm:achats.manage')->group(function () {
    Route::post('documents/{id}/request-approval', [AchatsController::class, 'requestApproval'])->whereUuid('id');
    Route::get('replenishment', [AchatsController::class, 'replenishment']);
    Route::post('replenishment', [AchatsController::class, 'orderReplenishment']);
    Route::post('requests/{id}/order', [AchatsController::class, 'orderRequest'])->whereUuid('id');
});
Route::middleware('perm:achats.approve')->post('documents/{id}/approve', [AchatsController::class, 'approve'])->whereUuid('id');
