<?php

use App\Modules\Billetterie\BilletterieController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:billetterie.scan,billetterie.manage')->group(function () {
    Route::post('scan', [BilletterieController::class, 'scan']);
    Route::get('lookup', [BilletterieController::class, 'lookup']);
});
Route::middleware('perm:billetterie.scan,billetterie.sell,billetterie.manage,evenements.manage')->group(function () {
    Route::get('events/{id}/stats', [BilletterieController::class, 'stats'])->whereUuid('id');
    Route::get('events/{id}/analytics', [BilletterieController::class, 'analytics'])->whereUuid('id');
    Route::get('events/{id}/attendance', [BilletterieController::class, 'attendance'])->whereUuid('id');
});
Route::middleware('perm:billetterie.sell,billetterie.manage')->group(function () {
    Route::post('orders/{id}/resend', [BilletterieController::class, 'resend'])->whereUuid('id');
});
// Liste des inscrits avec leurs coordonnées : réservée aux gestionnaires (pas aux opérateurs des bornes)
Route::middleware('perm:billetterie.manage,evenements.manage')->group(function () {
    Route::get('events/{id}/attendance/export', [BilletterieController::class, 'attendanceExport'])->whereUuid('id');
});
Route::middleware('perm:billetterie.manage')->group(function () {
    Route::post('bookings/{id}/badges', [BilletterieController::class, 'badges'])->whereUuid('id');
    Route::post('events/{id}/feedback-request', [BilletterieController::class, 'requestFeedback'])->whereUuid('id');
});
