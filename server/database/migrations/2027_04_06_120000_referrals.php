<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Parrainage : chaque client partage son lien et gagne du crédit sur ses achats quand ses filleuls achètent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('referral_code', 20)->nullable()->unique();
            $t->unsignedBigInteger('referred_by')->nullable()->index();
            $t->decimal('referral_credit', 15, 2)->default(0);
        });
        Schema::table('module_orders', function (Blueprint $t) {
            $t->decimal('credit_used', 15, 2)->default(0);
        });
        Schema::create('referral_rewards', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('referrer_id')->index();
            $t->unsignedBigInteger('referee_id')->index();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->decimal('amount', 15, 2);
            $t->string('kind', 10)->default('earned'); // earned, spent
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
        Schema::table('module_orders', fn (Blueprint $t) => $t->dropColumn('credit_used'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['referral_code', 'referred_by', 'referral_credit']));
    }
};
