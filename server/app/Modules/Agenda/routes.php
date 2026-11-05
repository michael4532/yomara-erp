<?php

use App\Modules\Agenda\AgendaController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:agenda.use,agenda.manage')->group(function () {
    Route::get('overlay', [AgendaController::class, 'overlay']);
    Route::put('overlay/{kind}/{id}', [AgendaController::class, 'moveOverlay'])->whereIn('kind', ['interview', 'appointment', 'intervention', 'task']);
    Route::delete('overlay/{kind}/{id}', [AgendaController::class, 'cancelOverlay'])->whereIn('kind', ['interview', 'appointment', 'intervention']);
    Route::get('users', [AgendaController::class, 'users']);
    Route::get('feed', [AgendaController::class, 'feed']);
    Route::post('feed', [AgendaController::class, 'feed']);
    Route::get('events/{id}/ics', [AgendaController::class, 'ics'])->whereUuid('id');
    Route::post('events/{id}/invite', [AgendaController::class, 'invite'])->whereUuid('id');
});
