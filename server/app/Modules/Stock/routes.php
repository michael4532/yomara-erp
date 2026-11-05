<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Stock\StockController;
use Illuminate\Support\Facades\Route;

EntityController::routes('warehouses', Warehouse::class);
EntityController::routes('moves', StockMove::class);

Route::get('levels', [StockController::class, 'levels'])->middleware('perm:stock.view,stock.manage');
Route::post('transfer', [StockController::class, 'transfer'])->middleware('perm:stock.manage');
Route::post('inventory', [StockController::class, 'inventory'])->middleware('perm:stock.manage');
