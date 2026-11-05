<?php

use App\Modules\Recrutement\RecrutementController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:recrutement.view,recrutement.manage,recrutement.interview')->group(function () {
    Route::get('applications/{id}/cv', [RecrutementController::class, 'cv']);
});

Route::middleware('perm:recrutement.view,recrutement.manage')->group(function () {
    Route::get('stats', [RecrutementController::class, 'stats']);
    Route::get('settings', [RecrutementController::class, 'settings']);
});

Route::middleware('perm:recrutement.manage')->group(function () {
    Route::get('users', [RecrutementController::class, 'users']);
    Route::put('settings', [RecrutementController::class, 'updateSettings']);
    Route::post('applications/{id}/cv', [RecrutementController::class, 'uploadCv']);
    Route::post('applications/{id}/message', [RecrutementController::class, 'message']);
    Route::post('applications/{id}/hire', [RecrutementController::class, 'hire']);
});
