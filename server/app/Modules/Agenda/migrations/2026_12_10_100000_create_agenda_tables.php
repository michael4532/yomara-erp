<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ag_resources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->string('kind', 12)->default('room'); // room, vehicle, equipment
            $t->string('color', 9)->nullable();
            $t->unsignedSmallInteger('capacity')->nullable();
            $t->string('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('ag_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('location')->nullable();
            $t->string('online_link')->nullable();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->boolean('all_day')->default(false);
            $t->string('category', 12)->default('meeting'); // meeting, appointment, travel, training, personal, other
            $t->string('color', 9)->nullable();
            $t->foreignId('owner_id')->nullable()->index();
            $t->string('visibility', 8)->default('public'); // public, private
            $t->json('attendee_ids')->nullable();
            $t->json('responses')->nullable(); // {user_id: accepted|tentative|declined}
            $t->json('guests')->nullable(); // [{name, email, phone}]
            $t->uuid('resource_id')->nullable()->index();
            $t->json('recurrence')->nullable(); // {freq, interval, byday, until, count}
            $t->json('exceptions')->nullable(); // dates d'occurrences supprimées
            $t->unsignedSmallInteger('reminder_minutes')->nullable();
            $t->string('status', 10)->default('confirmed'); // confirmed, cancelled
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'starts_at']);
        });

        Schema::create('ag_reminders', function (Blueprint $t) {
            $t->id();
            $t->uuid('event_id');
            $t->dateTime('occurrence_at');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['event_id', 'occurrence_at']);
        });

        Schema::create('ag_feeds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id')->unique();
            $t->char('token_hash', 64)->unique();
            $t->text('token');
            $t->timestamp('last_access_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ag_feeds');
        Schema::dropIfExists('ag_reminders');
        Schema::dropIfExists('ag_events');
        Schema::dropIfExists('ag_resources');
    }
};
