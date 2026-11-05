<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qs_forms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('kind', 12)->default('satisfaction');
            $t->string('status', 10)->default('draft')->index();
            $t->string('token', 40)->unique();
            $t->json('questions')->nullable();
            $t->string('thank_you', 500)->nullable();
            $t->boolean('ask_contact')->default(true);
            $t->boolean('alert_unhappy')->default(true);
            $t->date('closes_on')->nullable();
            $t->unsignedInteger('max_responses')->nullable();
            $t->unsignedInteger('responses_count')->default(0);
            $t->timestamps();
        });
        Schema::create('qs_responses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->unsignedBigInteger('form_id')->index();
            $t->json('answers');
            $t->string('name', 150)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('partner_id', 40)->nullable();
            $t->decimal('score', 5, 2)->nullable();
            $t->string('mood', 10)->nullable()->index();
            $t->string('source', 10)->default('link');
            $t->string('ip_hash', 64)->nullable();
            $t->dateTime('submitted_at')->index();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qs_responses');
        Schema::dropIfExists('qs_forms');
    }
};
