<?php

use App\Modules\Liens\LiensController as L;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:liens.view,liens.manage')->group(function () {
    Route::get('overview', [L::class, 'overview']);
    Route::get('links', [L::class, 'index']);
    Route::get('stats', [L::class, 'stats']);
    Route::get('qrs', [L::class, 'qrs']);
    Route::get('qrs/{id}/{format}', [L::class, 'downloadQr'])->whereNumber('id')->whereIn('format', ['png', 'svg']);
});
Route::middleware('perm:liens.manage')->group(function () {
    Route::post('links', [L::class, 'store'])->middleware('throttle:60,1');
    Route::put('links/{id}', [L::class, 'update'])->whereNumber('id');
    Route::delete('links/{id}', [L::class, 'destroy'])->whereNumber('id');
    Route::post('preview', [L::class, 'preview'])->middleware('throttle:30,1');
    Route::post('qrs/render', [L::class, 'render'])->middleware('throttle:120,1');
    Route::post('qrs', [L::class, 'saveQr']);
    Route::put('qrs/{id}', [L::class, 'updateQr'])->whereNumber('id');
    Route::delete('qrs/{id}', [L::class, 'deleteQr'])->whereNumber('id');
});
