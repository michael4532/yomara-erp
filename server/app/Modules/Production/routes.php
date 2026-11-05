<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\ProductionController;
use Illuminate\Support\Facades\Route;

EntityController::routes('boms', Bom::class);
EntityController::routes('orders', ProductionOrder::class);
Route::middleware('perm:production.view,production.operate,production.manage')->group(function () {
    Route::get('needs', [ProductionController::class, 'needs']);
    Route::get('boms/{id}/cost', [ProductionController::class, 'cost']);
});
