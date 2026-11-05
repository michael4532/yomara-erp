<?php

use App\Modules\Hebergement\HebergementController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:hebergement.view,hebergement.manage')->group(function () {
    Route::get('stats', [HebergementController::class, 'stats']);
    Route::get('services/{id}/history', [HebergementController::class, 'history'])->whereUuid('id');
    Route::get('settings', [HebergementController::class, 'settings']);
});
Route::middleware('perm:hebergement.manage')->group(function () {
    Route::post('renew', [HebergementController::class, 'renew']);
    Route::post('services/{id}/extend', [HebergementController::class, 'extend'])->whereUuid('id');
    Route::post('services/{id}/provider-done', [HebergementController::class, 'providerDone'])->whereUuid('id');
    Route::post('services/{id}/remind', [HebergementController::class, 'remind'])->whereUuid('id');
    Route::put('settings', [HebergementController::class, 'updateSettings']);
    Route::post('run', [HebergementController::class, 'run']);
});
