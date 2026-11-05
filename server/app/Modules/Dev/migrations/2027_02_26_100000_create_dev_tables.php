<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $base = function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
        };
        Schema::create('dev_apps', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 150);
            $t->string('stack', 200)->nullable();
            $t->string('status', 12)->default('active');
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('repo_url', 500)->nullable();
            $t->string('prod_url', 500)->nullable();
            $t->string('staging_url', 500)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('dev_sprints', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 150);
            $t->unsignedBigInteger('app_id')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('status', 10)->default('planned');
            $t->text('goal')->nullable();
            $t->text('retro')->nullable();
            $t->timestamps();
        });
        Schema::create('dev_releases', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 60);
            $t->unsignedBigInteger('app_id')->nullable();
            $t->string('environment', 12)->default('production');
            $t->string('status', 12)->default('planned');
            $t->date('date')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->longText('notes')->nullable();
            $t->string('url', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('dev_items', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('number', 20)->nullable();
            $t->string('kind', 12)->default('story');
            $t->string('status', 12)->default('backlog')->index();
            $t->string('priority', 10)->default('normal');
            $t->unsignedBigInteger('app_id')->nullable()->index();
            $t->unsignedBigInteger('sprint_id')->nullable()->index();
            $t->unsignedBigInteger('release_id')->nullable()->index();
            $t->unsignedBigInteger('assignee_id')->nullable()->index();
            $t->unsignedBigInteger('reporter_id')->nullable();
            $t->decimal('points', 5, 1)->nullable();
            $t->date('due_date')->nullable();
            $t->string('branch', 150)->nullable();
            $t->string('pr_url', 500)->nullable();
            $t->text('description')->nullable();
            $t->text('acceptance')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('done_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['dev_items', 'dev_releases', 'dev_sprints', 'dev_apps'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
