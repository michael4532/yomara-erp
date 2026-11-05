<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const TABLES = ['partners', 'products', 'trade_documents', 'rh_employees', 'crm_leads', 'prj_projects'];

    public function up(): void
    {
        Schema::create('cf_fields', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('entity', 30);
            $t->string('key', 40);
            $t->string('label', 120);
            $t->string('type', 15); // text, textarea, number, money, date, select, multiselect, checkbox, url, email, phone
            $t->json('options')->nullable();
            $t->boolean('required')->default(false);
            $t->string('default_value')->nullable();
            $t->string('help')->nullable();
            $t->boolean('show_on_pdf')->default(false);
            $t->boolean('show_in_list')->default(false);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'entity']);
        });

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'custom')) {
                Schema::table($table, fn (Blueprint $t) => $t->json('custom')->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'custom')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('custom'));
            }
        }
        Schema::dropIfExists('cf_fields');
    }
};
