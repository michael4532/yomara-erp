<?php

use App\Modules\Rapports\RapportsController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:rapports.view,rapports.create,rapports.manage')->group(function () {
    Route::get('meta', [RapportsController::class, 'meta']);
    Route::get('templates', [RapportsController::class, 'templates']);
    Route::get('values', [RapportsController::class, 'values']);
    Route::get('reports/{id}/run', [RapportsController::class, 'run'])->whereUuid('id');
    Route::get('reports/{id}/export', [RapportsController::class, 'export'])->whereUuid('id');
    Route::get('dashboards/{id}/run', [RapportsController::class, 'board'])->whereUuid('id');
    Route::get('dashboards/{id}/export', [RapportsController::class, 'boardExport'])->whereUuid('id');
});

Route::middleware('perm:rapports.create,rapports.manage')->group(function () {
    Route::post('preview', [RapportsController::class, 'preview']);
    Route::post('duplicate', [RapportsController::class, 'duplicate']);
});

Route::middleware('perm:rapports.manage')->group(function () {
    Route::get('schedules', [RapportsController::class, 'schedules']);
    Route::post('schedules', [RapportsController::class, 'storeSchedule']);
    Route::put('schedules/{id}', [RapportsController::class, 'updateSchedule'])->whereNumber('id');
    Route::delete('schedules/{id}', [RapportsController::class, 'destroySchedule'])->whereNumber('id');
    Route::post('schedules/{id}/send', [RapportsController::class, 'sendSchedule'])->whereNumber('id');
});
