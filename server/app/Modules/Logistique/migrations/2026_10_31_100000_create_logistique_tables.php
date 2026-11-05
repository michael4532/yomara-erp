<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lg_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->decimal('fee', 15, 2)->default(0);
            $table->unsignedSmallInteger('delay_days')->default(1);
            $table->string('cities')->nullable(); // communes / quartiers couverts
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lg_vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('plate', 30)->nullable();
            $table->string('type', 20)->default('moto'); // moto, tricycle, voiture, camionnette, camion
            $table->decimal('capacity_kg', 10, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lg_tours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('name')->nullable();
            $table->date('date')->index();
            $table->foreignId('driver_id')->nullable()->index();
            $table->uuid('vehicle_id')->nullable();
            $table->string('status', 15)->default('planned'); // planned, in_progress, done
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lg_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('number', 30)->nullable()->index();
            $table->uuid('source_document_id')->nullable()->index();
            $table->uuid('partner_id')->nullable()->index();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->uuid('zone_id')->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->date('scheduled_date')->nullable()->index();
            $table->string('time_slot', 30)->nullable();
            $table->string('status', 15)->default('planned'); // draft, planned, in_transit, delivered, failed, returned, cancelled
            $table->foreignId('driver_id')->nullable()->index();
            $table->uuid('tour_id')->nullable()->index();
            $table->unsignedSmallInteger('tour_seq')->default(0);
            $table->json('items')->nullable();
            $table->unsignedSmallInteger('packages')->default(1);
            $table->decimal('weight_kg', 10, 2)->default(0);
            $table->decimal('fee', 15, 2)->default(0);
            $table->decimal('cod_amount', 15, 2)->default(0);   // à encaisser à la livraison
            $table->decimal('cod_collected', 15, 2)->default(0); // encaissé par le livreur
            $table->string('cod_method', 20)->nullable();
            $table->timestamp('cod_remitted_at')->nullable();   // remis à la caisse
            $table->uuid('payment_id')->nullable();
            $table->text('notes')->nullable();
            $table->longText('signature')->nullable();           // image PNG (data URI)
            $table->string('signed_by')->nullable();
            $table->string('photo')->nullable();
            $table->decimal('proof_lat', 10, 7)->nullable();
            $table->decimal('proof_lng', 10, 7)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('failed_reason')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('tracking_token', 40)->nullable()->unique();
            $table->boolean('notify')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'status']);
        });

        Schema::create('lg_delivery_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->uuid('delivery_id')->index();
            $table->string('status', 15);
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['lg_delivery_events', 'lg_deliveries', 'lg_tours', 'lg_vehicles', 'lg_zones'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
