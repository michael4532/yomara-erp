<?php

use App\Modules\Maintenance\MaintenanceController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:maintenance.request,maintenance.view,maintenance.work,maintenance.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::post('orders/{id}/{action}', [C::class, 'action'])->whereNumber('id')->whereIn('action', ['approve', 'start', 'hold', 'finish', 'cancel']);
});
Route::middleware('perm:maintenance.manage')->post('generate', [C::class, 'generate']);
