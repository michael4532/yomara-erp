<?php

use App\Modules\Acquisition\AcquisitionController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:acquisition.view,acquisition.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('report', [C::class, 'report']);
    Route::get('accounts', [C::class, 'accounts']);
    Route::get('search-console', [C::class, 'searchConsole']);
    Route::get('tracking', [C::class, 'tracking']);
});
Route::middleware('perm:acquisition.manage')->group(function () {
    Route::post('accounts', [C::class, 'storeAccount'])->middleware('throttle:20,1');
    Route::put('accounts/{id}', [C::class, 'updateAccount'])->whereNumber('id');
    Route::delete('accounts/{id}', [C::class, 'destroyAccount'])->whereNumber('id');
    Route::post('accounts/{id}/sync', [C::class, 'syncAccount'])->whereNumber('id')->middleware('throttle:10,1');
    Route::post('google/connect', [C::class, 'googleConnect']);
    Route::delete('google', [C::class, 'googleDisconnect']);
    Route::put('tracking', [C::class, 'saveTracking']);
    Route::post('sources/{source}/test', [C::class, 'testLead'])->whereNumber('source')->middleware('throttle:10,1');
});
