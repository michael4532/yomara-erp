<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rdv_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('category', 80)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedSmallInteger('buffer_minutes')->default(0); // battement après le rendez-vous
            $table->decimal('price', 15, 2)->default(0);
            $table->uuid('product_id')->nullable(); // article facturé
            $table->string('location', 20)->default('onsite'); // onsite, home, online
            $table->json('staff_ids')->nullable(); // vide = toute l'équipe
            $table->boolean('online')->default(true); // réservable en ligne
            $table->boolean('requires_approval')->default(false);
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rdv_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('name');
            $table->string('title', 120)->nullable(); // ex. Coiffeuse, Dr …
            $table->json('schedule')->nullable(); // {"1": [["08:00","12:00"],["14:00","18:00"]], …}
            $table->string('color', 9)->nullable();
            $table->boolean('online')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rdv_closures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('staff_id')->nullable(); // vide = fermeture de l'établissement
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rdv_appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('number', 40)->nullable();
            $table->uuid('service_id')->nullable()->index();
            $table->uuid('staff_id')->nullable()->index();
            $table->uuid('partner_id')->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('confirmed'); // pending, confirmed, done, no_show, cancelled
            $table->string('source', 20)->default('manual'); // manual, online, portal
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->string('cancel_reason')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->string('token', 64)->nullable()->unique();
            $table->boolean('notify')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rdv_appointments');
        Schema::dropIfExists('rdv_closures');
        Schema::dropIfExists('rdv_staff');
        Schema::dropIfExists('rdv_services');
    }
};
