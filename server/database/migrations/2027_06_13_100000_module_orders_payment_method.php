<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mode de paiement d'une activation manuelle (espèces, Wave, virement…), repris sur le reçu
        Schema::table('module_orders', function (Blueprint $t) {
            $t->string('payment_method', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('module_orders', fn (Blueprint $t) => $t->dropColumn('payment_method'));
    }
};
