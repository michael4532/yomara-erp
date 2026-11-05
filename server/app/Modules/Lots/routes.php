<?php

use App\Modules\Lots\LotsController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:stock.view,stock.manage,pos.use,ventes.manage,achats.manage,achats.receive')->group(function () {
    Route::get('levels', [LotsController::class, 'levels']);
    Route::get('search', [LotsController::class, 'search']);
    Route::get('lots/{id}/trace', [LotsController::class, 'trace'])->whereUuid('id');
    Route::get('settings', [LotsController::class, 'settings']);
});
Route::middleware('perm:stock.manage')->group(function () {
    Route::post('scrap', [LotsController::class, 'scrap']);
    Route::put('settings', [LotsController::class, 'updateSettings']);
});
