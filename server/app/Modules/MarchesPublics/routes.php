<?php

use App\Modules\MarchesPublics\MarchesController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:marches.view,marches.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('dashboard', [C::class, 'dashboard']);
});
Route::middleware('perm:marches.manage')->group(function () {
    Route::post('tenders/{id}/checklist', [C::class, 'checklist'])->whereNumber('id');
    Route::post('tenders/{id}/award', [C::class, 'award'])->whereNumber('id');
    Route::post('statements/{id}/invoice', [C::class, 'invoice'])->whereNumber('id');
});
