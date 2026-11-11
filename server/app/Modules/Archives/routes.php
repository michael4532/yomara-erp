<?php

use App\Modules\Archives\ArchivesController as A;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:archives.view,archives.manage')->group(function () {
    Route::get('dashboard', [A::class, 'dashboard']);
    Route::get('series', [A::class, 'series']);
    Route::get('records', [A::class, 'index']);
    Route::get('records/{id}', [A::class, 'show'])->whereUuid('id');
    Route::get('records/{id}/download', [A::class, 'download'])->whereUuid('id');
    Route::post('records/{id}/verify', [A::class, 'verify'])->whereUuid('id');
    Route::post('records/{id}/loans', [A::class, 'requestLoan'])->whereUuid('id');
});

// Archiviste
Route::middleware('perm:archives.manage')->group(function () {
    Route::post('series', [A::class, 'saveSeries']);
    Route::put('series/{id}', [A::class, 'saveSeries'])->whereNumber('id');
    Route::delete('series/{id}', [A::class, 'deleteSeries'])->whereNumber('id');
    Route::post('records', [A::class, 'store'])->middleware('throttle:60,1');
    Route::post('records/from-ged', [A::class, 'fromGed']);
    Route::put('records/{id}', [A::class, 'update'])->whereUuid('id');
    Route::post('verify-all', [A::class, 'verifyAll'])->middleware('throttle:6,1');
    Route::get('loans', [A::class, 'loans']);
    Route::post('loans/{id}', [A::class, 'decideLoan'])->whereNumber('id');
    Route::get('disposals', [A::class, 'disposals']);
    Route::post('disposals', [A::class, 'propose']);
    Route::get('journal', [A::class, 'journal']);
});

// Visa et exécution des éliminations : droit distinct
Route::middleware('perm:archives.dispose')->group(function () {
    Route::post('disposals/{id}', [A::class, 'decideDisposal'])->whereNumber('id');
});
