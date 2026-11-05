<?php

use App\Modules\Pdf\PdfController;
use Illuminate\Support\Facades\Route;

Route::middleware(['perm:pdf.use', 'throttle:40,1'])->post('{op}', [PdfController::class, 'run'])->whereIn('op', PdfController::OPS);
