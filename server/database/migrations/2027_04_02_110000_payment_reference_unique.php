<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Une référence de paiement chez un prestataire ne règle qu'une seule transaction. */
return new class extends Migration
{
    public function up(): void
    {
        // Les attentes KKiaPay portaient toutes la même valeur provisoire : on la remplace par une valeur propre à chaque transaction
        foreach (DB::table('payment_transactions')->where('reference', 'kkiapay-pending')->pluck('id') as $id) {
            DB::table('payment_transactions')->where('id', $id)->update(['reference' => null]);
        }
        $dupes = DB::table('payment_transactions')->whereNotNull('reference')->select('gateway_id', 'reference')->groupBy('gateway_id', 'reference')->havingRaw('count(*) > 1')->count();
        if ($dupes === 0) {
            Schema::table('payment_transactions', fn (Blueprint $t) => $t->unique(['gateway_id', 'reference'], 'payment_tx_gateway_reference_unique'));
        }
    }

    public function down(): void
    {
        try {
            Schema::table('payment_transactions', fn (Blueprint $t) => $t->dropUnique('payment_tx_gateway_reference_unique'));
        } catch (\Throwable) {
        }
    }
};
