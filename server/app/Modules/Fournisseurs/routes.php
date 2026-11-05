<?php

use App\Modules\Fournisseurs\FournisseursController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:portail_fournisseurs.view,portail_fournisseurs.manage')->group(function () {
    Route::get('stats', [FournisseursController::class, 'stats']);
    Route::get('accesses', [FournisseursController::class, 'accesses']);
    Route::get('tenders/{id}/compare', [FournisseursController::class, 'compare'])->whereUuid('id');
    Route::get('invoices/{id}/file', [FournisseursController::class, 'invoiceFile'])->whereUuid('id');
    Route::get('invoices/{id}/match', [FournisseursController::class, 'match'])->whereUuid('id');
    Route::get('settings', [FournisseursController::class, 'settings']);
});
Route::middleware('perm:portail_fournisseurs.manage')->group(function () {
    Route::post('partners/{id}/invite', [FournisseursController::class, 'invite'])->whereUuid('id');
    Route::post('partners/{id}/revoke', [FournisseursController::class, 'revoke'])->whereUuid('id');
    Route::post('tenders/{id}/award', [FournisseursController::class, 'award'])->whereUuid('id');
    Route::post('invoices/read', [FournisseursController::class, 'read']);
    Route::post('invoices', [FournisseursController::class, 'upload']);
    Route::put('settings', [FournisseursController::class, 'saveSettings']);
});
