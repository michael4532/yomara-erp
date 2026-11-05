<?php

use App\Modules\NotesFrais\NotesFraisController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:notes_frais.self,notes_frais.approve,notes_frais.manage')->group(function () {
    Route::post('expenses/{id}/receipt', [NotesFraisController::class, 'uploadReceipt']);
    Route::get('expenses/{id}/receipt', [NotesFraisController::class, 'receipt']);
    Route::get('settings', [NotesFraisController::class, 'settings']);
});

Route::middleware('perm:notes_frais.manage')->group(function () {
    Route::put('settings', [NotesFraisController::class, 'updateSettings']);
    Route::post('pay', [NotesFraisController::class, 'pay']);
});
