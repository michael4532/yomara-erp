<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mb_accounts', function (Blueprint $t) {
            $t->string('avatar_url', 300)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('mb_accounts', fn (Blueprint $t) => $t->dropColumn('avatar_url'));
    }
};
