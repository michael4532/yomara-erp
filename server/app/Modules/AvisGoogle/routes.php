<?php

use App\Modules\AvisGoogle\AvisController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:avis.view,avis.reply,avis.manage')->group(function () {
    Route::get('/', [C::class, 'index']);
    Route::get('reviews', [C::class, 'reviews']);
    Route::get('locations/{id}/metrics', [C::class, 'metrics'])->whereNumber('id');
    Route::get('posts', [C::class, 'posts']);
    Route::get('requests', [C::class, 'requests']);
});
Route::middleware('perm:avis.reply,avis.manage')->group(function () {
    Route::post('reviews/{id}/reply', [C::class, 'reply'])->whereNumber('id');
    Route::delete('reviews/{id}/reply', [C::class, 'deleteReply'])->whereNumber('id');
    Route::post('reviews/{id}/suggest', [C::class, 'suggest'])->whereNumber('id');
    Route::post('requests', [C::class, 'storeRequest']);
    Route::delete('requests/{id}', [C::class, 'cancelRequest'])->whereNumber('id');
});
Route::middleware('perm:avis.manage')->group(function () {
    Route::post('connect', [C::class, 'connect']);
    Route::delete('connect', [C::class, 'disconnect']);
    Route::post('places/search', [C::class, 'searchPlaces']);
    Route::post('places', [C::class, 'addPlace']);
    Route::delete('locations/{id}', [C::class, 'removeLocation'])->whereNumber('id');
    Route::put('locations/{id}', [C::class, 'updateLocation'])->whereNumber('id');
    Route::put('settings', [C::class, 'saveSettings']);
    Route::post('sync', [C::class, 'sync']);
    Route::post('posts', [C::class, 'storePost']);
    Route::post('media', [C::class, 'media']);
    Route::delete('posts/{id}', [C::class, 'destroyPost'])->whereNumber('id');
});
