<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $t) {
            $t->unsignedTinyInteger('score')->default(0)->after('priority');
            $t->string('utm_source', 100)->nullable()->after('source');
            $t->string('utm_medium', 100)->nullable()->after('utm_source');
            $t->string('utm_campaign', 150)->nullable()->after('utm_medium');
            $t->string('utm_content', 150)->nullable()->after('utm_campaign');
            $t->string('ad_platform', 20)->nullable()->after('utm_content');
            $t->unsignedBigInteger('ad_campaign_id')->nullable()->index()->after('ad_platform');
            $t->string('ad_ref', 120)->nullable()->after('ad_campaign_id');
        });
        Schema::create('crm_sequences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('name', 150);
            $t->text('description')->nullable();
            $t->json('steps');
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('crm_sequence_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('sequence_id')->index();
            $t->uuid('lead_id')->index();
            $t->unsignedSmallInteger('step')->default(0);
            $t->dateTime('next_at')->nullable()->index();
            $t->string('status', 10)->default('active')->index();
            $t->json('history')->nullable();
            $t->unsignedBigInteger('started_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sequence_runs');
        Schema::dropIfExists('crm_sequences');
        Schema::table('crm_leads', fn (Blueprint $t) => $t->dropColumn(['score', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'ad_platform', 'ad_campaign_id', 'ad_ref']));
    }
};
