<?php

use App\Modules\Ecommerce\EcommerceController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:ecommerce.view,ecommerce.manage,ecommerce.settings')->group(function () {
    Route::get('overview', [EcommerceController::class, 'overview']);
    Route::get('orders', [EcommerceController::class, 'orders']);
    Route::get('orders/{id}', [EcommerceController::class, 'show'])->whereNumber('id');
    Route::get('stats', [EcommerceController::class, 'stats']);
    Route::get('picking', [EcommerceController::class, 'picking']);
});
Route::middleware('perm:ecommerce.manage')->group(function () {
    Route::post('orders', [EcommerceController::class, 'create']);
    Route::post('orders/{id}/confirm', [EcommerceController::class, 'confirm'])->whereNumber('id');
    Route::post('orders/{id}/prepare', [EcommerceController::class, 'prepare'])->whereNumber('id');
    Route::post('orders/{id}/ship', [EcommerceController::class, 'ship'])->whereNumber('id');
    Route::post('orders/{id}/pay', [EcommerceController::class, 'pay'])->whereNumber('id');
    Route::post('orders/{id}/deliver', [EcommerceController::class, 'deliver'])->whereNumber('id');
    Route::post('orders/{id}/cancel', [EcommerceController::class, 'cancel'])->whereNumber('id');
    Route::post('orders/{id}/note', [EcommerceController::class, 'note'])->whereNumber('id');
});
Route::middleware('perm:ecommerce.settings')->group(function () {
    Route::put('settings', [EcommerceController::class, 'saveSettings']);
    Route::post('settings/feed-token', [EcommerceController::class, 'resetFeed']);
});
