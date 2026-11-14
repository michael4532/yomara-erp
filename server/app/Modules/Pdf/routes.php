<?php

use App\Modules\Pdf\PdfController;
use Illuminate\Support\Facades\Route;

Route::middleware(['perm:pdf.use', 'throttle:40,1'])->post('{op}', [PdfController::class, 'run'])->whereIn('op', PdfController::OPS);
Route::middleware('perm:pdf.use')->group(function () {
    Route::get('history', [PdfController::class, 'history']);
    Route::get('history/{id}', [PdfController::class, 'historyFile'])->whereNumber('id');
    Route::delete('history/{id}', [PdfController::class, 'historyDelete'])->whereNumber('id');
});
