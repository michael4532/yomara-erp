<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->string('kind', 10)->default('open'); // open, won, lost
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('title');
            $table->uuid('partner_id')->nullable()->index();
            $table->string('contact_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('source', 30)->nullable();
            $table->uuid('stage_id')->nullable()->index();
            $table->string('status', 10)->default('open'); // open, won, lost
            $table->decimal('expected_revenue', 15, 2)->default(0);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->foreignId('owner_id')->nullable()->index();
            $table->unsignedTinyInteger('priority')->default(0); // 0 à 3 étoiles
            $table->string('tags')->nullable();
            $table->text('notes')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->uuid('quote_id')->nullable()->index();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'status']);
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('lead_id')->nullable()->index();
            $table->uuid('partner_id')->nullable()->index();
            $table->string('type', 20)->default('call'); // call, meeting, email, whatsapp, task, visit
            $table->string('subject');
            $table->dateTime('due_at')->nullable();
            $table->boolean('done')->default(false);
            $table->dateTime('done_at')->nullable();
            $table->foreignId('owner_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'done', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_stages');
    }
};
