<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sync = function (Blueprint $t) {
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        };

        Schema::create('an_axes', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name', 80);
            $t->string('code', 20)->nullable();
            $t->string('system', 20)->nullable();      // 'projects' : sections créées depuis les projets
            $t->boolean('required')->default(false);   // achats et notes de frais doivent être ventilés sur cet axe
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $sync($t);
        });

        Schema::create('an_sections', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('axis_id')->index();
            $t->uuid('parent_id')->nullable();
            $t->string('code', 30)->nullable();
            $t->string('name', 120);
            $t->uuid('project_id')->nullable()->index();
            $t->foreignId('manager_id')->nullable();
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $sync($t);
        });

        Schema::create('an_rules', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('applies_to', 10)->default('any'); // sale, purchase, expense, payroll, any
            $t->uuid('product_id')->nullable();
            $t->uuid('category_id')->nullable();          // catégorie de produit
            $t->uuid('partner_id')->nullable();
            $t->uuid('expense_category_id')->nullable();
            $t->string('department', 120)->nullable();
            $t->json('distribution');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $sync($t);
        });

        Schema::create('an_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id');
            $t->uuid('axis_id');
            $t->uuid('section_id');
            $t->date('date');
            $t->decimal('amount', 15, 2);                 // produit > 0, charge < 0
            $t->string('account_code', 20)->nullable();
            $t->string('source_type', 20);                // document, expense, payroll, manual
            $t->string('source_id', 64)->nullable();
            $t->string('source_line_id', 64)->nullable();
            $t->string('label')->nullable();
            $t->uuid('partner_id')->nullable();
            $t->uuid('product_id')->nullable();
            $t->decimal('quantity', 15, 3)->nullable();
            $t->decimal('percent', 7, 3)->nullable();
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'section_id', 'date']);
            $t->index(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('bg_budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 10)->default('draft');   // draft, approved, closed
            $t->unsignedTinyInteger('warn_percent')->default(80);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->foreignId('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('bg_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id');
            $t->foreignId('budget_id')->index();
            $t->string('kind', 8)->default('expense');    // expense | revenue
            $t->uuid('section_id')->nullable();
            $t->string('account_prefix', 20)->nullable();
            $t->string('label')->nullable();
            $t->foreignId('owner_id')->nullable();
            $t->json('amounts');                          // {"2026-01": 100000, …}
            $t->decimal('total', 15, 2)->default(0);
            $t->unsignedTinyInteger('alerted')->default(0); // dernier seuil signalé (0, avertissement, 100)
            $t->unsignedSmallInteger('sort')->default(0);
        });

        // Ventilation analytique sur les documents, leurs lignes et les notes de frais
        Schema::table('trade_documents', fn (Blueprint $t) => $t->json('analytic')->nullable());
        Schema::table('trade_document_lines', fn (Blueprint $t) => $t->json('analytic')->nullable());
        if (Schema::hasTable('nf_expenses')) {
            Schema::table('nf_expenses', fn (Blueprint $t) => $t->json('analytic')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['bg_lines', 'bg_budgets', 'an_lines', 'an_rules', 'an_sections', 'an_axes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropColumn('analytic'));
        Schema::table('trade_document_lines', fn (Blueprint $t) => $t->dropColumn('analytic'));
        if (Schema::hasColumn('nf_expenses', 'analytic')) {
            Schema::table('nf_expenses', fn (Blueprint $t) => $t->dropColumn('analytic'));
        }
    }
};
