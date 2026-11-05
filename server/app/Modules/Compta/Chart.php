<?php

namespace App\Modules\Compta;

use App\Models\Company;
use App\Modules\Compta\Models\Account;
use App\Modules\Compta\Models\Journal;

/**
 * Plan comptable SYSCOHADA révisé (comptes usuels d'une PME) et journaux.
 * Créés à la première utilisation du module ; la société peut ajouter ses
 * propres sous-comptes (ex. 5211 Banque Atlantique, 4111 Clients grands comptes).
 */
class Chart
{
    public const ACCOUNTS = [
        // Classe 1 — Ressources durables
        '101' => 'Capital social', '1013' => 'Capital souscrit, appelé, versé, non amorti', '104' => 'Compte de l\'exploitant',
        '111' => 'Réserve légale', '118' => 'Autres réserves', '121' => 'Report à nouveau créditeur', '129' => 'Report à nouveau débiteur',
        '131' => 'Résultat net : bénéfice', '139' => 'Résultat net : perte', '14' => 'Subventions d\'investissement',
        '162' => 'Emprunts auprès des établissements de crédit', '17' => 'Dettes de location-acquisition',
        '19' => 'Provisions pour risques et charges',
        // Classe 2 — Actif immobilisé
        '212' => 'Brevets, licences, concessions', '213' => 'Logiciels et sites internet', '22' => 'Terrains',
        '231' => 'Bâtiments', '234' => 'Aménagements, agencements et installations', '241' => 'Matériel et outillage',
        '2441' => 'Matériel de bureau', '2442' => 'Matériel informatique', '2444' => 'Mobilier de bureau', '245' => 'Matériel de transport',
        '261' => 'Titres de participation', '275' => 'Dépôts et cautionnements versés', '2813' => 'Amortissements des logiciels', '2831' => 'Amortissements des bâtiments', '2834' => 'Amortissements des agencements',
        '2841' => 'Amortissements du matériel et outillage',
        '2844' => 'Amortissements du matériel de bureau et informatique', '2845' => 'Amortissements du matériel de transport',
        // Classe 3 — Stocks
        '31' => 'Marchandises', '32' => 'Matières premières et fournitures liées', '33' => 'Autres approvisionnements',
        '36' => 'Produits finis', '39' => 'Dépréciations des stocks',
        // Classe 4 — Tiers
        '401' => 'Fournisseurs, dettes en compte', '408' => 'Fournisseurs, factures non parvenues', '409' => 'Fournisseurs débiteurs (avances)',
        '411' => 'Clients', '416' => 'Créances clients litigieuses ou douteuses', '418' => 'Clients, produits à recevoir',
        '419' => 'Clients créditeurs (avances reçues)', '421' => 'Personnel, avances et acomptes', '422' => 'Personnel, rémunérations dues', '4286' => 'Personnel, notes de frais à rembourser',
        '431' => 'Sécurité sociale (CNPS)', '441' => 'État, impôt sur les bénéfices', '442' => 'État, autres impôts et taxes',
        '4431' => 'État, TVA facturée sur ventes', '4432' => 'État, TVA facturée sur prestations de services',
        '4441' => 'État, TVA due', '4449' => 'État, crédit de TVA à reporter', '4451' => 'État, TVA récupérable sur immobilisations',
        '4452' => 'État, TVA récupérable sur achats', '4454' => 'État, TVA récupérable sur services extérieurs',
        '447' => 'État, impôts retenus à la source', '461' => 'Associés, apports', '465' => 'Associés, dividendes à payer', '471' => 'Débiteurs et créditeurs divers',
        '476' => 'Charges constatées d\'avance', '481' => 'Fournisseurs d\'investissements', '485' => 'Créances sur cessions d\'immobilisations', '477' => 'Produits constatés d\'avance', '491' => 'Dépréciations des comptes clients',
        // Classe 5 — Trésorerie
        '513' => 'Chèques à encaisser', '521' => 'Banques locales', '5215' => 'Mobile Money (Orange, MTN, Moov, Wave)',
        '561' => 'Crédits de trésorerie', '571' => 'Caisse', '585' => 'Virements de fonds',
        // Classe 6 — Charges
        '601' => 'Achats de marchandises', '602' => 'Achats de matières premières', '6031' => 'Variations des stocks de marchandises',
        '604' => 'Achats stockés de matières et fournitures consommables', '605' => 'Autres achats', '6051' => 'Eau',
        '6052' => 'Électricité', '6053' => 'Carburants et autres énergies', '6055' => 'Fournitures de bureau', '6056' => 'Petit matériel et outillage',
        '608' => 'Achats d\'emballages', '612' => 'Transports sur ventes', '614' => 'Transports du personnel', '611' => 'Transports sur achats', '618' => 'Autres frais de transport', '6181' => 'Voyages et déplacements',
        '622' => 'Locations et charges locatives', '624' => 'Entretien, réparations et maintenance', '625' => 'Primes d\'assurance',
        '626' => 'Études, recherches et documentation', '627' => 'Publicité, publications, relations publiques', '628' => 'Frais de télécommunications', '631' => 'Frais bancaires',
        '633' => 'Frais de formation du personnel', '637' => 'Rémunérations de personnel extérieur (sous-traitance)', '635' => 'Cotisations', '632' => 'Honoraires et rémunérations d\'intermédiaires', '638' => 'Autres charges externes', '6383' => 'Réceptions', '6384' => 'Missions', '641' => 'Impôts et taxes directs',
        '645' => 'Impôts et taxes indirects', '651' => 'Pertes sur créances clients', '661' => 'Rémunérations directes du personnel',
        '664' => 'Charges sociales', '671' => 'Intérêts des emprunts', '673' => 'Escomptes accordés (points fidélité)', '676' => 'Pertes de change',
        '681' => 'Dotations aux amortissements d\'exploitation', '691' => 'Dotations aux provisions d\'exploitation',
        // Classe 7 — Produits
        '701' => 'Ventes de marchandises', '702' => 'Ventes de produits finis', '705' => 'Travaux facturés', '706' => 'Services vendus',
        '707' => 'Produits accessoires', '71' => 'Subventions d\'exploitation', '758' => 'Produits divers',
        '771' => 'Intérêts de prêts', '772' => 'Revenus de participations', '776' => 'Gains de change', '781' => 'Transferts de charges', '791' => 'Reprises de provisions',
        // Classe 8 — Hors activités ordinaires
        '81' => 'Valeurs comptables des cessions d\'immobilisations', '82' => 'Produits des cessions d\'immobilisations',
        '83' => 'Charges hors activités ordinaires', '84' => 'Produits hors activités ordinaires', '891' => 'Impôts sur les bénéfices de l\'exercice',
    ];

    public const JOURNALS = [
        'VE' => ['Ventes', 'sale'],
        'AC' => ['Achats', 'purchase'],
        'CA' => ['Caisse', 'cash'],
        'BQ' => ['Banque', 'bank'],
        'MM' => ['Mobile Money', 'mobile_money'],
        'OD' => ['Opérations diverses', 'misc'],
        'SA' => ['Salaires', 'payroll'],
        'AN' => ['À-nouveaux', 'misc'],
        'CL' => ['Clôture de l\'exercice', 'misc'],
    ];

    /** Comptes utilisés par les écritures automatiques (modifiables dans les paramètres). */
    public const DEFAULT_MAPPING = [
        'customers' => '411', 'suppliers' => '401',
        'sales_goods' => '701', 'sales_services' => '706',
        'purchases_goods' => '601', 'purchases_services' => '605',
        'vat_collected' => '4431', 'vat_deductible' => '4452',
        'cash' => '571', 'bank' => '521', 'card' => '521', 'cheque' => '513', 'mobile_money' => '5215', 'other' => '571',
        'loyalty' => '673', 'gift_card' => '419',
    ];

    /**
     * Natures d'achat (SYSCOHADA révisé) : compte proposé pour chaque ligne de facture fournisseur.
     * clé => [libellé, compte, groupe]
     */
    public const PURCHASE_KINDS = [
        'goods' => ['Marchandises (revente)', '601', 'Achats'],
        'materials' => ['Matières premières', '602', 'Achats'],
        'consumables' => ['Matières et fournitures consommables', '604', 'Achats'],
        'water' => ['Eau', '6051', 'Achats'],
        'electricity' => ['Électricité', '6052', 'Achats'],
        'fuel' => ['Carburant et autres énergies', '6053', 'Achats'],
        'office' => ['Fournitures de bureau', '6055', 'Achats'],
        'small_tools' => ['Petit matériel et outillage', '6056', 'Achats'],
        'packaging' => ['Emballages', '608', 'Achats'],
        'transport_in' => ['Transport sur achats', '611', 'Transports'],
        'transport' => ['Autres frais de transport', '618', 'Transports'],
        'rent' => ['Loyers et locations', '622', 'Services extérieurs'],
        'maintenance' => ['Entretien et réparations', '624', 'Services extérieurs'],
        'insurance' => ['Assurances', '625', 'Services extérieurs'],
        'studies' => ['Études, documentation', '626', 'Services extérieurs'],
        'advertising' => ['Publicité, imprimés, flyers', '627', 'Services extérieurs'],
        'telecom' => ['Téléphone et Internet', '628', 'Services extérieurs'],
        'bank_fees' => ['Frais bancaires', '631', 'Autres services extérieurs'],
        'fees' => ['Honoraires et conseils', '632', 'Autres services extérieurs'],
        'training' => ['Formation', '633', 'Autres services extérieurs'],
        'subcontract' => ['Sous-traitance, personnel extérieur', '637', 'Autres services extérieurs'],
        'missions' => ['Missions, réceptions', '6384', 'Autres services extérieurs'],
        'taxes' => ['Impôts et taxes', '641', 'Impôts et taxes'],
        'software' => ['Logiciels et sites internet', '213', 'Immobilisations'],
        'tools' => ['Matériel et outillage', '241', 'Immobilisations'],
        'office_equipment' => ['Matériel de bureau', '2441', 'Immobilisations'],
        'it_equipment' => ['Matériel informatique', '2442', 'Immobilisations'],
        'furniture' => ['Mobilier de bureau', '2444', 'Immobilisations'],
        'vehicles' => ['Matériel de transport', '245', 'Immobilisations'],
    ];

    /** Journal de trésorerie selon le moyen de paiement. */
    public const PAYMENT_JOURNALS = [
        'cash' => 'CA', 'other' => 'CA', 'mobile_money' => 'MM', 'bank_transfer' => 'BQ', 'card' => 'BQ', 'cheque' => 'BQ', 'loyalty' => 'OD', 'gift_card' => 'OD',
    ];

    public static function ensure(Company $company): void
    {
        if (Account::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return;
        }
        // Premier usage : référentiel choisi d'après le pays de la société (modifiable tant qu'aucune écriture n'existe)
        if (! data_get($company->settings, 'compta.referential')) {
            $settings = $company->settings ?? [];
            data_set($settings, 'compta.referential', Referentials::forCountry($company->country));
            $company->forceFill(['settings' => $settings])->saveQuietly();
        }
        self::seed($company, Referentials::of($company));
    }

    /** Crée les comptes du référentiel (sans toucher aux comptes existants) et les journaux. */
    public static function seed(Company $company, string $ref): void
    {
        $now = now();
        $have = Account::withoutGlobalScopes()->where('company_id', $company->id)->pluck('code')->map(fn ($c) => (string) $c)->all();
        $rows = [];
        foreach (Referentials::accounts($ref) as $code => $name) {
            if (! in_array((string) $code, $have, true)) {
                $rows[] = ['company_id' => $company->id, 'code' => (string) $code, 'name' => $name, 'is_system' => true, 'active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            Account::withoutGlobalScopes()->insert($chunk);
        }
        foreach (self::JOURNALS as $code => [$name, $type]) {
            Journal::withoutGlobalScopes()->firstOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => $name, 'type' => $type]);
        }
    }

    /** Comme code(), pour la société en cours (identique hors contexte de société). */
    public static function local(string $canonical): string
    {
        return app()->bound('tenant') && app('tenant') instanceof Company ? Referentials::code(app('tenant'), $canonical) : $canonical;
    }

    /** Numéro, dans le plan de la société, d'un compte SYSCOHADA de référence utilisé par les écritures automatiques. */
    public static function code(Company $company, string $canonical): string
    {
        return Referentials::code($company, $canonical);
    }

    public static function mapping(Company $company): array
    {
        $defaults = array_map(fn ($code) => Referentials::code($company, $code), self::DEFAULT_MAPPING);

        return array_merge($defaults, (array) data_get($company->settings, 'compta.accounts', []));
    }

    /** Natures d'achat avec le compte du référentiel de la société. */
    public static function purchaseKinds(Company $company): array
    {
        return array_map(fn ($k) => [$k[0], Referentials::code($company, $k[1]), $k[2]], self::PURCHASE_KINDS);
    }
}
