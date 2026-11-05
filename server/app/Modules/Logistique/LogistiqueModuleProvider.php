<?php

namespace App\Modules\Logistique;

use App\Modules\Logistique\Models\Delivery;
use Illuminate\Support\ServiceProvider;

class LogistiqueModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        Delivery::saved(function (Delivery $d) {
            app(LogisticsService::class)->afterSave($d);
        });
    }
}
