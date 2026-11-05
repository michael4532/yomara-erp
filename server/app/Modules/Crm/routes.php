<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Crm\CrmController;
use App\Modules\Crm\SequenceController as Seq;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Stage;
use Illuminate\Support\Facades\Route;

EntityController::routes('stages', Stage::class);
EntityController::routes('leads', Lead::class);
EntityController::routes('activities', Activity::class);

Route::middleware('perm:crm.view,crm.manage')->group(function () {
    Route::get('stats', [CrmController::class, 'stats']);
    Route::get('users', [CrmController::class, 'users']);
});

Route::middleware('perm:crm.view,crm.manage')->group(function () {
    Route::get('sequences', [Seq::class, 'index']);
    Route::get('leads/{lead}/sequence', [Seq::class, 'leadRuns'])->whereUuid('lead');
});
Route::middleware('perm:crm.manage')->group(function () {
    Route::post('sequences', [Seq::class, 'store']);
    Route::put('sequences/{id}', [Seq::class, 'update'])->whereNumber('id');
    Route::delete('sequences/{id}', [Seq::class, 'destroy'])->whereNumber('id');
    Route::post('leads/{lead}/sequence', [Seq::class, 'enroll'])->whereUuid('lead');
    Route::delete('leads/{lead}/sequence', [Seq::class, 'stop'])->whereUuid('lead');
});
