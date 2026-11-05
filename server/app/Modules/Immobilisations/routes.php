<?php

use App\Modules\Immobilisations\ImmobilisationsController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:immobilisations.view,immobilisations.manage')->group(function () {
    Route::get('settings', [ImmobilisationsController::class, 'settings']);
    Route::get('assets/{id}', [ImmobilisationsController::class, 'show'])->whereUuid('id');
    Route::post('preview', [ImmobilisationsController::class, 'preview']);
    Route::get('register', [ImmobilisationsController::class, 'register']);
    Route::get('register/export', [ImmobilisationsController::class, 'export']);
});

Route::middleware('perm:immobilisations.manage')->group(function () {
    Route::put('settings', [ImmobilisationsController::class, 'updateSettings']);
    Route::post('depreciate', [ImmobilisationsController::class, 'depreciate']);
    Route::post('assets/{id}/unpost', [ImmobilisationsController::class, 'unpost'])->whereUuid('id');
    Route::post('assets/{id}/dispose', [ImmobilisationsController::class, 'dispose'])->whereUuid('id');
    Route::post('assets/{id}/cancel-disposal', [ImmobilisationsController::class, 'cancelDisposal'])->whereUuid('id');
    Route::post('from-document', [ImmobilisationsController::class, 'fromDocument']);
});
