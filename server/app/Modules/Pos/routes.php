<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\PosController;
use Illuminate\Support\Facades\Route;

EntityController::routes('registers', PosRegister::class);
EntityController::routes('sessions', PosSession::class);
Route::get('sessions/{id}/report', [PosController::class, 'report'])->middleware('perm:pos.use,pos.manage');
