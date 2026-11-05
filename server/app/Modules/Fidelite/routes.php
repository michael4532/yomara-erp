<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Fidelite\FideliteController;
use App\Modules\Fidelite\Models\GiftCard;
use App\Modules\Fidelite\Models\LoyaltyCard;
use App\Modules\Fidelite\Models\LoyaltyTransaction;
use App\Modules\Fidelite\Models\Reward;
use Illuminate\Support\Facades\Route;

EntityController::routes('cards', LoyaltyCard::class);
EntityController::routes('transactions', LoyaltyTransaction::class);
EntityController::routes('rewards', Reward::class);
EntityController::routes('gift-cards', GiftCard::class);

Route::middleware('perm:fidelite.view,fidelite.manage,pos.use,ventes.view')->group(function () {
    Route::get('settings', [FideliteController::class, 'settings']);
    Route::get('lookup', [FideliteController::class, 'lookup']);
});
Route::middleware('perm:fidelite.view,fidelite.manage')->get('stats', [FideliteController::class, 'stats']);
