<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bornes d'accueil : borne d'inscription, heure réelle d'inscription (borne hors ligne), provenance du visiteur
        Schema::table('ev_ticket_orders', function (Blueprint $t) {
            $t->string('kiosk', 60)->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->string('buyer_city', 120)->nullable();
            $t->string('buyer_country', 80)->nullable();
            $t->index(['event_id', 'registered_at']);
        });
        Schema::table('ev_checkins', function (Blueprint $t) {
            $t->index(['event_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ev_checkins', fn (Blueprint $t) => $t->dropIndex(['event_id', 'scanned_at']));
        Schema::table('ev_ticket_orders', function (Blueprint $t) {
            $t->dropIndex(['event_id', 'registered_at']);
            $t->dropColumn(['kiosk', 'registered_at', 'buyer_city', 'buyer_country']);
        });
    }
};
