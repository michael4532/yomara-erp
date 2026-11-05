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
            $t->unsignedBigInteger('owner_id')->nullable()->index();
        };
        Schema::create('mc_plans', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->string('status', 12)->default('draft');
            $t->text('objective')->nullable();
            $t->text('targets')->nullable();
            $t->text('message')->nullable();
            $t->json('channels')->nullable();
            $t->decimal('budget', 16, 2)->nullable();
            $t->text('kpis')->nullable();
            $t->timestamps();
        });
        Schema::create('mc_posts', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->unsignedBigInteger('plan_id')->nullable()->index();
            $t->json('networks')->nullable();
            $t->string('format', 20)->default('post');
            $t->dateTime('scheduled_at')->nullable()->index();
            $t->string('status', 12)->default('idea')->index();
            $t->text('caption')->nullable();
            $t->string('hashtags', 500)->nullable();
            $t->string('visual_url', 500)->nullable();
            $t->string('link_url', 500)->nullable();
            $t->unsignedBigInteger('validator_id')->nullable();
            $t->dateTime('validated_at')->nullable();
            $t->string('published_url', 500)->nullable();
            $t->unsignedInteger('reach')->nullable();
            $t->unsignedInteger('engagements')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('reminded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('mc_actions', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->unsignedBigInteger('plan_id')->nullable()->index();
            $t->string('channel', 20)->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('status', 12)->default('planned');
            $t->decimal('budget', 16, 2)->nullable();
            $t->decimal('spent', 16, 2)->nullable();
            $t->unsignedInteger('leads')->nullable();
            $t->decimal('sales', 16, 2)->nullable();
            $t->text('results')->nullable();
            $t->timestamps();
        });
        Schema::create('mc_competitors', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('website', 500)->nullable();
            $t->string('sector', 120)->nullable();
            $t->string('positioning', 12)->nullable();
            $t->string('threat', 10)->default('medium');
            $t->text('socials')->nullable();
            $t->text('offer')->nullable();
            $t->text('strengths')->nullable();
            $t->text('weaknesses')->nullable();
            $t->text('prices')->nullable();
            $t->timestamps();
        });
        Schema::create('mc_observations', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->unsignedBigInteger('competitor_id')->nullable()->index();
            $t->date('observed_on')->nullable()->index();
            $t->string('kind', 15)->default('campaign');
            $t->string('impact', 10)->default('medium');
            $t->text('details')->nullable();
            $t->string('source_url', 500)->nullable();
            $t->text('response')->nullable();
            $t->timestamps();
        });
        Schema::create('mc_press', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('kind', 15)->default('release');
            $t->string('media', 200)->nullable();
            $t->string('contact', 200)->nullable();
            $t->date('date')->nullable()->index();
            $t->string('status', 12)->default('todo');
            $t->text('body')->nullable();
            $t->string('url', 500)->nullable();
            $t->unsignedInteger('audience')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mc_press', 'mc_observations', 'mc_competitors', 'mc_actions', 'mc_posts', 'mc_plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
