<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mode de règlement prévu et case « Pour décharge » sur les documents de vente et d'achat. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_documents', function (Blueprint $t) {
            if (! Schema::hasColumn('trade_documents', 'payment_method')) {
                $t->string('payment_method', 30)->nullable();
            }
            if (! Schema::hasColumn('trade_documents', 'receipt_box')) {
                $t->boolean('receipt_box')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('trade_documents', function (Blueprint $t) {
            $t->dropColumn(['payment_method', 'receipt_box']);
        });
    }
};
