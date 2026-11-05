<?php

use App\Modules\ImportExport\ImportExportController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:import_export.import,import_export.export')->get('entities', [C::class, 'entities']);
Route::middleware('perm:import_export.import')->group(function () {
    Route::get('template/{entity}', [C::class, 'template']);
    Route::post('analyze', [C::class, 'analyze']);
    Route::post('jobs/{id}/run', [C::class, 'run']);
    Route::get('jobs', [C::class, 'jobs']);
    Route::get('jobs/{id}/errors', [C::class, 'errors']);
});
Route::middleware('perm:import_export.export')->get('export/{entity}', [C::class, 'export']);
