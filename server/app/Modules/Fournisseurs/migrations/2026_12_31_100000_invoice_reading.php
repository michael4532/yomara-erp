<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sp_invoices', function (Blueprint $t) {
            $t->string('source', 10)->default('portal'); // portal, upload
            $t->json('extracted')->nullable();
            $t->string('created_by', 120)->nullable();
        });
        Schema::table('sp_tenders', fn (Blueprint $t) => $t->uuid('project_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('sp_tenders', fn (Blueprint $t) => $t->dropColumn('project_id'));
        Schema::table('sp_invoices', fn (Blueprint $t) => $t->dropColumn(['source', 'extracted', 'created_by']));
    }
};
