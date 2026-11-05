<?php

use App\Modules\Tarifs\TarifsController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:ventes.view,ventes.manage,pos.use,tarifs.manage')->post('resolve', [TarifsController::class, 'resolve']);
