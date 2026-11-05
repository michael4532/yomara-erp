<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->char('currency', 3);
            $t->date('date');
            $t->decimal('rate', 18, 8);   // 1 unité de la devise = rate unités de la devise de la société
            $t->string('note')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'currency', 'date']);
        });

        Schema::table('trade_documents', fn (Blueprint $t) => $t->decimal('exchange_rate', 18, 8)->default(1));
        Schema::table('payments', fn (Blueprint $t) => $t->decimal('exchange_rate', 18, 8)->default(1));
        Schema::table('partners', fn (Blueprint $t) => $t->char('currency', 3)->nullable());
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropColumn('exchange_rate'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('exchange_rate'));
        Schema::table('partners', fn (Blueprint $t) => $t->dropColumn('currency'));
    }
};
