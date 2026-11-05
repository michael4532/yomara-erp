<?php

use App\Modules\Qualite\QualiteController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:qualite.report,qualite.inspect,qualite.view,qualite.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('dashboard', [C::class, 'dashboard']);
});
Route::middleware('perm:qualite.inspect,qualite.manage')->group(function () {
    Route::get('plans/{id}/criteria', [C::class, 'criteria'])->whereNumber('id');
    Route::post('inspections/{id}/answers', [C::class, 'answers'])->whereNumber('id');
});
