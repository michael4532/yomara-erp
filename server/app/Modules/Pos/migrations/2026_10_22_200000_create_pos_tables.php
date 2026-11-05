<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('code', 20)->nullable(); // préfixe des numéros provisoires hors ligne (ex. C1)
            $table->uuid('warehouse_id')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('register_id')->index();
            $table->foreignId('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('status', 10)->default('open'); // open | closed
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 15, 2)->default(0);
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();
            $table->decimal('total_sales', 15, 2)->nullable();
            $table->unsignedInteger('tickets_count')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('trade_documents', function (Blueprint $table) {
            $table->uuid('pos_session_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropColumn('pos_session_id'));
        Schema::dropIfExists('pos_sessions');
        Schema::dropIfExists('pos_registers');
    }
};
