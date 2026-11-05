<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Logistique\LogistiqueController;
use App\Modules\Logistique\Models\Delivery;
use App\Modules\Logistique\Models\Tour;
use App\Modules\Logistique\Models\Vehicle;
use App\Modules\Logistique\Models\Zone;
use Illuminate\Support\Facades\Route;

EntityController::routes('zones', Zone::class);
EntityController::routes('vehicles', Vehicle::class);
EntityController::routes('tours', Tour::class);
EntityController::routes('deliveries', Delivery::class);

Route::middleware('perm:logistique.view,logistique.deliver,logistique.manage')->group(function () {
    Route::get('drivers', [LogistiqueController::class, 'drivers']);
    Route::get('deliveries/{id}/events', [LogistiqueController::class, 'events'])->whereUuid('id');
    Route::post('deliveries/{id}/photo', [LogistiqueController::class, 'photo'])->whereUuid('id');
});
Route::middleware('perm:logistique.view,logistique.manage')->get('stats', [LogistiqueController::class, 'stats']);
Route::middleware('perm:logistique.manage')->post('remit', [LogistiqueController::class, 'remit']);
