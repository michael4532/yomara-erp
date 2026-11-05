<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\RendezVous\Models\Appointment;
use App\Modules\RendezVous\Models\Closure;
use App\Modules\RendezVous\Models\Service;
use App\Modules\RendezVous\Models\Staff;
use App\Modules\RendezVous\RendezVousController;
use Illuminate\Support\Facades\Route;

EntityController::routes('services', Service::class);
EntityController::routes('staff', Staff::class);
EntityController::routes('closures', Closure::class);
EntityController::routes('appointments', Appointment::class);

Route::middleware('perm:rendez_vous.view,rendez_vous.own,rendez_vous.manage')->group(function () {
    Route::get('slots', [RendezVousController::class, 'slots']);
    Route::get('info', [RendezVousController::class, 'info']);
});
Route::middleware('perm:rendez_vous.manage')->group(function () {
    Route::post('appointments/{id}/invoice', [RendezVousController::class, 'invoice'])->whereUuid('id');
    Route::get('users', [RendezVousController::class, 'users']);
    Route::get('stats', [RendezVousController::class, 'stats']);
});
