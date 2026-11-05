<?php

use App\Modules\Contenus\ContenusController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:contenus.view,contenus.manage,contenus.validate,contenus.watch')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::get('social/accounts', [C::class, 'accounts']);
});
Route::middleware('perm:contenus.manage,contenus.validate')->group(function () {
    Route::post('social/connect/{platform}', [C::class, 'connect'])->whereIn('platform', ['meta', 'linkedin']);
    Route::delete('social/accounts/{id}', [C::class, 'disconnect'])->whereNumber('id');
    Route::post('posts/{id}/publish', [C::class, 'publish'])->whereNumber('id');
    Route::post('social/stats', [C::class, 'stats']);
});
