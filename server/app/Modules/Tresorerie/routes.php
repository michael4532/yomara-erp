<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Tresorerie\Models\Forecast;
use App\Modules\Tresorerie\Models\Operation;
use App\Modules\Tresorerie\Models\TreasuryAccount;
use App\Modules\Tresorerie\TresorerieController as C;
use Illuminate\Support\Facades\Route;

EntityController::routes('accounts', TreasuryAccount::class);
EntityController::routes('operations', Operation::class);
EntityController::routes('forecasts', Forecast::class);
Route::middleware('perm:tresorerie.view,tresorerie.operate,tresorerie.manage')->group(function () {
    Route::get('summary', [C::class, 'summary']);
    Route::get('categories', [C::class, 'categories']);
    Route::get('forecast', [C::class, 'forecast']);
    Route::get('forecast-settings', [C::class, 'forecastSettings']);
});
Route::middleware('perm:tresorerie.manage')->group(function () {
    // Encaissements Mobile Money reçus par SMS
    Route::get('momo', [\App\Modules\Tresorerie\Momo\MomoController::class, 'index']);
    Route::post('momo', [\App\Modules\Tresorerie\Momo\MomoController::class, 'store']);
    Route::post('momo/test', [\App\Modules\Tresorerie\Momo\MomoController::class, 'test']);
    Route::post('momo/{id}/assign', [\App\Modules\Tresorerie\Momo\MomoController::class, 'assign'])->whereUuid('id');
    Route::post('momo/{id}/operation', [\App\Modules\Tresorerie\Momo\MomoController::class, 'operation'])->whereUuid('id');
    Route::post('momo/{id}/ignore', [\App\Modules\Tresorerie\Momo\MomoController::class, 'ignore'])->whereUuid('id');
    Route::post('accounts/{account}/momo-token', [\App\Modules\Tresorerie\Momo\MomoController::class, 'token'])->whereUuid('account');
    Route::put('forecast-settings', [C::class, 'saveForecastSettings']);
    // Synchronisation bancaire et règles de rapprochement
    $b = \App\Modules\Tresorerie\Bank\BankController::class;
    Route::get('bank', [$b, 'index']);
    Route::post('bank/connections', [$b, 'store']);
    Route::put('bank/connections/{id}', [$b, 'update'])->whereUuid('id');
    Route::delete('bank/connections/{id}', [$b, 'destroy'])->whereUuid('id');
    Route::post('bank/connections/{id}/sync', [$b, 'syncNow'])->whereUuid('id');
    Route::post('bank/connections/{id}/link', [$b, 'link'])->whereUuid('id');
    Route::get('bank/institutions', [$b, 'institutions']);
    Route::post('bank/rules', [$b, 'storeRule']);
    Route::put('bank/rules/{id}', [$b, 'updateRule'])->whereNumber('id');
    Route::delete('bank/rules/{id}', [$b, 'destroyRule'])->whereNumber('id');
    Route::post('bank/rules/apply', [$b, 'applyRules']);
    Route::post('accounts/{account}/statement/analyze', [C::class, 'analyze']);
    Route::post('accounts/{account}/statement/import', [C::class, 'import']);
    Route::get('accounts/{account}/statement/lines', [C::class, 'lines']);
    Route::post('accounts/{account}/statement/auto', [C::class, 'auto']);
    Route::get('statement-lines/{id}/candidates', [C::class, 'candidates']);
    Route::post('statement-lines/{id}', [C::class, 'act']);
});
