<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Etiquettes\EtiquettesController;
use App\Modules\Etiquettes\Models\LabelPrint;
use App\Modules\Etiquettes\Models\LabelTemplate;
use Illuminate\Support\Facades\Route;

EntityController::routes('templates', LabelTemplate::class);
EntityController::routes('prints', LabelPrint::class);
Route::post('barcodes', [EtiquettesController::class, 'barcodes'])->middleware('perm:etiquettes.manage');
