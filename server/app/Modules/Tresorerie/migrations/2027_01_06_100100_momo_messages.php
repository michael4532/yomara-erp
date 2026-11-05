<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jeton de réception des SMS de confirmation d'un compte Mobile Money
        Schema::table('tr_accounts', fn (Blueprint $t) => $t->string('momo_token', 48)->nullable()->unique());
        // SMS reçus et leur rapprochement
        Schema::create('tr_momo_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('account_id')->index();
            $t->string('provider', 10)->nullable();
            $t->string('sender', 60)->nullable();
            $t->text('text');
            $t->string('hash', 64)->index();
            $t->string('direction', 8); // in, out, unknown
            $t->decimal('amount', 15, 2)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('payer_name', 120)->nullable();
            $t->string('reference', 60)->nullable();
            $t->string('memo', 150)->nullable();
            $t->timestamp('received_at');
            $t->string('status', 12)->index(); // matched, pending, ignored, outgoing, duplicate
            $t->string('match_reason', 160)->nullable();
            $t->uuid('partner_id')->nullable();
            $t->uuid('document_id')->nullable();
            $t->uuid('payment_id')->nullable();
            $t->uuid('operation_id')->nullable();
            $t->string('handled_by', 120)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tr_momo_messages');
        Schema::table('tr_accounts', fn (Blueprint $t) => $t->dropColumn('momo_token'));
    }
};
