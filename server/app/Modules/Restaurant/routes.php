<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Restaurant\Models\Floor;
use App\Modules\Restaurant\Models\Order;
use App\Modules\Restaurant\Models\OrderLine;
use App\Modules\Restaurant\Models\Station;
use App\Modules\Restaurant\Models\Table;
use App\Modules\Restaurant\RestaurantController;
use Illuminate\Support\Facades\Route;

EntityController::routes('floors', Floor::class);
EntityController::routes('tables', Table::class);
EntityController::routes('stations', Station::class);
EntityController::routes('orders', Order::class);
EntityController::routes('order-lines', OrderLine::class);
Route::get('stats', [RestaurantController::class, 'stats'])->middleware('perm:restaurant.manage');
