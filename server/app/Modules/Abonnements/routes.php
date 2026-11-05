<?php

use App\Modules\Abonnements\AbonnementsController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:abonnements.view,abonnements.manage')->group(function () {
    Route::get('stats', [AbonnementsController::class, 'stats']);
    Route::get('subscriptions/{id}/invoices', [AbonnementsController::class, 'invoices'])->whereUuid('id');
});
Route::middleware('perm:abonnements.manage')->group(function () {
    Route::post('subscriptions/{id}/generate', [AbonnementsController::class, 'generate'])->whereUuid('id');
    Route::post('run', [AbonnementsController::class, 'run']);
});
