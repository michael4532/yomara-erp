<?php

use App\Modules\Documents\DocumentsController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:documents.view,documents.manage,documents.admin')->group(function () {
    Route::get('usage', [DocumentsController::class, 'usage']);
    Route::post('files/{id}/office', [\App\Modules\Documents\OfficeController::class, 'open'])->whereUuid('id');
    Route::get('search', [DocumentsController::class, 'search']);
    Route::get('files/{id}/download', [DocumentsController::class, 'download'])->whereUuid('id');
    Route::get('files/{id}/versions', [DocumentsController::class, 'versions'])->whereUuid('id');
});

Route::middleware('perm:documents.manage,documents.admin')->group(function () {
    Route::post('office/new', [\App\Modules\Documents\OfficeController::class, 'create']);
    Route::get('roles', [DocumentsController::class, 'roles']);
    Route::post('files', [DocumentsController::class, 'upload']);
    Route::post('files/{id}/version', [DocumentsController::class, 'newVersion'])->whereUuid('id');
    Route::post('files/{id}/versions/{number}/restore', [DocumentsController::class, 'restoreVersion'])->whereUuid('id')->whereNumber('number');
    Route::get('trash', [DocumentsController::class, 'trash']);
    Route::post('files/{id}/restore', [DocumentsController::class, 'restore'])->whereUuid('id');
    Route::delete('files/{id}/purge', [DocumentsController::class, 'purge'])->whereUuid('id');
    Route::delete('trash', [DocumentsController::class, 'emptyTrash']);
    Route::get('shares', [DocumentsController::class, 'shares']);
    Route::post('shares', [DocumentsController::class, 'share']);
    Route::delete('shares/{id}', [DocumentsController::class, 'revoke'])->whereNumber('id');
});

Route::middleware('perm:documents.admin')->group(function () {
    Route::get('storage', [DocumentsController::class, 'storage']);
});
