<?php

use App\Modules\Whatsapp\InboxController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:whatsapp.use,whatsapp.manage')->group(function () {
    Route::get('inbox', [C::class, 'index']);
    Route::get('conversations', [C::class, 'conversations']);
    Route::post('conversations', [C::class, 'start']);
    Route::get('conversations/{id}', [C::class, 'show'])->whereNumber('id');
    Route::put('conversations/{id}', [C::class, 'update'])->whereNumber('id');
    Route::post('conversations/{id}/messages', [C::class, 'send'])->whereNumber('id');
    Route::post('conversations/{id}/partner', [C::class, 'createPartner'])->whereNumber('id');
    Route::post('quick-replies', [C::class, 'storeQuick']);
    Route::put('quick-replies/{id}', [C::class, 'updateQuick'])->whereNumber('id');
    Route::delete('quick-replies/{id}', [C::class, 'destroyQuick'])->whereNumber('id');
});
Route::middleware('perm:whatsapp.manage')->group(function () {
    Route::put('inbox/settings', [C::class, 'saveSettings']);
    Route::post('templates/sync', [C::class, 'syncTemplates']);
    Route::post('templates', [C::class, 'createTemplate']);
});
