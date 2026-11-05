<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Les formules Professional et Enterprise incluent la boutique en ligne (comme le site vitrine). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        foreach (DB::table('plans')->whereIn('code', ['professional', 'entreprise', 'sur_mesure'])->get() as $p) {
            $m = (array) json_decode((string) $p->modules, true);
            if (! in_array('ecommerce', $m, true)) {
                $m[] = 'ecommerce';
                DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
            }
        }
    }

    public function down(): void {}
};
