<?php

use App\Modules\Questionnaires\QuestionnairesController as C;
use App\Support\Records\Records;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:questionnaires.view,questionnaires.answer,questionnaires.manage')->group(function () {
    Records::routes(__DIR__.'/records.php');
    Route::get('forms/{id}/questions', [C::class, 'questions'])->whereNumber('id');
    Route::get('forms/{id}/results', [C::class, 'results'])->whereNumber('id');
    Route::get('forms/{id}/export', [C::class, 'export'])->whereNumber('id');
    Route::post('forms/{id}/responses', [C::class, 'answer'])->whereNumber('id');
});
Route::middleware('perm:questionnaires.manage')->group(function () {
    Route::put('forms/{id}/questions', [C::class, 'saveQuestions'])->whereNumber('id');
    Route::post('forms/{id}/duplicate', [C::class, 'duplicate'])->whereNumber('id');
});
