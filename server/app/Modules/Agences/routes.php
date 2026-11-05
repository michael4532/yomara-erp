<?php

use App\Modules\Agences\AgencesController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AgencesController::class, 'index']);
Route::get('summary', [AgencesController::class, 'summary'])->middleware('perm:agences.view,agences.manage');
Route::middleware('perm:agences.manage')->group(function () {
    Route::post('/', [AgencesController::class, 'save']);
    Route::put('{id}', [AgencesController::class, 'save'])->whereUuid('id');
    Route::delete('{id}', [AgencesController::class, 'destroy'])->whereUuid('id');
});
