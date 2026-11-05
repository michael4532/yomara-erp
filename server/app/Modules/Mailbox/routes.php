<?php

use App\Modules\Mailbox\MailboxController as C;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:mailbox.use,mailbox.manage')->group(function () {
    Route::get('/', [C::class, 'index']);
    Route::get('changes', [C::class, 'changes']);
    Route::get('messages', [C::class, 'messages']);
    Route::post('messages/bulk', [C::class, 'bulk']);
    Route::get('messages/{id}', [C::class, 'show'])->whereNumber('id');
    Route::post('messages/{id}/flags', [C::class, 'flags'])->whereNumber('id');
    Route::post('messages/{id}/move', [C::class, 'move'])->whereNumber('id');
    Route::delete('messages/{id}', [C::class, 'destroy'])->whereNumber('id');
    Route::post('messages/{id}/convert', [C::class, 'convert'])->whereNumber('id');
    Route::post('messages/{id}/accept-invite', [C::class, 'acceptInvite'])->whereNumber('id');
    Route::get('messages/{id}/attachments/{att}', [C::class, 'download'])->whereNumber('id');
    Route::post('send', [C::class, 'send']);
    Route::get('outbox', [C::class, 'outbox']);
    Route::delete('outbox/{id}', [C::class, 'cancelOutbox'])->whereUuid('id');
    Route::post('outbox/{id}/retry', [C::class, 'retryOutbox'])->whereUuid('id');
    Route::post('accounts/detect', [C::class, 'detect']);
    Route::post('accounts', [C::class, 'storeAccount']);
    Route::post('accounts/hosted', [C::class, 'storeHosted']);
    Route::post('media', [C::class, 'uploadMedia']);
    Route::post('accounts/{id}/avatar', [C::class, 'avatar'])->whereNumber('id');
    Route::delete('accounts/{id}/avatar', [C::class, 'removeAvatar'])->whereNumber('id');
    Route::post('accounts/{id}/hosted-password', [C::class, 'hostedPassword'])->whereNumber('id');
    Route::get('accounts/{id}/deliverability', [C::class, 'deliverability'])->whereNumber('id');
    Route::put('accounts/{id}', [C::class, 'updateAccount'])->whereNumber('id');
    Route::delete('accounts/{id}', [C::class, 'destroyAccount'])->whereNumber('id');
    Route::post('accounts/{id}/sync', [C::class, 'syncAccount'])->whereNumber('id');
    Route::post('live', [C::class, 'live']);
    Route::post('messages/{id}/assist', [C::class, 'assist'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('accounts/{id}/shared', [C::class, 'addShared'])->whereNumber('id');
    Route::post('accounts/{id}/contacts', [C::class, 'importContacts'])->whereNumber('id');
    Route::post('folders', [C::class, 'storeFolder']);
    Route::put('folders/{id}', [C::class, 'updateFolder'])->whereNumber('id');
    Route::delete('folders/{id}', [C::class, 'destroyFolder'])->whereNumber('id');
    Route::post('oauth/{provider}', [C::class, 'oauthStart']);
    Route::post('signatures', [C::class, 'storeSignature']);
    Route::put('signature-defaults', [C::class, 'signatureDefaults']);
    Route::put('signatures/{id}', [C::class, 'updateSignature'])->whereNumber('id');
    Route::delete('signatures/{id}', [C::class, 'destroySignature'])->whereNumber('id');
    Route::post('templates', [C::class, 'storeTemplate']);
    Route::put('templates/{id}', [C::class, 'updateTemplate'])->whereNumber('id');
    Route::delete('templates/{id}', [C::class, 'destroyTemplate'])->whereNumber('id');
});
Route::middleware('perm:mailbox.manage')->group(function () {
    Route::get('settings', [C::class, 'settings']);
    Route::put('settings', [C::class, 'saveSettings']);
});
