<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('partner_id')->index();
            $table->string('number', 40);
            $table->integer('points')->default(0);
            $table->integer('earned_12m')->default(0);
            $table->string('tier', 40)->nullable();
            $table->string('status', 20)->default('active'); // active, blocked
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('lc_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('card_id')->index();
            $table->string('type', 20); // earn, redeem, bonus, adjust, expire, reverse
            $table->integer('points');
            $table->integer('remaining')->default(0); // points encore disponibles (gains, consommés du plus ancien au plus récent)
            $table->decimal('amount', 15, 2)->default(0);
            $table->uuid('document_id')->nullable()->index();
            $table->uuid('payment_id')->nullable()->index();
            $table->string('note')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lc_rewards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->unsignedInteger('points_cost');
            $table->string('kind', 20)->default('amount'); // amount, product
            $table->decimal('amount', 15, 2)->default(0);
            $table->uuid('product_id')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('code', 40);
            $table->decimal('initial_amount', 15, 2);
            $table->decimal('balance', 15, 2);
            $table->uuid('partner_id')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('message')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status', 20)->default('active'); // active, used, expired, cancelled
            $table->uuid('source_document_id')->nullable();
            $table->string('kind', 20)->default('gift'); // gift (vendue), voucher (bon offert / avoir)
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('gift_card_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->uuid('gift_card_id')->index();
            $table->decimal('amount', 15, 2);
            $table->uuid('payment_id')->nullable()->index();
            $table->uuid('document_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_moves');
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('lc_rewards');
        Schema::dropIfExists('lc_transactions');
        Schema::dropIfExists('lc_cards');
    }
};
