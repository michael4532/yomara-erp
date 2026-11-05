<?php

use App\Modules\Ia\IaController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:ia.use,ia.manage')->group(function () {
    Route::get('status', [IaController::class, 'status']);
    Route::post('ask', [IaController::class, 'ask'])->middleware('throttle:20,1');
    Route::post('draft', [IaController::class, 'draft'])->middleware('throttle:20,1');
    Route::get('conversations', [IaController::class, 'conversations']);
    Route::get('conversations/{id}', [IaController::class, 'conversation'])->whereNumber('id');
    Route::delete('conversations/{id}', [IaController::class, 'deleteConversation'])->whereNumber('id');
});
Route::middleware('perm:ia.manage')->put('settings', [IaController::class, 'settings']);
