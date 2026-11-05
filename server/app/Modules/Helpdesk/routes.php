<?php

use App\Modules\Helpdesk\HelpdeskController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:helpdesk.view,helpdesk.agent,helpdesk.manage')->group(function () {
    Route::get('stats', [HelpdeskController::class, 'stats']);
    Route::get('settings', [HelpdeskController::class, 'settings']);
    Route::get('agents', [HelpdeskController::class, 'agents']);
    Route::get('files/{id}', [HelpdeskController::class, 'download'])->whereUuid('id');
});
Route::middleware('perm:helpdesk.manage')->put('settings', [HelpdeskController::class, 'saveSettings']);
Route::middleware('perm:helpdesk.agent,helpdesk.manage')->post('tickets/{id}/files', [HelpdeskController::class, 'upload'])->whereUuid('id');
Route::middleware('perm:helpdesk.manage')->group(function () {
    Route::get('inbound', [HelpdeskController::class, 'inbound']);
    Route::put('inbound', [HelpdeskController::class, 'saveInbound']);
    Route::post('inbound/fetch', [HelpdeskController::class, 'fetchNow']);
});
