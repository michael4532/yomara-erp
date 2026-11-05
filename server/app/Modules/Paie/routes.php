<?php

use App\Modules\Paie\PaieController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:paie.view,paie.manage')->group(function () {
    Route::get('settings', [PaieController::class, 'settings']);
    Route::get('profile', [PaieController::class, 'profile']);
    Route::get('runs', [PaieController::class, 'runs']);
    Route::get('runs/{id}', [PaieController::class, 'run']);
    Route::get('runs/{id}/declaration', [PaieController::class, 'declaration']);
    Route::get('runs/{id}/export', [PaieController::class, 'export']);
    Route::get('payslips/{id}', [PaieController::class, 'payslip']);
    Route::post('simulate', [PaieController::class, 'simulate']);
});

Route::middleware('perm:paie.manage')->group(function () {
    Route::put('settings', [PaieController::class, 'updateSettings']);
    Route::put('profile', [PaieController::class, 'updateProfile']);
    Route::delete('profile/{code}', [PaieController::class, 'resetProfile']);
    Route::post('runs', [PaieController::class, 'storeRun']);
    Route::delete('runs/{id}', [PaieController::class, 'deleteRun']);
    Route::post('runs/{id}/refresh', [PaieController::class, 'refreshRun']);
    Route::post('runs/{id}/validate', [PaieController::class, 'validateRun']);
    Route::post('runs/{id}/reopen', [PaieController::class, 'reopenRun']);
    Route::post('runs/{id}/pay', [PaieController::class, 'payRun']);
    Route::put('payslips/{id}', [PaieController::class, 'updatePayslip']);
    Route::post('quick/preview', [PaieController::class, 'quickPreview']);
    Route::post('quick/pay', [PaieController::class, 'quickPay']);
});
