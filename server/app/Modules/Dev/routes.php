<?php

use App\Modules\Dev\DevController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:dev.view,dev.contribute,dev.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('dashboard', [C::class, 'dashboard']);
});
Route::middleware('perm:dev.manage')->group(function () {
    Route::post('releases/{id}/changelog', [C::class, 'changelog'])->whereNumber('id');
    Route::post('apps/{id}/git-secret', [C::class, 'regenerate'])->whereNumber('id');
});
