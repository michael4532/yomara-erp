<?php

use App\Modules\Migration\MigrationController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:migration.run')->group(function () {
    Route::get('/', [C::class, 'index']);
    Route::post('connect', [C::class, 'connect']);
    Route::post('files', [C::class, 'files']);
    Route::post('jobs/{id}/run', [C::class, 'run'])->whereNumber('id');
    Route::get('jobs/{id}', [C::class, 'show'])->whereNumber('id');
    Route::delete('jobs/{id}', [C::class, 'destroy'])->whereNumber('id');
});
