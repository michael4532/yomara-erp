<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ie_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->string('user_name')->nullable();
            $t->string('entity', 30);
            $t->string('filename');
            $t->string('path');
            $t->string('status', 12)->default('analyzed'); // analyzed | checked | done
            $t->unsignedInteger('rows')->default(0);
            $t->unsignedInteger('created')->default(0);
            $t->unsignedInteger('updated')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->json('headers')->nullable();
            $t->json('mapping')->nullable();
            $t->json('options')->nullable();
            $t->json('errors')->nullable(); // [{row, message}]
            $t->timestamps();
        });

        // Factures reprises d'un ancien logiciel : comptabilisées en à-nouveaux, sans points de fidélité
        Schema::table('trade_documents', function (Blueprint $t) {
            $t->boolean('is_opening')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropColumn('is_opening'));
        Schema::dropIfExists('ie_jobs');
    }
};
