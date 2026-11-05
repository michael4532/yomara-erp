<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** La formule « Entreprise » (tous les modules) inclut le site vitrine sur les installations existantes. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        foreach (DB::table('plans')->where('code', 'entreprise')->get() as $p) {
            $m = (array) json_decode((string) $p->modules, true);
            if (! in_array('sites', $m, true)) {
                $m[] = 'sites';
                DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
            }
        }
    }

    public function down(): void {}
};
