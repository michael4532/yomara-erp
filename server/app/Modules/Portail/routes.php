<?php

use App\Modules\Portail\PortailController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:portail.manage,ventes.manage')->group(function () {
    Route::get('inbox', [PortailController::class, 'inbox']);
    Route::get('partners/{partner}', [PortailController::class, 'show'])->whereUuid('partner');
    Route::post('partners/{partner}/invite', [PortailController::class, 'invite'])->whereUuid('partner');
    Route::post('partners/{partner}/revoke', [PortailController::class, 'revoke'])->whereUuid('partner');
    Route::post('partners/{partner}/reply', [PortailController::class, 'reply'])->whereUuid('partner');
    Route::post('partners/{partner}/read', [PortailController::class, 'read'])->whereUuid('partner');
});
