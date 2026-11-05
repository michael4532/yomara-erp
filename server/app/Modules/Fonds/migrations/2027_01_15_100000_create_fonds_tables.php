<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('af_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('number', 30)->nullable();
            $t->string('title', 200);
            $t->unsignedBigInteger('requester_id')->index();
            $t->string('department', 120)->nullable();      // service demandeur
            $t->string('to_department', 120)->nullable();   // hiérarchie, direction ou siège sollicité
            $t->unsignedBigInteger('approver_id')->nullable()->index();
            $t->unsignedBigInteger('provider_id')->nullable()->index();
            $t->string('provider_department', 120)->nullable();
            $t->string('priority', 10)->default('normal');
            $t->date('needed_by')->nullable();
            $t->text('purpose')->nullable();
            $t->string('status', 12)->default('draft')->index();
            $t->decimal('total_requested', 16, 2)->default(0);
            $t->decimal('total_approved', 16, 2)->default(0);
            $t->decimal('total_provided', 16, 2)->default(0);
            $t->text('decision_note')->nullable();
            $t->string('project_id', 40)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('provided_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('af_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('request_id')->index();
            $t->string('type', 12)->default('funds'); // funds, equipment, service
            $t->string('description', 255);
            $t->string('product_id', 40)->nullable();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_amount', 16, 2)->default(0);
            $t->decimal('amount', 16, 2)->default(0);
            $t->decimal('approved_quantity', 12, 3)->nullable();
            $t->decimal('approved_amount', 16, 2)->nullable();
            $t->decimal('provided_quantity', 12, 3)->default(0);
            $t->decimal('provided_amount', 16, 2)->default(0);
            $t->string('note', 255)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });
        Schema::create('af_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('request_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type', 20);
            $t->text('body')->nullable();
            $t->json('data')->nullable();
            $t->timestamps();
        });
        // La formule « Entreprise » (tous les modules) inclut les appels de fonds sur les installations existantes
        if (Schema::hasTable('plans')) {
            foreach (DB::table('plans')->where('code', 'entreprise')->get() as $p) {
                $m = (array) json_decode((string) $p->modules, true);
                if (! in_array('fonds', $m, true)) {
                    $m[] = 'fonds';
                    DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('af_events');
        Schema::dropIfExists('af_lines');
        Schema::dropIfExists('af_requests');
    }
};
