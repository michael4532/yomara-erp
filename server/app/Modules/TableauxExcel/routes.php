<?php

use App\Modules\TableauxExcel\TableauxExcelController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:tableaux_excel.view,tableaux_excel.manage')->group(function () {
    Route::get('/', [C::class, 'index']);
    Route::get('generate', [C::class, 'generate']);
    Route::get('workbooks/{id}/download', [C::class, 'download'])->whereNumber('id');
});
Route::middleware('perm:tableaux_excel.manage')->group(function () {
    Route::post('workbooks', [C::class, 'save']);
    Route::put('workbooks/{id}', [C::class, 'save'])->whereNumber('id');
    Route::delete('workbooks/{id}', [C::class, 'destroy'])->whereNumber('id');
    Route::post('workbooks/{id}/send', [C::class, 'sendNow'])->whereNumber('id');
});
