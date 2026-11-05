<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nouveaux prix de départ des modules : palier Essentiel (5 000 F/mois) par défaut ; Standard (10 000 F) pour les modules
 * qui coûtent à la plateforme (SMS, WhatsApp, e-mailing, sites et boutique, boîtes mail, stockage, API Google et réseaux) ;
 * Premium (25 000 F) pour l'IA et la visio.
 * Seuls les modules encore au prix d'origine sont changés : un prix modifié par le super admin est conservé.
 */
return new class extends Migration
{
    /** clé => [ancien prix mensuel, nouveau prix mensuel] */
    protected array $prices = [
            'ventes' => [10000, 5000],
            'achats' => [10000, 5000],
            'appro' => [10000, 5000],
            'stock' => [10000, 5000],
            'pos' => [10000, 5000],
            'evenements' => [10000, 5000],
            'sites' => [5000, 10000],
            'billetterie' => [10000, 5000],
            'prestations' => [10000, 5000],
            'hebergement' => [10000, 5000],
            'abonnements' => [10000, 5000],
            'restaurant' => [10000, 5000],
            'immobilier' => [25000, 5000],
            'btp' => [25000, 5000],
            'location' => [10000, 5000],
            'crm' => [10000, 5000],
            'portail_fournisseurs' => [10000, 5000],
            'helpdesk' => [10000, 5000],
            'sms' => [5000, 10000],
            'whatsapp' => [5000, 10000],
            'avis_google' => [5000, 10000],
            'analytique' => [10000, 5000],
            'compta' => [25000, 5000],
            'consolidation' => [25000, 5000],
            'agences' => [25000, 5000],
            'commercial' => [10000, 5000],
            'dev' => [10000, 5000],
            'juridique' => [10000, 5000],
            'fonds' => [10000, 5000],
            'tresorerie' => [10000, 5000],
            'rapports' => [10000, 5000],
            'mailbox' => [5000, 10000],
            'tableaux_excel' => [10000, 5000],
            'immobilisations' => [10000, 5000],
            'pilotage' => [10000, 5000],
            'rh' => [10000, 5000],
            'paie' => [25000, 5000],
            'pointage' => [10000, 5000],
            'talents' => [10000, 5000],
            'recrutement' => [10000, 5000],
            'projets' => [10000, 5000],
            'logistique' => [10000, 5000],
            'production' => [25000, 5000],
            'maintenance' => [10000, 5000],
            'interventions' => [10000, 5000],
            'marches_publics' => [10000, 5000],
            'qualite' => [10000, 5000],
            'documents' => [5000, 10000],
            'signature' => [5000, 10000],
            'ia' => [10000, 25000],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }
        foreach ($this->prices as $key => [$old, $new]) {
            $m = DB::table('modules')->where('key', $key)->first();
            if (! $m || (float) $m->price_monthly !== (float) $old) {
                continue; // prix déjà personnalisé
            }
            $set = ['price_monthly' => $new, 'updated_at' => now()];
            if ((float) $m->price_yearly === (float) ($old * 10)) {
                $set['price_yearly'] = $new * 10;
            }
            if (Schema::hasColumn('modules', 'price_one_time') && (float) $m->price_one_time === (float) ($old * 24)) {
                $set['price_one_time'] = $new * 24;
            }
            DB::table('modules')->where('id', $m->id)->update($set);
        }
    }

    public function down(): void
    {
        foreach ($this->prices as $key => [$old, $new]) {
            DB::table('modules')->where('key', $key)->where('price_monthly', $new)
                ->update(['price_monthly' => $old, 'price_yearly' => $old * 10, 'updated_at' => now()]);
        }
    }
};
