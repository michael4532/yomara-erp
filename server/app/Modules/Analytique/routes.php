<?php

use App\Modules\Analytique\AnalytiqueController;
use Illuminate\Support\Facades\Route;

Route::get('budget-status', [AnalytiqueController::class, 'budgetStatus']);

Route::middleware('perm:analytique.view,analytique.manage')->group(function () {
    Route::get('report', [AnalytiqueController::class, 'report']);
    Route::get('sections/{id}', [AnalytiqueController::class, 'section'])->whereUuid('id');
    Route::get('accounts', [AnalytiqueController::class, 'accounts']);
    Route::get('budgets', [AnalytiqueController::class, 'budgets']);
    Route::get('budgets/{id}', [AnalytiqueController::class, 'showBudget'])->whereNumber('id');
});

Route::middleware('perm:analytique.manage')->group(function () {
    Route::get('users', [AnalytiqueController::class, 'users']);
    Route::post('manual', [AnalytiqueController::class, 'storeManual']);
    Route::delete('manual/{id}', [AnalytiqueController::class, 'destroyManual'])->whereNumber('id');
    Route::post('rebuild', [AnalytiqueController::class, 'rebuild']);
    Route::post('budgets', [AnalytiqueController::class, 'storeBudget']);
    Route::put('budgets/{id}', [AnalytiqueController::class, 'updateBudget'])->whereNumber('id');
    Route::post('budgets/{id}/{action}', [AnalytiqueController::class, 'budgetAction'])->whereNumber('id')->whereIn('action', ['approve', 'close', 'reopen']);
    Route::delete('budgets/{id}', [AnalytiqueController::class, 'destroyBudget'])->whereNumber('id');
});
