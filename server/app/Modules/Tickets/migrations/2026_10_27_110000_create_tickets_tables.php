<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tk_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->foreignId('parent_id')->nullable()->index(); // hiérarchie : DG > DGA > services
            $table->foreignId('head_user_id')->nullable();       // chef de service
            $table->json('supervisor_ids')->nullable();           // autres responsables qui suivent ce service (RH, DGA…)
            $table->boolean('accepts_tickets')->default(true);
            $table->json('categories')->nullable();               // types de demandes
            $table->json('sla_hours')->nullable();                // délai cible par urgence
            $table->string('color', 9)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('tk_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('department_id')->index();
            $table->foreignId('user_id')->index();
            $table->string('role', 20)->default('member'); // head, deputy, member, intern
            $table->timestamps();
            $table->unique(['department_id', 'user_id']);
        });

        Schema::create('tk_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('number', 30);
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('urgency', 10)->default('normal'); // low, normal, high, critical
            $table->string('status', 20)->default('submitted'); // submitted, open, accepted, in_progress, on_hold, done, closed, cancelled, rejected
            $table->foreignId('requester_id')->index();
            $table->foreignId('requester_department_id')->nullable()->index();
            $table->foreignId('department_id')->index();
            $table->foreignId('assignee_id')->nullable()->index();
            $table->string('hold_reason')->nullable();
            $table->boolean('purchase_requested')->default(false);
            $table->text('resolution')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('hold_since')->nullable();
            $table->unsignedInteger('hold_seconds')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('tk_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->index();
            $table->foreignId('user_id')->nullable();
            $table->string('type', 20); // created, status, assign, comment, note, hold, rating
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('tk_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->index();
            $table->foreignId('user_id')->nullable();
            $table->string('name');
            $table->string('path');
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::create('tk_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('department_id')->nullable(); // null = toute l'entreprise
            $table->string('period', 10); // week, month
            $table->date('from');
            $table->date('to');
            $table->json('data');
            $table->text('summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['tk_reports', 'tk_files', 'tk_events', 'tk_tickets', 'tk_members', 'tk_departments'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
