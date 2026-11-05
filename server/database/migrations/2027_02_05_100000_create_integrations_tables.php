<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Webhooks sortants : Yomara prévient vos autres outils (Zapier, Make, n8n, votre site…) à chaque événement
        Schema::create('wh_endpoints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('url', 500);
            $t->string('description', 190)->nullable();
            $t->json('events'); // ["invoice.paid", "partners.*", "*"]
            $t->text('secret'); // chiffré
            $t->boolean('active')->default(true);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestamp('disabled_at')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->timestamp('last_failure_at')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('wh_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('endpoint_id')->index();
            $t->foreignId('company_id')->index();
            $t->string('event', 60);
            $t->longText('payload');
            $t->string('status', 10)->default('pending'); // pending, success, failed
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->unsignedSmallInteger('response_code')->nullable();
            $t->text('response_body')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'next_attempt_at']);
        });
        // Flux de données (Google Sheets IMPORTDATA, Excel Power Query, Power BI, Looker Studio)
        Schema::create('data_feeds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id')->index(); // les données suivent les droits de ce compte
            $t->string('name', 120);
            $t->string('dataset', 30);
            $t->string('token', 48)->unique();
            $t->unsignedSmallInteger('days')->default(0); // 0 = tout l'historique
            $t->unsignedInteger('hits')->default(0);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_feeds');
        Schema::dropIfExists('wh_deliveries');
        Schema::dropIfExists('wh_endpoints');
    }
};
