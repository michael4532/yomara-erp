<?php

use App\Modules\Fonds\FondsController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:fonds.request,fonds.approve,fonds.provide,fonds.view,fonds.manage')->group(function () {
    Route::get('requests', [C::class, 'index']);
    Route::get('meta', [C::class, 'meta']);
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::get('requests/{id}', [C::class, 'show'])->whereNumber('id');
    Route::get('requests/{id}/pdf', [C::class, 'pdf'])->whereNumber('id');
    Route::post('requests/{id}/{action}', [C::class, 'action'])->whereNumber('id')
        ->whereIn('action', ['submit', 'question', 'answer', 'approve', 'reject', 'provide', 'confirm', 'cancel', 'comment']);
});
Route::middleware('perm:fonds.request,fonds.manage')->group(function () {
    Route::post('requests', [C::class, 'store']);
    Route::put('requests/{id}', [C::class, 'update'])->whereNumber('id');
});
Route::middleware('perm:fonds.manage')->put('settings', [C::class, 'saveSettings']);
