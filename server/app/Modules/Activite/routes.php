<?php

use App\Modules\Activite\ActiviteController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:activite.use,activite.view_all')->group(function () {
    Route::get('report', [C::class, 'current']);
    Route::post('report', [C::class, 'save']);
    Route::get('report/file/{format}', [C::class, 'draftFile'])->whereIn('format', ['pdf', 'word']);
    Route::get('reports', [C::class, 'index']);
    Route::get('reports/{id}', [C::class, 'show'])->whereNumber('id');
    Route::get('reports/{id}/file/{format}', [C::class, 'file'])->whereNumber('id')->whereIn('format', ['pdf', 'word']);
    Route::post('reports/{id}/send', [C::class, 'send'])->whereNumber('id');
    Route::post('reports/{id}/review', [C::class, 'review'])->whereNumber('id');
    Route::get('supervisors', [C::class, 'supervisors']);
    Route::post('entries', [C::class, 'storeEntry']);
    Route::put('entries/{id}', [C::class, 'updateEntry'])->whereNumber('id');
    Route::delete('entries/{id}', [C::class, 'deleteEntry'])->whereNumber('id');
});
