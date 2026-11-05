<?php

use App\Modules\Marketing\MarketingController;
use App\Modules\Marketing\MarketingStudioController as Studio;
use Illuminate\Support\Facades\Route;

Route::middleware('perm:marketing.view,marketing.manage')->group(function () {
    Route::get('overview', [MarketingController::class, 'overview']);
    Route::get('contacts', [MarketingController::class, 'contacts']);
    Route::get('templates/{id}', [MarketingController::class, 'template']);
    Route::get('campaigns/{id}', [MarketingController::class, 'campaign']);
    Route::post('preview', [MarketingController::class, 'preview']);
    Route::get('layouts', [Studio::class, 'layouts']);
    Route::get('rss', [Studio::class, 'rss']);
    Route::get('contacts/{id}', [Studio::class, 'contact'])->whereNumber('id');
    Route::get('segment-fields', [Studio::class, 'segmentFields']);
    Route::post('segments/count', [Studio::class, 'segmentCount']);
    Route::get('forms', [Studio::class, 'forms']);
    Route::get('automations', [Studio::class, 'automations']);
    Route::get('automations/{id}', [Studio::class, 'automation']);
});

Route::middleware('perm:marketing.manage')->group(function () {
    Route::post('lists', [MarketingController::class, 'storeList']);
    Route::put('lists/{id}', [MarketingController::class, 'updateList']);
    Route::delete('lists/{id}', [MarketingController::class, 'destroyList']);
    Route::post('contacts', [MarketingController::class, 'storeContact']);
    Route::put('contacts/{id}', [MarketingController::class, 'updateContact']);
    Route::delete('contacts/{id}', [MarketingController::class, 'destroyContact']);
    Route::post('import', [MarketingController::class, 'import']);
    Route::post('templates', [MarketingController::class, 'storeTemplate']);
    Route::put('templates/{id}', [MarketingController::class, 'updateTemplate']);
    Route::delete('templates/{id}', [MarketingController::class, 'destroyTemplate']);
    Route::post('campaigns', [MarketingController::class, 'storeCampaign']);
    Route::put('campaigns/{id}', [MarketingController::class, 'updateCampaign']);
    Route::delete('campaigns/{id}', [MarketingController::class, 'destroyCampaign']);
    Route::post('campaigns/{id}/duplicate', [MarketingController::class, 'duplicate']);
    Route::post('campaigns/{id}/send', [MarketingController::class, 'send']);
    Route::post('campaigns/{id}/cancel', [MarketingController::class, 'cancel']);
    Route::post('campaigns/{id}/test', [MarketingController::class, 'test']);
    Route::post('campaigns/{id}/attachments', [MarketingController::class, 'attach']);
    Route::delete('campaigns/{id}/attachments', [MarketingController::class, 'detach']);
    Route::post('images', [MarketingController::class, 'image']);
    Route::post('segments', [Studio::class, 'storeSegment']);
    Route::put('segments/{id}', [Studio::class, 'updateSegment']);
    Route::delete('segments/{id}', [Studio::class, 'destroySegment']);
    Route::post('forms', [Studio::class, 'storeForm']);
    Route::put('forms/{id}', [Studio::class, 'updateForm']);
    Route::delete('forms/{id}', [Studio::class, 'destroyForm']);
    Route::post('automations', [Studio::class, 'storeAutomation']);
    Route::put('automations/{id}', [Studio::class, 'updateAutomation']);
    Route::post('automations/{id}/status', [Studio::class, 'setAutomationStatus']);
    Route::post('automations/{id}/enroll', [Studio::class, 'enroll']);
    Route::delete('automations/{id}', [Studio::class, 'destroyAutomation']);
});
