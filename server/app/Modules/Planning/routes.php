<?php

use App\Modules\Planning\PlanningController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:planning.view,planning.manage')->group(function () {
    Route::get('load', [PlanningController::class, 'load']);
    Route::get('available', [PlanningController::class, 'available']);
    Route::get('settings', [PlanningController::class, 'settings']);
});
Route::middleware('perm:planning.manage')->put('settings', [PlanningController::class, 'saveSettings']);
