<?php

use App\Modules\Devises\DevisesController;
use Illuminate\Support\Facades\Route;

Route::get('currencies', [DevisesController::class, 'index']);
Route::get('rate', [DevisesController::class, 'rate']);
Route::middleware('perm:devises.manage')->put('currencies', [DevisesController::class, 'update']);
