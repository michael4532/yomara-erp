<?php

use App\Modules\Juridique\JuridiqueController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:juridique.view,juridique.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::get('contracts/{id}/fields', [C::class, 'fields'])->whereNumber('id');
});
Route::middleware('perm:juridique.manage')->post('contracts/{id}/draft', [C::class, 'draft'])->whereNumber('id');
