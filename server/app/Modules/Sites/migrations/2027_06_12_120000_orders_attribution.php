<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ws_orders', function (Blueprint $t) {
            $t->string('utm_source', 100)->nullable();
            $t->string('utm_medium', 100)->nullable();
            $t->string('utm_campaign', 150)->nullable()->index();
        });
        Schema::table('ws_messages', function (Blueprint $t) {
            $t->string('utm_source', 100)->nullable();
            $t->string('utm_campaign', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ws_orders', fn (Blueprint $t) => $t->dropColumn(['utm_source', 'utm_medium', 'utm_campaign']));
        Schema::table('ws_messages', fn (Blueprint $t) => $t->dropColumn(['utm_source', 'utm_campaign']));
    }
};
