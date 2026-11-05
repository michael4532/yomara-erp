<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Projets\Models\Milestone;
use App\Modules\Projets\Models\Project;
use App\Modules\Projets\Models\Task;
use App\Modules\Projets\Models\Timesheet;
use App\Modules\Projets\ProjetsController;
use Illuminate\Support\Facades\Route;

EntityController::routes('projects', Project::class);
EntityController::routes('tasks', Task::class);
EntityController::routes('milestones', Milestone::class);
EntityController::routes('timesheets', Timesheet::class);

Route::middleware('perm:projets.view,projets.contribute,projets.manage')->group(function () {
    Route::get('stats', [ProjetsController::class, 'stats']);
    Route::get('users', [ProjetsController::class, 'users']);
});
Route::middleware('perm:projets.manage')->post('projects/{id}/invoice', [ProjetsController::class, 'invoice'])->whereUuid('id');
Route::middleware('perm:projets.manage')->group(function () {
    Route::get('projects/{id}/costs', [ProjetsController::class, 'costs'])->whereUuid('id');
    Route::get('cost-settings', [ProjetsController::class, 'costSettings']);
    Route::put('cost-settings', [ProjetsController::class, 'saveCostSettings']);
});
