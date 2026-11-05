<?php

use App\Modules\Fne\FneController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:fne.view,fne.manage')->group(function () {
    Route::get('settings', [FneController::class, 'settings']);
    Route::get('uncertified', [FneController::class, 'uncertified']);
});

Route::middleware('perm:fne.manage')->group(function () {
    Route::put('settings', [FneController::class, 'updateSettings']);
    Route::post('certify', [FneController::class, 'certify']);
    Route::post('process', [FneController::class, 'process']);
    Route::get('certifications/{id}', [FneController::class, 'show'])->whereUuid('id');
    Route::post('certifications/{id}/retry', [FneController::class, 'retry'])->whereUuid('id');
    Route::post('certifications/{id}/manual', [FneController::class, 'manual'])->whereUuid('id');
});
