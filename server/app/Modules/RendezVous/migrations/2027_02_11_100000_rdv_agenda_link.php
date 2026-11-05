<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rdv_appointments', 'agenda_event_id')) {
            Schema::table('rdv_appointments', fn (Blueprint $t) => $t->uuid('agenda_event_id')->nullable()->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rdv_appointments', 'agenda_event_id')) {
            Schema::table('rdv_appointments', fn (Blueprint $t) => $t->dropColumn('agenda_event_id'));
        }
    }
};
