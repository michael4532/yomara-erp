<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stockages des documents : le serveur lui-même, puis des stockages externes compatibles S3 (R2, B2, Wasabi…).
        // Les nouveaux fichiers vont dans le premier stockage actif (par priorité) qui a encore de la place.
        Schema::create('storage_backends', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('provider', 30)->default('s3'); // local, r2, b2, wasabi, s3, idrive, scaleway, ovh, contabo, minio, other
            $t->string('endpoint')->nullable();
            $t->string('region', 60)->nullable();
            $t->string('bucket')->nullable();
            $t->text('access_key')->nullable();
            $t->text('secret_key')->nullable();
            $t->unsignedBigInteger('capacity_mb')->nullable(); // vide : sans limite
            $t->unsignedBigInteger('used_bytes')->default(0);
            $t->unsignedInteger('priority')->default(10);
            $t->boolean('is_active')->default(true);
            $t->string('last_error', 500)->nullable();
            $t->timestamp('last_error_at')->nullable();
            $t->timestamp('last_ok_at')->nullable();
            $t->timestamps();
        });
        if (Schema::hasTable('dc_versions') && ! Schema::hasColumn('dc_versions', 'backend_id')) {
            Schema::table('dc_versions', function (Blueprint $t) {
                $t->unsignedBigInteger('backend_id')->nullable()->index();
            });
        }
        $used = Schema::hasTable('dc_versions') ? (int) DB::table('dc_versions')->sum('size') : 0;
        $id = DB::table('storage_backends')->insertGetId(['name' => 'Serveur (hébergement)', 'provider' => 'local', 'priority' => 1, 'is_active' => true, 'used_bytes' => $used, 'created_at' => now(), 'updated_at' => now()]);
        if (Schema::hasTable('dc_versions')) {
            DB::table('dc_versions')->whereNull('backend_id')->update(['backend_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_backends');
        if (Schema::hasColumn('dc_versions', 'backend_id')) {
            Schema::table('dc_versions', fn (Blueprint $t) => $t->dropColumn('backend_id'));
        }
    }
};
