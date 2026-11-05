<?php

use App\Modules\Variantes\VariantesController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:core.products.view,core.products.manage')->get('templates/{id}', [VariantesController::class, 'show'])->whereUuid('id');
Route::middleware('perm:core.products.manage')->group(function () {
    Route::post('templates/{id}/generate', [VariantesController::class, 'generate'])->whereUuid('id');
    Route::post('templates/{id}/propagate', [VariantesController::class, 'propagate'])->whereUuid('id');
});
