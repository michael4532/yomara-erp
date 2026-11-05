<?php

use App\Modules\Veille\VeilleController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:veille.view,veille.manage,veille.report')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::get('compare', [C::class, 'compare']);
    Route::get('competitors/{id}/libraries', [C::class, 'libraries'])->whereNumber('id');
    Route::get('observations/{id}/photo', [C::class, 'photo'])->whereNumber('id');
    Route::post('analysis', [C::class, 'analysis'])->middleware('throttle:20,1');
});
Route::middleware('perm:veille.manage,veille.report')->group(function () {
    Route::post('observations/{id}/photo', [C::class, 'uploadPhoto'])->whereNumber('id');
    Route::post('mentions/{id}/observe', [C::class, 'mentionToObservation'])->whereNumber('id');
});
Route::middleware('perm:veille.manage')->group(function () {
    Route::post('competitors/{id}/audit', [C::class, 'audit'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('competitors/{id}/battle-card', [C::class, 'battleCard'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('watches/{id}/check', [C::class, 'checkWatch'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('alerts/{id}/check', [C::class, 'checkAlert'])->whereNumber('id')->middleware('throttle:30,1');
});
