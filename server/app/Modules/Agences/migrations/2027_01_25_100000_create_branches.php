<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->string('code', 20)->nullable();
            $t->string('city')->nullable();
            $t->string('address')->nullable();
            $t->string('phone', 40)->nullable();
            $t->foreignId('manager_id')->nullable();      // responsable d'agence (utilisateur)
            $t->uuid('warehouse_id')->nullable();         // entrepôt de l'agence
            $t->uuid('cash_account_id')->nullable();      // caisse de l'agence (trésorerie)
            $t->boolean('is_head_office')->default(false); // siège
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        // Agences auxquelles un utilisateur a accès (aucune = toutes)
        Schema::create('branch_user', function (Blueprint $t) {
            $t->id();
            $t->uuid('branch_id')->index();
            $t->foreignId('user_id')->index();
            $t->unique(['branch_id', 'user_id']);
        });
        foreach (['trade_documents', 'warehouses', 'pos_registers', 'tr_accounts', 'tr_operations', 'an_sections'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->uuid('branch_id')->nullable()->after('company_id');
                    $t->index(['company_id', 'branch_id'], $table.'_branch_idx');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['trade_documents', 'warehouses', 'pos_registers', 'tr_accounts', 'tr_operations', 'an_sections'] as $table) {
            if (Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex($table.'_branch_idx');
                    $t->dropColumn('branch_id');
                });
            }
        }
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('branches');
    }
};
