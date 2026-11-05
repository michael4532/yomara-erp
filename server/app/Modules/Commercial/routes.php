<?php

use App\Modules\Commercial\CommercialController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:commercial.field,commercial.view,commercial.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::post('tours/{id}/vehicle', [C::class, 'vehicle'])->whereNumber('id');
});
