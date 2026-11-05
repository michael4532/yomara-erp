<?php

use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Facades\Route;

Route::prefix('install')->group(function () {
    Route::get('/', [InstallController::class, 'welcome']);
    Route::get('database', [InstallController::class, 'database']);
    Route::post('database', [InstallController::class, 'saveDatabase']);
    Route::get('setup', [InstallController::class, 'setup']);
    Route::post('setup', [InstallController::class, 'saveSetup']);
});
