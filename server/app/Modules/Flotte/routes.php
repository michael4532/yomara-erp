<?php

use App\Modules\Flotte\FlotteController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:flotte.request,flotte.approve,flotte.view,flotte.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::get('availability', [C::class, 'availability']);
    Route::post('trips/{id}/{action}', [C::class, 'action'])->whereNumber('id')->whereIn('action', ['approve', 'refuse', 'checkout', 'return', 'cancel']);
});
