<?php

use App\Modules\Compta\ComptaController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:compta.view,compta.manage')->group(function () {
    Route::get('setup', [ComptaController::class, 'setup']);
    Route::get('entries', [ComptaController::class, 'entries']);
    Route::get('balance', [ComptaController::class, 'balance']);
    Route::get('ledger/{account}', [ComptaController::class, 'ledger']);
    Route::get('income', [ComptaController::class, 'income']);
    Route::get('balance-sheet', [ComptaController::class, 'sheet']);
    Route::get('vat', [ComptaController::class, 'vat']);
    Route::get('export', [ComptaController::class, 'export']);
    Route::get('dashboard', [ComptaController::class, 'dashboard']);
    Route::get('purchases', [ComptaController::class, 'purchases']);
    Route::get('rules', [ComptaController::class, 'rules']);
    Route::get('tiers', [ComptaController::class, 'tiers']);
    Route::get('tiers/{partner}', [ComptaController::class, 'tiersLedger'])->whereUuid('partner');
    Route::get('statements/{kind}', [ComptaController::class, 'statement'])->whereIn('kind', ['income', 'sheet', 'cashflow']);
    Route::get('years', [ComptaController::class, 'years']);
    Route::get('years/{year}', [ComptaController::class, 'yearDetail'])->whereNumber('year');
    Route::get('years/{year}/liasse/{format?}', [ComptaController::class, 'liasse'])->whereNumber('year')->whereIn('format', ['xlsx', 'pdf']);
    Route::get('vat/return/{format?}', [ComptaController::class, 'vatReturn'])->whereIn('format', ['xlsx', 'pdf']);
    Route::get('group', [ComptaController::class, 'group']);
    Route::get('consolidation/{format?}', [ComptaController::class, 'consolidation'])->whereIn('format', ['xlsx', 'pdf']);
    Route::get('statements/{kind}/export', [ComptaController::class, 'exportStatement'])->whereIn('kind', ['income', 'sheet', 'cashflow', 'tiers']);
});

// Le service achats répond à un refus de la comptabilité
Route::middleware('perm:achats.manage,compta.manage')->post('purchases/{id}/resubmit', [ComptaController::class, 'resubmitPurchase'])->whereUuid('id');

Route::middleware('perm:compta.manage')->group(function () {
    Route::post('accounts', [ComptaController::class, 'storeAccount']);
    Route::put('accounts/{id}', [ComptaController::class, 'updateAccount']);
    Route::put('settings', [ComptaController::class, 'updateSettings']);
    Route::post('rebuild', [ComptaController::class, 'rebuild']);
    Route::post('entries', [ComptaController::class, 'storeEntry']);
    Route::post('entries/{id}/reverse', [ComptaController::class, 'reverseEntry']);
    Route::post('purchases/post-approved', [ComptaController::class, 'postApproved']);
    Route::post('purchases/{id}/decide', [ComptaController::class, 'decidePurchase'])->whereUuid('id');
    Route::post('rules', [ComptaController::class, 'saveRule']);
    Route::post('group/link-code', [ComptaController::class, 'groupLinkCode']);
    Route::post('group/members', [ComptaController::class, 'groupAttach']);
    Route::put('group/members/{id}', [ComptaController::class, 'groupUpdate'])->whereNumber('id');
    Route::delete('group/members/{id}', [ComptaController::class, 'groupDetach'])->whereNumber('id');
    Route::post('group/members/{id}/stakes', [ComptaController::class, 'groupStake'])->whereNumber('id');
    Route::delete('group/stakes/{id}', [ComptaController::class, 'groupStakeDelete'])->whereNumber('id');
    Route::put('group/settings', [ComptaController::class, 'groupSettings']);
    Route::post('group/adjustments', [ComptaController::class, 'groupAdjustment']);
    Route::delete('group/adjustments/{id}', [ComptaController::class, 'groupAdjustmentDelete'])->whereNumber('id');
    Route::post('consolidation/freeze', [ComptaController::class, 'consolidationFreeze']);
    Route::post('consolidation/unfreeze', [ComptaController::class, 'consolidationUnfreeze']);
    Route::put('fiscal-settings', [ComptaController::class, 'saveFiscalSettings']);
    Route::put('years/{year}/fiscal', [ComptaController::class, 'saveYearFiscal'])->whereNumber('year');
    Route::post('years/{year}/{action}', [ComptaController::class, 'yearAction'])->whereNumber('year')->whereIn('action', ['tax', 'close', 'reopen', 'allocate', 'cancel-allocation', 'depreciation']);
});
