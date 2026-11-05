<?php

use App\Modules\Social\InboxController as I;
use App\Modules\Social\SocialController as S;
use App\Modules\Social\StudioController as V;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:social.view,social.manage,social.publish,social.accounts')->group(function () {
    Route::get('overview', [S::class, 'overview']);
    Route::get('posts', [S::class, 'index']);
    Route::get('posts/{id}', [S::class, 'show'])->whereNumber('id');
    Route::get('analytics', [S::class, 'analytics']);
    Route::get('best-times', [S::class, 'bestTimes']);
    Route::get('media', [S::class, 'media']);
});
Route::middleware('perm:social.manage,social.publish')->group(function () {
    Route::post('posts', [S::class, 'store']);
    Route::put('posts/{id}', [S::class, 'update'])->whereNumber('id');
    Route::post('posts/{id}/duplicate', [S::class, 'duplicate'])->whereNumber('id');
    Route::post('posts/{id}/cancel', [S::class, 'cancel'])->whereNumber('id');
    Route::delete('posts/{id}', [S::class, 'destroy'])->whereNumber('id');
    Route::post('media', [S::class, 'upload'])->middleware('throttle:60,1');
    Route::delete('media/{id}', [S::class, 'deleteMedia'])->whereNumber('id');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('assist/adapt', [S::class, 'adapt']);
        Route::post('assist/ideas', [S::class, 'ideas']);
        Route::post('assist/hashtags', [S::class, 'hashtags']);
    });
    Route::post('assist/fit', [S::class, 'fit']);
});
Route::middleware('perm:social.publish')->group(function () {
    Route::post('posts/{id}/approve', [S::class, 'approve'])->whereNumber('id');
    Route::post('posts/{id}/reject', [S::class, 'reject'])->whereNumber('id');
    Route::post('posts/{id}/publish', [S::class, 'publishNow'])->whereNumber('id');
    Route::post('posts/{id}/retry', [S::class, 'retry'])->whereNumber('id');
    Route::post('stats/sync', [S::class, 'syncStats'])->middleware('throttle:6,1');
});
Route::middleware('perm:social.accounts')->group(function () {
    Route::post('connect/{provider}', [S::class, 'connect'])->whereIn('provider', ['meta', 'threads', 'linkedin', 'x', 'tiktok', 'pinterest', 'google']);
    Route::post('connect-token/{network}', [S::class, 'connectToken'])->whereIn('network', ['telegram', 'mastodon', 'bluesky', 'sensya'])->middleware('throttle:20,1');
    Route::put('accounts/{id}', [S::class, 'updateAccount'])->whereNumber('id');
    Route::delete('accounts/{id}', [S::class, 'disconnect'])->whereNumber('id');
});

// Boîte de réception (commentaires et messages)
Route::middleware('perm:social.inbox,social.manage,social.publish')->group(function () {
    Route::get('inbox', [I::class, 'index']);
    Route::get('inbox/{id}', [I::class, 'show'])->whereNumber('id');
    Route::post('inbox/{id}/reply', [I::class, 'reply'])->whereNumber('id')->middleware('throttle:60,1');
    Route::put('inbox/{id}', [I::class, 'update'])->whereNumber('id');
    Route::post('inbox/{id}/messages/{message}/hide', [I::class, 'hide'])->whereNumber('id')->whereNumber('message');
    Route::post('inbox/{id}/lead', [I::class, 'toLead'])->whereNumber('id');
    Route::post('inbox/refresh', [I::class, 'refresh'])->middleware('throttle:10,1');
    Route::get('inbox-rules', [I::class, 'rules']);
});
Route::middleware('perm:social.accounts,social.publish')->group(function () {
    Route::put('inbox-rules', [I::class, 'saveRules']);
    Route::post('accounts/{account}/inbox', [I::class, 'enable'])->whereNumber('account');
    Route::delete('accounts/{account}/inbox', [I::class, 'disable'])->whereNumber('account');
});
// Charte de marque et visuels
Route::middleware('perm:social.view,social.manage,social.publish')->group(function () {
    Route::get('brand', [V::class, 'brand']);
    Route::get('visuals/templates', [V::class, 'templates']);
});
Route::middleware('perm:social.manage,social.publish')->group(function () {
    Route::put('brand', [V::class, 'saveBrand']);
    Route::post('visuals', [V::class, 'generate'])->middleware('throttle:30,1');
    Route::post('visuals/preview', [V::class, 'preview'])->middleware('throttle:60,1');
});
