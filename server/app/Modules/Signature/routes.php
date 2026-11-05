<?php

use App\Modules\Signature\SignatureController;
use Illuminate\Support\Facades\Route;

// Tout utilisateur : documents qu'il doit signer (signataire interne)
Route::get('mine', [SignatureController::class, 'mine']);
Route::get('requests', [SignatureController::class, 'index']);
Route::get('requests/{id}', [SignatureController::class, 'show'])->whereUuid('id');
Route::get('requests/{id}/file/{kind}', [SignatureController::class, 'file'])->whereUuid('id')->whereIn('kind', ['original', 'signed']);
Route::post('requests/{id}/sign', [SignatureController::class, 'sign'])->whereUuid('id');
Route::post('requests/{id}/decline', [SignatureController::class, 'decline'])->whereUuid('id');

Route::middleware('perm:signature.view,signature.manage')->group(function () {
    Route::get('settings', [SignatureController::class, 'settings']);
});

Route::middleware('perm:signature.manage')->group(function () {
    Route::get('users', [SignatureController::class, 'users']);
    Route::put('settings', [SignatureController::class, 'updateSettings']);
    Route::post('requests', [SignatureController::class, 'store']);
    Route::post('requests/{id}/send', [SignatureController::class, 'send'])->whereUuid('id');
    Route::post('requests/{id}/remind', [SignatureController::class, 'remind'])->whereUuid('id');
    Route::post('requests/{id}/cancel', [SignatureController::class, 'cancel'])->whereUuid('id');
    Route::delete('requests/{id}', [SignatureController::class, 'destroy'])->whereUuid('id');
});
