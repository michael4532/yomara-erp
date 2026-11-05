<?php

use App\Modules\Appro\ApproController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:appro.view,appro.manage')->group(function () {
    Route::get('meta', [C::class, 'meta']);
    Route::get('dashboard', [C::class, 'dashboard']);
    Route::get('imports', [C::class, 'imports']);
    Route::get('imports/{id}', [C::class, 'import'])->whereNumber('id');
    Route::get('imports/{id}/pdf', [C::class, 'importPdf'])->whereNumber('id');
    Route::get('imports/{id}/xlsx', [C::class, 'importXlsx'])->whereNumber('id');
    Route::get('specs', [C::class, 'specs']);
    Route::get('specs/{id}', [C::class, 'spec'])->whereNumber('id');
    Route::get('specs/{id}/pdf', [C::class, 'specPdf'])->whereNumber('id');
    Route::get('comparisons', [C::class, 'comparisons']);
    Route::get('comparisons/{id}', [C::class, 'comparison'])->whereNumber('id');
    Route::get('comparisons/{id}/pdf', [C::class, 'comparisonPdf'])->whereNumber('id');
    Route::get('comparisons/{id}/invites/{inviteId}/file', [C::class, 'inviteFile'])->whereNumber('id')->whereNumber('inviteId');
    Route::get('rentals', [C::class, 'rentals']);
    Route::get('rentals/xlsx', [C::class, 'rentalsXlsx']);
    Route::get('rentals/{id}', [C::class, 'rental'])->whereNumber('id');
    Route::get('suppliers', [C::class, 'scorecard']);
    Route::get('suppliers/xlsx', [C::class, 'scorecardXlsx']);
    Route::get('suppliers/{id}', [C::class, 'supplier'])->whereUuid('id');
});
Route::middleware('perm:appro.manage')->group(function () {
    Route::post('imports', [C::class, 'saveImport']);
    Route::put('imports/{id}', [C::class, 'saveImport'])->whereNumber('id');
    Route::post('imports/{id}/{action}', [C::class, 'importAction'])->whereNumber('id')->whereIn('action', ['apply', 'order']);
    Route::post('specs', [C::class, 'saveSpec']);
    Route::put('specs/{id}', [C::class, 'saveSpec'])->whereNumber('id');
    Route::post('specs/{id}/send', [C::class, 'sendSpec'])->whereNumber('id');
    Route::post('comparisons', [C::class, 'saveComparison']);
    Route::put('comparisons/{id}', [C::class, 'saveComparison'])->whereNumber('id');
    Route::post('comparisons/from', [C::class, 'compareFrom']);
    Route::post('comparisons/{id}/invite', [C::class, 'inviteSuppliers'])->whereNumber('id');
    Route::post('comparisons/{id}/messages', [C::class, 'offerMessage'])->whereNumber('id');
    Route::post('comparisons/{id}/read', [C::class, 'offersRead'])->whereNumber('id');
    Route::post('comparisons/{id}/{action}', [C::class, 'comparisonAction'])->whereNumber('id')->whereIn('action', ['prefill', 'award', 'cancel']);
    Route::post('rentals', [C::class, 'saveRental']);
    Route::put('rentals/{id}', [C::class, 'saveRental'])->whereNumber('id');
    Route::post('rentals/{id}/{action}', [C::class, 'rentalAction'])->whereNumber('id')->whereIn('action', ['extend', 'return', 'invoice', 'cancel']);
});
