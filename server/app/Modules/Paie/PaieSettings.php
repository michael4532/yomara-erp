<?php

namespace App\Modules\Paie;

use App\Models\Company;

/**
 * Paramètres de paie Côte d'Ivoire. Valeurs par défaut au 1er janvier 2024
 * (réforme ITS, annexe fiscale 2024) — toutes modifiables par société
 * (Paie → Paramètres), sans mise à jour du logiciel, quand les taux changent.
 */
class PaieSettings
{
    public const DEFAULTS = [
        'smig' => 75000,
        'days_basis' => 30, // prorata : salaire × jours payés / 30

        // CNPS — régime de retraite
        'retraite_salarie' => 6.3,
        'retraite_employeur' => 7.7,
        'plafond_retraite' => 3375000, // 45 × SMIG
        // CNPS — autres branches (à la charge de l'employeur)
        'prestations_familiales' => 5.0,
        'maternite' => 0.75,
        'accident_travail' => 2.0, // 2 à 5 % selon le risque de l'activité
        'plafond_autres' => 70000, // plafond PF / maternité / AT (inchangé depuis la hausse du SMIG de 2023)

        // CMU — par personne et par mois
        'cmu_salarie' => 500,
        'cmu_employeur' => 500,
        'cmu_max_enfants' => 6,

        // ITS mensuel (base : 100 % du brut imposable, sans abattement)
        'its_bareme' => [
            ['up_to' => 75000, 'rate' => 0],
            ['up_to' => 240000, 'rate' => 16],
            ['up_to' => 800000, 'rate' => 21],
            ['up_to' => 2400000, 'rate' => 24],
            ['up_to' => 8000000, 'rate' => 28],
            ['up_to' => null, 'rate' => 32],
        ],
        // Réduction pour charges de famille : par demi-part au-delà de la 1re part
        'ricf_par_demi_part' => 5500,
        'parts_max' => 5,

        // Impôts à la charge de l'employeur (base : brut imposable)
        'contribution_nationaux' => 1.2,
        'contribution_expatries' => 10.4,
        'fdfp_apprentissage' => 0.4,
        'fdfp_formation' => 1.2,

        // Éléments exonérés
        'transport_exonere' => 30000, // indemnité de transport non imposable jusqu'à ce montant
        'anciennete_auto' => true, // prime d'ancienneté : 2 % à 2 ans, +1 %/an, max 25 % du salaire de base
        'anciennete_max' => 25,

        // Comptes SYSCOHADA
        'comptes' => [
            'salaires' => '661', 'charges_sociales' => '664', 'impots_employeur' => '641',
            'net_a_payer' => '422', 'avances' => '421', 'cnps' => '431', 'its' => '447', 'impots_dus' => '442', 'assurance' => '438',
        ],
    ];

    public static function for(Company $company): array
    {
        $out = array_replace(self::DEFAULTS, (array) data_get($company->settings, 'paie', []));
        // Comptes par défaut traduits dans le plan de la société (PCG, CGNC…)
        $out['comptes'] = array_replace(array_map(fn ($c) => \App\Modules\Compta\Referentials::code($company, $c), self::DEFAULTS['comptes']), (array) data_get($company->settings, 'paie.comptes', []));

        return $out;
    }
}
