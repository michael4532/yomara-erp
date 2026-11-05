<?php

use App\Modules\Btp\BtpController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:btp.view,btp.manage,btp.log')->group(function () {
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::get('sites/{id}', [C::class, 'show'])->whereNumber('id');
    Route::get('sites/{id}/estimate.pdf', [C::class, 'estimatePdf'])->whereNumber('id');
    Route::get('situations/{id}/pdf', [C::class, 'situationPdf'])->whereNumber('id');
});
Route::middleware('perm:btp.manage,btp.log')->group(function () {
    Route::post('sites/{id}/logs', [C::class, 'saveLog'])->whereNumber('id');
    Route::delete('logs/{id}', [C::class, 'deleteLog'])->whereNumber('id');
});
Route::middleware('perm:btp.manage')->group(function () {
    Route::post('sites', [C::class, 'saveSite']);
    Route::put('sites/{id}', [C::class, 'saveSite'])->whereNumber('id');
    Route::put('sites/{id}/items', [C::class, 'saveItems'])->whereNumber('id');
    Route::post('sites/{id}/quote', [C::class, 'quote'])->whereNumber('id');
    Route::post('sites/{id}/accept', [C::class, 'accept'])->whereNumber('id');
    Route::post('sites/{id}/lost', [C::class, 'lost'])->whereNumber('id');
    Route::post('sites/{id}/advance', [C::class, 'advance'])->whereNumber('id');
    Route::post('sites/{id}/situations/preview', [C::class, 'preview'])->whereNumber('id');
    Route::post('sites/{id}/situations', [C::class, 'createSituation'])->whereNumber('id');
    Route::post('sites/{id}/receive', [C::class, 'receive'])->whereNumber('id');
    Route::post('sites/{id}/release', [C::class, 'release'])->whereNumber('id');
});
