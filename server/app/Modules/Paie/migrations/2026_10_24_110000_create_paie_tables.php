<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paie_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('period', 7); // 2026-10
            $table->string('label');
            $table->string('status', 20)->default('draft'); // draft, validated, paid
            $table->date('payment_date')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->json('totals')->nullable();
            $table->foreignId('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'period']);
        });

        Schema::create('paie_payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('run_id')->index();
            $table->foreignId('employee_id')->index();
            $table->string('number', 40)->nullable();
            $table->json('employee'); // instantané de la fiche au moment du calcul
            $table->decimal('days_worked', 5, 2)->default(30);
            $table->json('items')->nullable(); // éléments variables saisis
            $table->json('result'); // résultat complet du calcul
            $table->decimal('gross', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->decimal('cost', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paie_payslips');
        Schema::dropIfExists('paie_runs');
    }
};
