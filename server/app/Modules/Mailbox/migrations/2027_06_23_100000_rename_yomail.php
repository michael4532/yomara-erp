<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// « Boîte mail » devient « YoMail » sur les installations existantes (nom personnalisé conservé).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('key', 'mailbox')->where('name', 'Boîte mail')->update(['name' => 'YoMail']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('key', 'mailbox')->where('name', 'YoMail')->update(['name' => 'Boîte mail']);
        }
    }
};
