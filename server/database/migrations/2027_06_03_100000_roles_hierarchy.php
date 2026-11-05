<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fonctions de l'entreprise : rattachement hiérarchique (organigramme) et description ; responsable direct de chaque utilisateur
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $t) {
            if (! Schema::hasColumn('roles', 'parent_id')) {
                $t->unsignedBigInteger('parent_id')->nullable()->index()->after('name');
            }
            if (! Schema::hasColumn('roles', 'description')) {
                $t->string('description', 300)->nullable()->after('parent_id');
            }
            if (! Schema::hasColumn('roles', 'color')) {
                $t->string('color', 9)->nullable()->after('description');
            }
        });
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'manager_id')) {
                $t->unsignedBigInteger('manager_id')->nullable()->index()->after('role_id');
            }
            if (! Schema::hasColumn('users', 'job_title')) {
                $t->string('job_title', 120)->nullable()->after('manager_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn(['parent_id', 'description', 'color']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['manager_id', 'job_title']));
    }
};
