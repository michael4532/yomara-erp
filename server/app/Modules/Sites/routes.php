<?php

use App\Modules\Sites\SitesController;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:sites.view,sites.manage')->group(function () {
    Route::get('overview', [SitesController::class, 'overview']);
    Route::get('templates', [SitesController::class, 'templates']);
    Route::get('pricing', [SitesController::class, 'pricing']);
    Route::post('estimate', [SitesController::class, 'estimate']);
    Route::get('messages', [SitesController::class, 'messages']);
    Route::get('stats', [SitesController::class, 'stats']);
    Route::get('shop', [SitesController::class, 'shop']);
    Route::get('posts', [SitesController::class, 'posts']);
    Route::get('shop/products', [SitesController::class, 'productPages']);
});
Route::middleware('perm:sites.manage')->group(function () {
    Route::post('requests', [SitesController::class, 'submit']);
    Route::post('requests/{id}/cancel', [SitesController::class, 'cancel'])->whereNumber('id');
    Route::post('requests/{id}/decide', [SitesController::class, 'decide'])->whereNumber('id');
    Route::put('site', [SitesController::class, 'update']);
    Route::post('site/images', [SitesController::class, 'upload']);
    Route::post('site/images/remove', [SitesController::class, 'removeImage']);
    Route::post('site/publish', [SitesController::class, 'publish']);
    Route::put('site/domain', [SitesController::class, 'domain']);
    Route::post('site/domain/check', [SitesController::class, 'checkDomain']);
    Route::post('messages/{id}/read', [SitesController::class, 'readMessage'])->whereNumber('id');
    Route::put('shop/settings', [SitesController::class, 'saveShop']);
    Route::post('shop/products/{id}', [SitesController::class, 'saveProductPage'])->whereUuid('id');
    Route::post('shop/products/{id}/photos', [SitesController::class, 'productPhoto'])->whereUuid('id');
    Route::post('shop/products/{id}/photos/remove', [SitesController::class, 'removeProductPhoto'])->whereUuid('id');
    Route::post('orders/{id}/status', [SitesController::class, 'orderStatus'])->whereNumber('id');
    Route::post('posts', [SitesController::class, 'savePost']);
    Route::post('posts/{id}', [SitesController::class, 'savePost'])->whereNumber('id');
    Route::delete('posts/{id}', [SitesController::class, 'deletePost'])->whereNumber('id');
    Route::post('coupons', [SitesController::class, 'saveCoupon']);
    Route::post('coupons/{id}', [SitesController::class, 'saveCoupon'])->whereNumber('id');
    Route::delete('coupons/{id}', [SitesController::class, 'deleteCoupon'])->whereNumber('id');
    Route::post('reviews/{id}', [SitesController::class, 'updateReview'])->whereNumber('id');
});
