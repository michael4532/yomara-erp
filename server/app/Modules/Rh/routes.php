<?php

use App\Modules\Rh\RhController;
use Illuminate\Support\Facades\Route;

// Espace salarié (le compte doit être relié à une fiche)
Route::middleware('perm:rh.self,rh.view,rh.manage')->group(function () {
    Route::get('me', [RhController::class, 'self']);
    Route::put('me', [RhController::class, 'updateSelf']);
    Route::get('me/payslips/{id}', [RhController::class, 'selfPayslip'])->whereNumber('id');
    Route::get('me/contracts/{id}', [RhController::class, 'selfContract'])->whereNumber('id');
});

Route::middleware('perm:rh.view,rh.manage')->group(function () {
    Route::get('employees', [RhController::class, 'employees']);
    Route::get('documents/settings', [RhController::class, 'docSettings']);
    Route::get('employees/{id}', [RhController::class, 'employee']);
    Route::get('employees/{id}/dossier', [RhController::class, 'dossier']);
    Route::get('leaves', [RhController::class, 'leaves']);
    Route::get('deadlines', [RhController::class, 'deadlines']);
});

// Demandes des salariés (tickets) : fil d'échanges pour le salarié, son responsable et la RH
Route::middleware('perm:rh.request,rh.view,rh.manage,rh.self')->group(function () {
    Route::get('requests/settings', [RhController::class, 'requestSettings']);
    Route::post('requests/{id}/comment', [RhController::class, 'commentRequest']);
    Route::post('requests/{id}/attachments', [RhController::class, 'attachRequest']);
    Route::get('requests/{id}/attachments/{aid}', [RhController::class, 'requestAttachment']);
    Route::get('team', [RhController::class, 'team']);
    Route::get('team/{id}', [RhController::class, 'teamMember']);
});
Route::middleware('perm:rh.manage')->group(function () {
    Route::post('employees/{id}/settlement', [RhController::class, 'settlementPreview']);
    Route::post('employees/{id}/settlement/apply', [RhController::class, 'settlementApply']);
    Route::delete('employees/{id}/settlement', [RhController::class, 'settlementCancel']);
    Route::put('requests/settings', [RhController::class, 'updateRequestSettings']);
    Route::post('requests/{id}/decide', [RhController::class, 'decideRequest']);
});

// Contrats de travail des salariés : accès accordé par l'administrateur (droit rh.contracts)
Route::middleware('perm:rh.contracts')->group(function () {
    Route::get('contracts', [RhController::class, 'contracts']);
    Route::get('contracts/{source}/{id}', [RhController::class, 'contract'])->whereIn('source', ['juridique', 'rh']);
});

Route::middleware('perm:rh.manage')->group(function () {
    Route::get('users', [RhController::class, 'users']);
    Route::put('documents/settings', [RhController::class, 'updateDocSettings']);
    Route::post('employees', [RhController::class, 'storeEmployee']);
    Route::put('employees/{id}', [RhController::class, 'updateEmployee']);
    Route::delete('employees/{id}', [RhController::class, 'deleteEmployee']);
    Route::post('leaves', [RhController::class, 'storeLeave']);
    Route::put('leaves/{id}', [RhController::class, 'updateLeave']);
    Route::post('leaves/{id}/{decision}', [RhController::class, 'decideLeave'])->whereIn('decision', ['approve', 'reject', 'cancel']);
});
