<?php

use App\Modules\Location\LocationController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:location.view,location.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::get('availability', [C::class, 'availability']);
});
Route::middleware('perm:location.manage')->post('rentals/{id}/{action}', [C::class, 'action'])->whereNumber('id')->whereIn('action', ['reserve', 'checkout', 'return', 'invoice', 'cancel']);
