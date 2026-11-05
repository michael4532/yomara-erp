<?php

use App\Modules\Immobilier\ImmobilierController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:immobilier.view,immobilier.manage,immobilier.owners')->group(function () {
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::get('properties', [C::class, 'properties']);
    Route::get('leases', [C::class, 'leases']);
    Route::get('leases/{id}', [C::class, 'lease'])->whereNumber('id');
    Route::get('rents', [C::class, 'rents']);
    Route::get('rents/{id}/pdf', [C::class, 'rentPdf'])->whereNumber('id');
    Route::get('expenses', [C::class, 'expenses']);
});
Route::middleware('perm:immobilier.manage')->group(function () {
    Route::post('properties', [C::class, 'saveProperty']);
    Route::put('properties/{id}', [C::class, 'saveProperty'])->whereNumber('id');
    Route::delete('properties/{id}', [C::class, 'deleteProperty'])->whereNumber('id');
    Route::post('units', [C::class, 'saveUnit']);
    Route::put('units/{id}', [C::class, 'saveUnit'])->whereNumber('id');
    Route::delete('units/{id}', [C::class, 'deleteUnit'])->whereNumber('id');
    Route::post('leases', [C::class, 'createLease']);
    Route::put('leases/{id}', [C::class, 'updateLease'])->whereNumber('id');
    Route::post('leases/{id}/end', [C::class, 'endLease'])->whereNumber('id');
    Route::post('leases/{id}/deposit', [C::class, 'deposit'])->whereNumber('id');
    Route::post('leases/{id}/remind', [C::class, 'remind'])->whereNumber('id');
    Route::post('rents/generate', [C::class, 'generate']);
    Route::post('rents/{id}/pay', [C::class, 'pay'])->whereNumber('id');
    Route::post('expenses', [C::class, 'saveExpense']);
    Route::delete('expenses/{id}', [C::class, 'deleteExpense'])->whereNumber('id');
});
Route::middleware('perm:immobilier.owners')->group(function () {
    Route::get('owners', [C::class, 'owners']);
    Route::get('statement', [C::class, 'statement']);
    Route::get('statement/pdf', [C::class, 'statementPdf']);
    Route::post('payouts', [C::class, 'savePayout']);
    Route::delete('payouts/{id}', [C::class, 'deletePayout'])->whereNumber('id');
});
