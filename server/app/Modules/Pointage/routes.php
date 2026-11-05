<?php

use App\Modules\Pointage\PointageController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:pointage.self,pointage.view,pointage.manage')->group(function () {
    Route::get('me', [PointageController::class, 'me']);
    Route::put('me/pin', [PointageController::class, 'myPin']);
});

Route::middleware('perm:pointage.view,pointage.manage')->group(function () {
    Route::get('report', [PointageController::class, 'report']);
    Route::get('report/export', [PointageController::class, 'export']);
    Route::get('settings', [PointageController::class, 'settings']);
});

Route::middleware('perm:pointage.manage')->group(function () {
    Route::put('settings', [PointageController::class, 'updateSettings']);
    Route::put('badges/{id}/pin', [PointageController::class, 'pin']);
    Route::post('payroll', [PointageController::class, 'payroll']);
});
