<?php

use App\Modules\Visio\VisioController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:visio.use,visio.webinar,visio.manage')->group(function () {
    Route::get('/', [C::class, 'index']);
    Route::post('meetings', [C::class, 'store']);
    Route::post('instant', [C::class, 'instant']);
    Route::get('meetings/{id}', [C::class, 'show'])->whereNumber('id');
    Route::put('meetings/{id}', [C::class, 'update'])->whereNumber('id');
    Route::delete('meetings/{id}', [C::class, 'destroy'])->whereNumber('id');
    Route::post('meetings/{id}/invite', [C::class, 'invite'])->whereNumber('id');
    Route::delete('meetings/{id}/participants/{pid}', [C::class, 'removeParticipant'])->whereNumber('id')->whereNumber('pid');
    Route::post('meetings/{id}/join', [C::class, 'join'])->whereNumber('id');
    Route::post('meetings/{id}/end', [C::class, 'end'])->whereNumber('id');
    Route::post('meetings/{id}/report', [C::class, 'regenerate'])->whereNumber('id');
    Route::put('meetings/{id}/report', [C::class, 'updateReport'])->whereNumber('id');
    Route::post('meetings/{id}/report/send', [C::class, 'sendReport'])->whereNumber('id');
    Route::get('meetings/{id}/participants.csv', [C::class, 'registrants'])->whereNumber('id');
    Route::get('meetings/{id}/calendar.ics', [C::class, 'ics'])->whereNumber('id');
    Route::get('recordings/{id}', [C::class, 'recording'])->whereNumber('id');
    Route::delete('recordings/{id}', [C::class, 'deleteRecording'])->whereNumber('id');
});
Route::middleware('perm:visio.manage')->group(function () {
    Route::get('settings', [C::class, 'settings']);
    Route::put('settings', [C::class, 'saveSettings']);
    Route::post('settings/test/{provider}', [C::class, 'testProvider']);
    Route::post('settings/reset/{provider}', [C::class, 'resetProvider']);
});
