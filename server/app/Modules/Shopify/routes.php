<?php

use App\Modules\Shopify\ShopifyController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:woo.view,woo.manage')->group(function () {
    Route::get('/', [C::class, 'index']);
    Route::get('stores/{id}/products', [C::class, 'products'])->whereNumber('id');
    Route::get('stores/{id}/orders', [C::class, 'orders'])->whereNumber('id');
    Route::get('stores/{id}/logs', [C::class, 'logs'])->whereNumber('id');
});
Route::middleware('perm:woo.manage')->group(function () {
    Route::post('stores', [C::class, 'connect']);
    Route::put('stores/{id}', [C::class, 'update'])->whereNumber('id');
    Route::delete('stores/{id}', [C::class, 'destroy'])->whereNumber('id');
    Route::post('stores/{id}/sync', [C::class, 'sync'])->whereNumber('id');
    Route::post('stores/{id}/link', [C::class, 'link'])->whereNumber('id');
    Route::post('stores/{id}/products', [C::class, 'choose'])->whereNumber('id');
    Route::post('stores/{id}/orders/{linkId}/status', [C::class, 'setStatus'])->whereNumber('id')->whereNumber('linkId');
});
