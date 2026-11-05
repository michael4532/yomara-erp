<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rappels d'expiration des modules achetés : dernier rappel envoyé (jours avant l'échéance)
        Schema::table('company_modules', fn (Blueprint $t) => $t->smallInteger('expiry_notice')->nullable());

        // Tarif unique par défaut : 10 000 F / mois pour tous les modules payants (annuel = 10 mois, licence = 24 mois)
        DB::table('modules')->where('is_core', false)->where('price_monthly', '>', 0)
            ->update(['price_monthly' => 10000, 'price_yearly' => 100000, 'price_one_time' => 240000]);

        // Moyens de paiement de la plateforme (boutique des modules) s'il n'y en a aucun
        if (! DB::table('payment_gateways')->whereNull('company_id')->exists()) {
            $now = now();
            $rows = [
                ['driver' => 'cinetpay', 'label' => 'Mobile Money & carte bancaire (CinetPay)', 'mode' => 'live', 'enabled' => false, 'sort' => 0, 'instructions' => null],
                ['driver' => 'wave', 'label' => 'Wave', 'mode' => 'live', 'enabled' => false, 'sort' => 1, 'instructions' => null],
                ['driver' => 'orange_money', 'label' => 'Orange Money', 'mode' => 'live', 'enabled' => false, 'sort' => 2, 'instructions' => null],
                ['driver' => 'mobile_money', 'label' => 'Dépôt Mobile Money', 'mode' => 'live', 'enabled' => true, 'sort' => 3,
                    'instructions' => "Envoyez le montant par Orange Money, MTN, Moov ou Wave au numéro indiqué par notre service commercial,\npuis saisissez l'ID de la transaction : le module est activé dès vérification."],
                ['driver' => 'bank_transfer', 'label' => 'Virement bancaire', 'mode' => 'live', 'enabled' => true, 'sort' => 4,
                    'instructions' => "Virement au compte communiqué sur votre bon de commande, avec le numéro de commande en libellé.\nActivation dès réception."],
                ['driver' => 'cash', 'label' => 'Espèces (à nos bureaux)', 'mode' => 'live', 'enabled' => false, 'sort' => 5,
                    'instructions' => 'Réglez à nos bureaux en indiquant le numéro de commande.'],
            ];
            foreach ($rows as $r) {
                DB::table('payment_gateways')->insert($r + ['company_id' => null, 'credentials' => null, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('company_modules', fn (Blueprint $t) => $t->dropColumn('expiry_notice'));
    }
};
