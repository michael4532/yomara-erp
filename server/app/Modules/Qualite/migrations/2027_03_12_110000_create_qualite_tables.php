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
        Schema::create('qa_plans', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('scope', 12)->default('reception');
            $t->string('frequency', 10)->default('each');
            $t->text('criteria')->nullable();
            $t->unsignedTinyInteger('threshold')->default(100);
            $t->boolean('auto_nc')->default(true);
            $t->boolean('active')->default(true);
            $t->date('reminded_on')->nullable();
            $t->timestamps();
        });
        Schema::create('qa_inspections', function (Blueprint $t) use ($base) {
            $base($t);
            $t->unsignedBigInteger('plan_id')->index();
            $t->string('title', 200)->nullable();
            $t->dateTime('done_at')->index();
            $t->string('subject', 200)->nullable();
            $t->string('partner_id', 40)->nullable();
            $t->string('result', 12)->default('pending')->index();
            $t->unsignedTinyInteger('score')->nullable();
            $t->json('answers')->nullable();
            $t->unsignedBigInteger('nc_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('qa_nonconformities', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('number', 30)->nullable()->index();
            $t->string('title', 200);
            $t->string('source', 12)->default('internal');
            $t->string('severity', 10)->default('minor');
            $t->string('status', 12)->default('open')->index();
            $t->date('detected_on');
            $t->unsignedBigInteger('reporter_id')->nullable();
            $t->string('partner_id', 40)->nullable()->index();
            $t->string('product', 200)->nullable();
            $t->unsignedBigInteger('inspection_id')->nullable();
            $t->text('description')->nullable();
            $t->text('containment')->nullable();
            $t->text('root_cause')->nullable();
            $t->string('cause_category', 12)->nullable();
            $t->decimal('cost', 14, 2)->nullable();
            $t->date('due_date')->nullable();
            $t->date('closed_on')->nullable();
            $t->boolean('effective')->nullable();
            $t->timestamps();
        });
        Schema::create('qa_actions', function (Blueprint $t) use ($base) {
            $base($t);
            $t->unsignedBigInteger('nc_id')->nullable()->index();
            $t->string('title', 200);
            $t->string('kind', 12)->default('corrective');
            $t->string('status', 12)->default('todo')->index();
            $t->date('due_date')->nullable()->index();
            $t->date('done_on')->nullable();
            $t->text('result')->nullable();
            $t->date('reminded_on')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['qa_actions', 'qa_nonconformities', 'qa_inspections', 'qa_plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
