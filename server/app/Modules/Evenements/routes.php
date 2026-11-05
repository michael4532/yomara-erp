<?php

use App\Modules\Evenements\EvenementsController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:evenements.view,evenements.sell,evenements.manage')->group(function () {
    Route::get('events/{id}/stats', [EvenementsController::class, 'stats'])->whereUuid('id');
    Route::get('events/{id}/plan', [EvenementsController::class, 'plan'])->whereUuid('id');
});
Route::middleware('perm:evenements.sell,evenements.manage')->group(function () {
    Route::post('bookings/{id}/checkin', [EvenementsController::class, 'checkin'])->whereUuid('id');
});
Route::middleware('perm:evenements.manage')->group(function () {
    Route::post('bookings/{id}/invoice-balance', [EvenementsController::class, 'invoiceBalance'])->whereUuid('id');
    Route::post('bookings/{id}/mark-paid', [EvenementsController::class, 'markPaid'])->whereUuid('id');
    Route::post('bookings/{id}/profile', [EvenementsController::class, 'profile'])->whereUuid('id');
    Route::post('events/{id}/spaces/generate', [EvenementsController::class, 'generateSpaces'])->whereUuid('id');
    Route::post('run', [EvenementsController::class, 'run']);
});
