<?php

use App\Modules\Prestations\PrestationsController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:prestations.view,prestations.log,prestations.manage')->group(function () {
    Route::get('stats', [PrestationsController::class, 'stats']);
    Route::get('deliverables/{id}/file', [PrestationsController::class, 'download'])->whereUuid('id');
    Route::get('reports/{id}/pdf', [PrestationsController::class, 'reportPdf'])->whereUuid('id');
    Route::get('settings', [PrestationsController::class, 'settings']);
});
Route::middleware('perm:prestations.log,prestations.manage')->group(function () {
    Route::post('deliverables/{id}/file', [PrestationsController::class, 'upload'])->whereUuid('id');
});
Route::middleware('perm:prestations.manage')->group(function () {
    Route::post('packs/{id}/invoice', [PrestationsController::class, 'invoicePack'])->whereUuid('id');
    Route::post('packs/{id}/overage', [PrestationsController::class, 'invoiceOverage'])->whereUuid('id');
    Route::post('packs/{id}/renew', [PrestationsController::class, 'renew'])->whereUuid('id');
    Route::post('deliverables/{id}/resend', [PrestationsController::class, 'resend'])->whereUuid('id');
    Route::put('settings', [PrestationsController::class, 'saveSettings']);
    Route::post('reports', [PrestationsController::class, 'generateReport']);
    Route::post('reports/{id}/send', [PrestationsController::class, 'sendReport'])->whereUuid('id');
});
