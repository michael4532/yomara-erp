<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ev_ticket_types', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('event_id')->index();
            $t->string('name');
            $t->string('kind', 10)->default('visitor'); // visitor, vip, exhibitor, staff, press
            $t->decimal('price', 14, 2)->default(0); // TTC (prix public)
            $t->unsignedInteger('quantity')->nullable();
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->boolean('reentry')->default(false);
            $t->date('sale_start')->nullable();
            $t->date('sale_end')->nullable();
            $t->boolean('online')->default(true);
            $t->unsignedTinyInteger('max_per_order')->default(10);
            $t->text('description')->nullable();
            $t->string('color', 9)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('ev_ticket_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 40)->nullable();
            $t->uuid('event_id')->index();
            $t->uuid('partner_id')->nullable();
            $t->string('buyer_name', 160);
            $t->string('buyer_phone', 40)->nullable();
            $t->string('buyer_email')->nullable();
            $t->json('items');
            $t->decimal('total', 14, 2)->default(0);
            $t->string('status', 10)->default('pending'); // pending, paid, cancelled
            $t->string('channel', 10)->default('box_office'); // online, box_office
            $t->string('payment_method', 20)->nullable();
            $t->uuid('invoice_id')->nullable();
            $t->string('token', 64)->nullable()->unique();
            $t->timestamp('paid_at')->nullable();
            $t->string('last_error')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('ev_tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('event_id')->index();
            $t->uuid('type_id')->nullable();
            $t->string('kind', 10)->default('visitor');
            $t->string('code', 20);
            $t->string('token', 64)->unique();
            $t->string('holder_name', 160)->nullable();
            $t->string('company_name', 160)->nullable();
            $t->string('holder_phone', 40)->nullable();
            $t->string('holder_email')->nullable();
            $t->uuid('booking_id')->nullable()->index();
            $t->uuid('order_id')->nullable()->index();
            $t->decimal('price', 14, 2)->default(0);
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->boolean('reentry')->default(false);
            $t->string('status', 10)->default('valid'); // valid, cancelled
            $t->timestamp('checked_in_at')->nullable();
            $t->timestamp('last_checkin_at')->nullable();
            $t->unsignedInteger('checkins_count')->default(0);
            $t->string('notes')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'code']);
        });

        Schema::create('ev_checkins', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('event_id')->index();
            $t->uuid('ticket_id')->nullable()->index();
            $t->string('code', 80);
            $t->timestamp('scanned_at');
            $t->string('gate', 60)->nullable();
            $t->string('result', 12); // ok, duplicate, refused
            $t->string('reason')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::table('ev_events', function (Blueprint $t) {
            $t->boolean('online_deposit')->default(false);
            $t->boolean('ticketing_enabled')->default(false);
            $t->unsignedInteger('capacity')->nullable();
        });
        Schema::table('ev_bookings', function (Blueprint $t) {
            $t->string('public_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('ev_bookings', fn (Blueprint $t) => $t->dropColumn('public_token'));
        Schema::table('ev_events', fn (Blueprint $t) => $t->dropColumn(['online_deposit', 'ticketing_enabled', 'capacity']));
        Schema::dropIfExists('ev_checkins');
        Schema::dropIfExists('ev_tickets');
        Schema::dropIfExists('ev_ticket_orders');
        Schema::dropIfExists('ev_ticket_types');
    }
};
