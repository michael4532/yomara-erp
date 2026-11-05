<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conversion des filiales en devise : cours de clôture (rate), cours moyen, cours historique des capitaux propres
        Schema::table('compta_group_members', function (Blueprint $t) {
            $t->decimal('average_rate', 18, 6)->nullable()->after('rate');
            $t->decimal('historical_rate', 18, 6)->nullable()->after('average_rate');
        });
        // Retraitements de consolidation d'une période : marge interne sur stocks, dividendes internes, écritures manuelles
        Schema::create('compta_group_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->index();
            $t->date('period_to');
            $t->string('kind', 16);                         // stock_margin, dividend, entry
            $t->foreignId('seller_id')->nullable();         // société qui a vendu (marge) ou versé (dividende)
            $t->foreignId('buyer_id')->nullable();          // société qui détient le stock ou reçoit le dividende
            $t->string('label', 190);
            $t->decimal('amount', 18, 2)->default(0);       // stock détenu / dividende
            $t->decimal('margin_pct', 7, 3)->nullable();
            $t->json('lines')->nullable();                   // écriture manuelle : [[compte, débit, crédit]]
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
            $t->index(['parent_id', 'period_to']);
        });
        // Comptes consolidés figés (arrêtés) d'une période
        Schema::create('compta_group_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->index();
            $t->date('period_from');
            $t->date('period_to');
            $t->longText('data');
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
            $t->unique(['parent_id', 'period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compta_group_snapshots');
        Schema::dropIfExists('compta_group_adjustments');
        Schema::table('compta_group_members', fn (Blueprint $t) => $t->dropColumn(['average_rate', 'historical_rate']));
    }
};
