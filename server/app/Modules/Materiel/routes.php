<?php

use App\Modules\Materiel\MaterielController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:materiel.use,materiel.view,materiel.manage')->group(function () {
    Route::get('checkouts/{id}/pdf', [C::class, 'pdf']);
    Route::post('checkouts/{id}/signature', [C::class, 'signature']);
    Route::get('settings', [C::class, 'settings']);
});
Route::middleware('perm:materiel.manage')->put('settings', [C::class, 'updateSettings']);
