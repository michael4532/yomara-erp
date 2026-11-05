<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Variations du pourcentage d'intérêts : achats complémentaires (+) et cessions partielles (−) de titres
        Schema::create('compta_group_stakes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_member_id')->index();
            $t->date('date');
            $t->decimal('change_pct', 7, 3);                 // points de pourcentage acquis (+) ou cédés (−)
            $t->decimal('amount', 18, 2)->default(0);         // prix payé (achat) ou prix de cession (vente)
            $t->decimal('cost_basis', 18, 2)->nullable();     // vente : coût des titres cédés sorti des comptes de la mère
            $t->string('note', 190)->nullable();
            $t->timestamps();
        });
        // Sortie du périmètre : date et prix de cession de la totalité des titres restants
        Schema::table('compta_group_members', function (Blueprint $t) {
            $t->date('exit_date')->nullable()->after('goodwill_impairment_date');
            $t->decimal('exit_proceeds', 18, 2)->nullable()->after('exit_date');
        });
    }

    public function down(): void
    {
        Schema::table('compta_group_members', fn (Blueprint $t) => $t->dropColumn(['exit_date', 'exit_proceeds']));
        Schema::dropIfExists('compta_group_stakes');
    }
};
