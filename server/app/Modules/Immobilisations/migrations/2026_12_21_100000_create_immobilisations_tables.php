<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('im_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name', 120);
            $t->string('gl_asset', 20);
            $t->string('gl_depreciation', 20);
            $t->string('gl_expense', 20)->default('681');
            $t->string('method', 12)->default('linear'); // linear, degressive, none
            $t->unsignedSmallInteger('years')->default(5);
            $t->decimal('coefficient', 4, 2)->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('im_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('code', 40)->nullable();
            $t->string('name');
            $t->uuid('category_id')->nullable();
            $t->string('status', 10)->default('draft'); // draft, running, disposed
            $t->date('acquisition_date')->nullable();
            $t->date('in_service_date')->nullable();
            $t->decimal('value', 15, 2)->default(0);
            $t->decimal('salvage_value', 15, 2)->default(0);
            $t->string('method', 12)->default('linear');
            $t->unsignedSmallInteger('years')->default(5);
            $t->decimal('coefficient', 4, 2)->nullable();
            $t->string('frequency', 8)->default('annual');
            $t->date('opening_date')->nullable(); // reprise : amortissements antérieurs à cette date déjà pratiqués ailleurs
            $t->string('serial_number', 120)->nullable();
            $t->string('location', 120)->nullable();
            $t->uuid('employee_id')->nullable();
            $t->uuid('partner_id')->nullable();
            $t->uuid('source_document_id')->nullable();
            $t->boolean('post_acquisition')->default(false);
            $t->string('acquisition_account', 20)->nullable();
            $t->json('analytic')->nullable();
            $t->text('notes')->nullable();
            $t->date('disposal_date')->nullable();
            $t->string('disposal_type', 10)->nullable(); // sale, scrap
            $t->decimal('disposal_amount', 15, 2)->nullable();
            $t->uuid('disposal_partner_id')->nullable();
            $t->string('disposal_note')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status']);
        });

        Schema::create('im_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id');
            $t->uuid('asset_id')->index();
            $t->unsignedSmallInteger('seq');
            $t->date('period_start');
            $t->date('period_end');
            $t->decimal('amount', 15, 2);
            $t->decimal('cumulated', 15, 2);
            $t->decimal('nbv', 15, 2);
            $t->string('kind', 10)->default('normal'); // normal, opening (reprise sans écriture)
            $t->timestamp('posted_at')->nullable();
            $t->index(['company_id', 'period_end']);
        });
    }

    public function down(): void
    {
        foreach (['im_lines', 'im_assets', 'im_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
