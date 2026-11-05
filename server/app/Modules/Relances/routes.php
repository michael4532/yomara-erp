<?php

use App\Modules\Relances\RecoveryController;
use App\Modules\Relances\RelancesController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:relances.view,relances.manage')->group(function () {
    Route::get('aging', [RelancesController::class, 'aging']);
    Route::get('priorities', [RelancesController::class, 'priorities']);
    Route::get('settings', [RelancesController::class, 'settings']);
    Route::get('history', [RelancesController::class, 'history']);
    Route::get('partners/{id}', [RelancesController::class, 'partner'])->whereUuid('id');
    Route::get('partners/{id}/preview', [RelancesController::class, 'preview'])->whereUuid('id');
    // Dossiers de recouvrement
    Route::get('cases', [RecoveryController::class, 'index']);
    Route::get('cases/{id}', [RecoveryController::class, 'show'])->whereNumber('id');
    Route::get('cases/{id}/notice', [RecoveryController::class, 'notice'])->whereNumber('id');
    Route::get('recovery/meta', [RecoveryController::class, 'meta']);
    Route::get('recovery/candidates', [RecoveryController::class, 'candidates']);
    Route::get('recovery/dashboard', [RecoveryController::class, 'dashboard']);
});

Route::middleware('perm:relances.manage')->group(function () {
    Route::put('settings', [RelancesController::class, 'updateSettings']);
    Route::post('partners/{id}/send', [RelancesController::class, 'send'])->whereUuid('id');
    Route::post('partners/{id}/pause', [RelancesController::class, 'pause'])->whereUuid('id');
    Route::post('run', [RelancesController::class, 'run']);
    Route::post('cases', [RecoveryController::class, 'store']);
    Route::post('cases/{id}/actions/{type}', [RecoveryController::class, 'act'])->whereNumber('id')->whereAlpha('type');
    Route::post('cases/{id}/actions/write_off', [RecoveryController::class, 'act'])->whereNumber('id')->defaults('type', 'write_off');
    Route::post('cases/{id}/close', [RecoveryController::class, 'close'])->whereNumber('id');
    Route::post('cases/{id}/assign', [RecoveryController::class, 'assign'])->whereNumber('id');
    Route::put('recovery/settings', [RecoveryController::class, 'saveSettings']);
});
