<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plan de classement : séries et sous-séries, durée de conservation (DUA) et sort final
        Schema::create('ar_series', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('parent_id')->nullable()->index();
            $t->string('code', 30);
            $t->string('name', 190);
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('retention_years')->default(10); // 0 = conservation définitive
            $t->string('final_action', 20)->default('destroy');       // destroy | keep | review (tri)
            $t->string('confidentiality', 20)->default('internal');
            $t->json('sources')->nullable();                          // types archivés automatiquement (sale.invoice…)
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('ar_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('series_id')->nullable()->index();
            $t->string('cote', 60);
            $t->string('title', 255);
            $t->text('description')->nullable();
            $t->date('doc_date')->nullable();
            $t->string('source_type', 40)->nullable();
            $t->uuid('source_id')->nullable()->index();
            $t->longText('content')->nullable();                      // instantané JSON d'un document de l'ERP
            $t->string('file_name', 255)->nullable();
            $t->string('file_path', 255)->nullable();
            $t->unsignedBigInteger('backend_id')->nullable();
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->string('sha256', 64)->nullable();
            $t->timestamp('sealed_at')->nullable();
            $t->date('retention_until')->nullable();
            $t->string('final_action', 20)->default('destroy');
            $t->string('status', 24)->default('active');               // active | on_loan | pending_disposal | disposed
            $t->string('confidentiality', 20)->default('internal');     // public | internal | confidential | secret
            $t->boolean('legal_hold')->default(false);                 // gel pour litige : jamais éliminé
            $t->string('location', 190)->nullable();                   // salle, rayon, boîte
            $t->string('keywords', 500)->nullable();
            $t->string('transfer_ref', 60)->nullable();                // bordereau de versement
            $t->string('department', 120)->nullable();                 // service versant
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'cote']);
            $t->index(['company_id', 'status', 'retention_until']);
        });

        // Journal : consultations, téléchargements, modifications, contrôles d'intégrité, prêts, éliminations
        Schema::create('ar_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('record_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action', 30);
            $t->string('details', 500)->nullable();
            $t->string('ip', 64)->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('ar_loans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('record_id')->index();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->string('borrower', 190);
            $t->string('purpose', 500)->nullable();
            $t->string('kind', 20)->default('consultation');            // consultation (sur place / en ligne) | pret (sortie physique)
            $t->string('status', 20)->default('pending');               // pending | approved | rejected | returned
            $t->date('due_date')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('returned_at')->nullable();
            $t->timestamps();
        });

        Schema::create('ar_disposals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('number', 40);
            $t->string('status', 20)->default('proposed');              // proposed | approved | executed | cancelled
            $t->json('records');                                        // [{id, cote, title, doc_date, size}]
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('proposed_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('executed_by')->nullable();
            $t->timestamp('executed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ar_disposals', 'ar_loans', 'ar_events', 'ar_records', 'ar_series'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
