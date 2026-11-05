<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dc_folders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('parent_id')->nullable()->index();
            $t->string('name');
            $t->string('color', 9)->nullable();
            $t->string('access', 8)->default('inherit'); // inherit, all, roles, private
            $t->json('role_ids')->nullable();
            $t->foreignId('owner_id')->nullable();
            $t->string('system_key', 30)->nullable(); // dossiers créés par l'ERP (documents signés…)
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('dc_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('folder_id')->nullable()->index();
            $t->string('name');
            $t->text('description')->nullable();
            $t->json('tags')->nullable();
            $t->string('mime', 120)->nullable();
            $t->string('extension', 12)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->char('sha256', 64)->nullable();
            $t->unsignedSmallInteger('current_version')->default(1);
            $t->string('path')->nullable();
            $t->uuid('partner_id')->nullable()->index();
            $t->uuid('employee_id')->nullable()->index();
            $t->string('source_type', 30)->nullable();
            $t->string('source_id', 64)->nullable()->index();
            $t->date('expires_at')->nullable();
            $t->unsignedSmallInteger('expiry_notice')->nullable(); // dernier rappel envoyé (jours avant échéance)
            $t->foreignId('owner_id')->nullable();
            $t->boolean('locked')->default(false);
            $t->longText('content_text')->nullable();
            $t->longText('search_text')->nullable();
            $t->foreignId('deleted_by')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('dc_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('file_id')->index();
            $t->unsignedSmallInteger('number');
            $t->string('path');
            $t->string('name');
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->char('sha256', 64)->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('comment', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('dc_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('file_id')->nullable()->index();
            $t->uuid('folder_id')->nullable()->index();
            $t->char('token_hash', 64)->unique();
            $t->text('token');
            $t->timestamp('expires_at')->nullable();
            $t->string('password_hash')->nullable();
            $t->unsignedInteger('max_downloads')->nullable();
            $t->unsignedInteger('downloads')->default(0);
            $t->timestamp('last_access_at')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dc_shares');
        Schema::dropIfExists('dc_versions');
        Schema::dropIfExists('dc_files');
        Schema::dropIfExists('dc_folders');
    }
};
