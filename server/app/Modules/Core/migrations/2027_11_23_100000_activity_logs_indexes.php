<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Historique d'activité de tout l'ERP : recherches par date, par personne et par fiche. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $t) {
            $t->index(['company_id', 'created_at'], 'activity_logs_company_date');
            $t->index(['company_id', 'user_id'], 'activity_logs_company_user');
            $t->index(['company_id', 'subject_id'], 'activity_logs_company_subject');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $t) {
            $t->dropIndex('activity_logs_company_date');
            $t->dropIndex('activity_logs_company_user');
            $t->dropIndex('activity_logs_company_subject');
        });
    }
};
