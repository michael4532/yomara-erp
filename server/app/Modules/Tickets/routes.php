<?php

use App\Modules\Tickets\TicketController;
use Illuminate\Support\Facades\Route;

// Tout utilisateur de la société peut ouvrir et suivre ses demandes ; la visibilité dépend de son rôle dans l'organisation.
Route::get('setup', [TicketController::class, 'setup']);
Route::get('tickets', [TicketController::class, 'index']);
Route::post('tickets', [TicketController::class, 'store']);
Route::get('tickets/{id}', [TicketController::class, 'show'])->whereNumber('id');
Route::post('tickets/{id}/comments', [TicketController::class, 'comment'])->whereNumber('id');
Route::post('tickets/{id}/files', [TicketController::class, 'upload'])->whereNumber('id');
Route::get('tickets/{id}/files/{file}', [TicketController::class, 'download'])->whereNumber(['id', 'file']);
Route::post('tickets/{id}/{action}', [TicketController::class, 'act'])->whereNumber('id')
    ->whereIn('action', ['open', 'accept', 'start', 'hold', 'resume', 'done', 'reject', 'close', 'reopen', 'cancel', 'assign']);
Route::get('stats', [TicketController::class, 'stats']);
Route::get('reports', [TicketController::class, 'reports']);
Route::get('reports/{id}', [TicketController::class, 'report'])->whereNumber('id');
Route::post('reports/generate', [TicketController::class, 'generate']);

Route::middleware('perm:tickets.manage')->group(function () {
    Route::post('departments', [TicketController::class, 'storeDepartment']);
    Route::put('departments/{id}', [TicketController::class, 'updateDepartment']);
    Route::delete('departments/{id}', [TicketController::class, 'destroyDepartment']);
    Route::put('departments/{id}/members', [TicketController::class, 'members']);
});
