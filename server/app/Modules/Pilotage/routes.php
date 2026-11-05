<?php

use App\Modules\Pilotage\PilotageController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:pilotage.view,pilotage.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('overview', [C::class, 'overview']);
    Route::get('forecast', [C::class, 'forecast']);
    Route::get('breakeven', [C::class, 'breakeven']);
    Route::get('profitability', [C::class, 'profitability']);
});
