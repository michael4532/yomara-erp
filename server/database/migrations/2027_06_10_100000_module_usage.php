<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jours d'utilisation de chaque module par société (une ligne par jour au plus) : tableau de bord d'adoption. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_usage')) {
            Schema::create('module_usage', function (Blueprint $t) {
                $t->foreignId('company_id');
                $t->string('module_key', 50);
                $t->date('day');
                $t->primary(['company_id', 'module_key', 'day']);
                $t->index('day');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_usage');
    }
};
