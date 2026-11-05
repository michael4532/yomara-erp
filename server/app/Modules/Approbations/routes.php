<?php

use App\Modules\Approbations\ApprobationsController;
use Illuminate\Support\Facades\Route;

// Chacun : ses demandes, sa boîte « à approuver » (selon les circuits), ses délégations.
Route::get('meta', [ApprobationsController::class, 'meta']);
Route::get('inbox', [ApprobationsController::class, 'inbox']);
Route::get('requests', [ApprobationsController::class, 'index']);
Route::get('requests/{id}', [ApprobationsController::class, 'show'])->whereUuid('id');
Route::post('requests/{id}/decide', [ApprobationsController::class, 'decide'])->whereUuid('id');
Route::post('requests/{id}/cancel', [ApprobationsController::class, 'cancel'])->whereUuid('id');
Route::get('delegations', [ApprobationsController::class, 'delegations']);
Route::post('delegations', [ApprobationsController::class, 'storeDelegation']);
Route::delete('delegations/{id}', [ApprobationsController::class, 'destroyDelegation'])->whereNumber('id');
