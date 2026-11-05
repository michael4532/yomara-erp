<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Interventions\InterventionsController;
use App\Modules\Interventions\Models\Contract;
use App\Modules\Interventions\Models\Equipment;
use App\Modules\Interventions\Models\Intervention;
use Illuminate\Support\Facades\Route;

EntityController::routes('equipments', Equipment::class);
EntityController::routes('contracts', Contract::class);
EntityController::routes('interventions', Intervention::class);

Route::middleware('perm:interventions.view,interventions.do,interventions.manage')->group(function () {
    Route::get('technicians', [InterventionsController::class, 'technicians']);
    Route::post('interventions/{id}/photos', [InterventionsController::class, 'photo'])->whereUuid('id');
    Route::delete('interventions/{id}/photos/{index}', [InterventionsController::class, 'deletePhoto'])->whereUuid('id')->whereNumber('index');
});
Route::middleware('perm:interventions.view,interventions.manage')->get('stats', [InterventionsController::class, 'stats']);
Route::middleware('perm:interventions.manage')->group(function () {
    Route::post('interventions/{id}/invoice', [InterventionsController::class, 'invoice'])->whereUuid('id');
    Route::post('contracts/{id}/invoice', [InterventionsController::class, 'invoiceContract'])->whereUuid('id');
    Route::post('generate', [InterventionsController::class, 'generate']);
});
