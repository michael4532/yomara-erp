<?php

use App\Modules\Feuilles\FeuillesController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:feuilles_temps.submit,feuilles_temps.approve')->group(function () {
    Route::get('week', [FeuillesController::class, 'week']);
    Route::get('settings', [FeuillesController::class, 'settings']);
});
Route::middleware('perm:feuilles_temps.approve')->group(function () {
    Route::get('pending', [FeuillesController::class, 'pending']);
    Route::get('weeks/{id}', [FeuillesController::class, 'show'])->whereUuid('id');
    Route::put('settings', [FeuillesController::class, 'saveSettings']);
});
