<?php

namespace App\Modules\Compta;

use App\Models\Company;

/**
 * Référentiels comptables : SYSCOHADA révisé (système normal et système minimal de trésorerie), SYCEBNL (entités à but
 * non lucratif de l'espace OHADA), Plan comptable général français, Code général de normalisation comptable marocain.
 *
 * Le logiciel écrit ses écritures automatiques avec des comptes SYSCOHADA (« comptes de référence ») ; chaque référentiel
 * les traduit vers ses propres numéros. La société garde ainsi les numéros de son plan, partout (écritures, paramètres,
 * états), et les états financiers suivent le modèle de son référentiel.
 */
class Referentials
{
    public const OHADA_COUNTRIES = ['BJ', 'BF', 'CM', 'CF', 'KM', 'CG', 'CD', 'CI', 'GA', 'GN', 'GW', 'GQ', 'ML', 'NE', 'SN', 'TD', 'TG'];

    public const LIST = [
        'syscohada' => [
            'label' => 'SYSCOHADA révisé — système normal', 'short' => 'SYSCOHADA', 'zone' => 'Espace OHADA (17 pays)', 'official_refs' => true,
            'description' => 'Entreprises de l\'espace OHADA : bilan, compte de résultat avec soldes intermédiaires de gestion et tableau des flux de trésorerie au modèle du système normal.',
        ],
        'syscohada_smt' => [
            'label' => 'SYSCOHADA révisé — système minimal de trésorerie (SMT)', 'short' => 'SMT', 'zone' => 'Espace OHADA, très petites entités', 'official_refs' => false,
            'description' => 'Très petites entités dont le chiffre d\'affaires reste sous les seuils du SMT : plan de comptes allégé, bilan et compte de résultat simplifiés, état des recettes et des dépenses.',
        ],
        'sycebnl' => [
            'label' => 'SYCEBNL — entités à but non lucratif', 'short' => 'SYCEBNL', 'zone' => 'Espace OHADA : associations, ONG, fondations, ordres professionnels, projets de développement', 'official_refs' => false,
            'description' => 'Fonds propres et dotations, fonds affectés des bailleurs, adhérents et cotisations, dons et générosité, subventions ; résultat présenté en excédent ou déficit.',
        ],
        'pcg' => [
            'label' => 'Plan comptable général (France)', 'short' => 'PCG', 'zone' => 'France et pays utilisant le PCG', 'official_refs' => false,
            'description' => 'Plan comptable général (règlement ANC n° 2014-03) : bilan et compte de résultat en liste, résultat d\'exploitation, financier et exceptionnel.',
        ],
        'cgnc' => [
            'label' => 'Code général de normalisation comptable (Maroc)', 'short' => 'CGNC', 'zone' => 'Maroc', 'official_refs' => false,
            'description' => 'Plan comptable marocain : bilan (actif / passif) et compte de produits et charges (CPC) avec résultats d\'exploitation, financier, courant, non courant et net.',
        ],
    ];

    // ------------------------------------------------------------------------------------------------ plans de comptes

    /** SMT : comptes SYSCOHADA retenus pour les très petites entités. */
    protected const SMT_CODES = [
        '101', '104', '121', '129', '131', '139', '162', '22', '231', '234', '241', '2441', '2442', '2444', '245', '2841', '2844', '2845', '31', '32',
        '401', '409', '411', '419', '421', '422', '4286', '431', '441', '442', '4431', '4441', '4452', '447', '461', '471',
        '521', '5215', '571', '585', '601', '602', '6031', '604', '605', '6051', '6052', '6053', '6055', '611', '618', '622', '624', '625', '627', '628', '631', '632', '637', '638',
        '641', '651', '661', '664', '671', '673', '676', '681', '701', '702', '706', '707', '71', '758', '776', '82', '81', '513', '4451', '4454', '4449', '891',
    ];

    /** SYCEBNL : comptes propres aux entités à but non lucratif (ajoutés au plan SYSCOHADA). */
    protected const SYCEBNL_EXTRA = [
        '101' => 'Dotation non consomptible sans droit de reprise', '102' => 'Dotation non consomptible avec droit de reprise', '103' => 'Droits d\'entrée',
        '11' => 'Réserves', '1041' => 'Dotation consomptible', '1049' => 'Dotation consomptible inscrite au compte de résultat',
        '131' => 'Résultat net : excédent', '139' => 'Résultat net : déficit', '14' => 'Subventions d\'investissement',
        '161' => 'Fonds affectés (bailleurs)', '162' => 'Fonds affectés aux investissements', '163' => 'Fonds de contrepartie de l\'État', '164' => 'Autres fonds reçus',
        '165' => 'Fonds affectés à un projet spécifique', '167' => 'Fonds provenant des dons et legs d\'immobilisations', '181' => 'Emprunts auprès des établissements de crédit',
        '411' => 'Adhérents', '4161' => 'Adhérents, cotisations douteuses', '412' => 'Clients-usagers', '4511' => 'Apporteurs en nature', '4512' => 'Apporteurs en numéraire',
        '4731' => 'Subventions d\'investissement à recevoir', '4732' => 'Subventions d\'exploitation à recevoir', '475' => 'Générosités financières à recevoir',
        '636' => 'Frais de recherche de fonds', '654' => 'Dons en nature courants à distribuer',
        '701' => 'Cotisations des adhérents', '702' => 'Quote-part de fonds d\'administration transférés', '703' => 'Quote-part des dotations consomptibles transférées',
        '7041' => 'Dons', '7042' => 'Legs', '7046' => 'Mécénat', '7051' => 'Ventes de marchandises', '7052' => 'Ventes de produits finis', '7053' => 'Ventes de services',
        '706' => 'Revenus des manifestations', '707' => 'Produits accessoires', '708' => 'Autres revenus',
        '711' => 'Subventions d\'exploitation de l\'État et des collectivités publiques', '713' => 'Subventions d\'exploitation des organismes internationaux', '718' => 'Autres subventions d\'exploitation',
        '752' => 'Contributions du fondateur', '7583' => 'Abandons de frais par les bénévoles', '7925' => 'Reprises de fonds affectés à un projet spécifique', '88' => 'Subventions d\'équilibre',
    ];

    /** SYCEBNL : comptes SYSCOHADA remplacés (capital, ventes, clients). */
    protected const SYCEBNL_REMOVED = ['1013', '104', '111', '118', '17', '702', '705'];

    protected const SYCEBNL_MAP = [
        '101' => '101', '104' => '4512', '111' => '11', '118' => '11', '162' => '181', '17' => '181', '411' => '412', '416' => '4161',
        '701' => '7051', '702' => '7052', '705' => '7053', '706' => '7053', '707' => '707', '71' => '711',
    ];

    /** Plan comptable général (France) : numéro => [libellé, comptes SYSCOHADA de référence]. */
    protected const PCG = [
        '101' => ['Capital', ['101', '1013']], '104' => ['Primes liées au capital social', []], '105' => ['Écarts de réévaluation', []],
        '1061' => ['Réserve légale', ['111']], '1063' => ['Réserves statutaires ou contractuelles', []], '1068' => ['Autres réserves', ['118']],
        '108' => ['Compte de l\'exploitant', ['104']], '110' => ['Report à nouveau (solde créditeur)', ['121']], '119' => ['Report à nouveau (solde débiteur)', ['129']],
        '120' => ['Résultat de l\'exercice (bénéfice)', ['131']], '129' => ['Résultat de l\'exercice (perte)', ['139']], '131' => ['Subventions d\'équipement', ['14']],
        '145' => ['Amortissements dérogatoires', []], '151' => ['Provisions pour risques', ['19']], '164' => ['Emprunts auprès des établissements de crédit', ['162']],
        '168' => ['Autres emprunts et dettes assimilées', ['17']], '201' => ['Frais d\'établissement', []], '205' => ['Concessions, brevets, licences, marques', ['212']],
        '2051' => ['Logiciels et sites internet', ['213']], '207' => ['Fonds commercial', []], '211' => ['Terrains', ['22']], '213' => ['Constructions', ['231']],
        '215' => ['Installations techniques, matériel et outillage industriels', ['241']], '2181' => ['Installations générales, agencements, aménagements divers', ['234']],
        '2182' => ['Matériel de transport', ['245']], '21831' => ['Matériel de bureau', ['2441']], '21832' => ['Matériel informatique', ['2442']], '2184' => ['Mobilier', ['2444']],
        '231' => ['Immobilisations corporelles en cours', []], '275' => ['Dépôts et cautionnements versés', ['275']], '2805' => ['Amortissements des logiciels et licences', ['2813']],
        '2813' => ['Amortissements des constructions', ['2831']], '2815' => ['Amortissements des installations techniques et du matériel', ['2841']],
        '28181' => ['Amortissements des agencements et aménagements', ['2834']], '28182' => ['Amortissements du matériel de transport', ['2845']],
        '28183' => ['Amortissements du matériel de bureau, informatique et mobilier', ['2844']],
        '31' => ['Matières premières', ['32']], '32' => ['Autres approvisionnements', ['33']], '355' => ['Produits finis', ['36']], '37' => ['Stocks de marchandises', ['31']],
        '397' => ['Dépréciations des stocks de marchandises', ['39']],
        '401' => ['Fournisseurs', ['401']], '404' => ['Fournisseurs d\'immobilisations', ['481']], '408' => ['Fournisseurs, factures non parvenues', ['408']],
        '4091' => ['Fournisseurs, avances et acomptes versés', ['409']], '411' => ['Clients', ['411']], '416' => ['Clients douteux ou litigieux', ['416']],
        '418' => ['Clients, produits non encore facturés', ['418']], '4191' => ['Clients, avances et acomptes reçus', ['419']],
        '421' => ['Personnel, rémunérations dues', ['422']], '425' => ['Personnel, avances et acomptes', ['421']], '4286' => ['Personnel, autres charges à payer (notes de frais)', ['4286']],
        '431' => ['Sécurité sociale (URSSAF)', ['431']], '437' => ['Autres organismes sociaux', []], '4421' => ['Prélèvement à la source (impôt sur le revenu)', ['447']],
        '444' => ['État, impôts sur les bénéfices', ['441']], '44551' => ['TVA à décaisser', ['4441']], '44562' => ['TVA déductible sur immobilisations', ['4451']],
        '44566' => ['TVA déductible sur autres biens et services', ['4452', '4454']], '44567' => ['Crédit de TVA à reporter', ['4449']],
        '44571' => ['TVA collectée', ['4431', '4432']], '447' => ['Autres impôts, taxes et versements assimilés', ['442']], '455' => ['Associés, comptes courants', ['461']], '457' => ['Associés, dividendes à payer', ['465']],
        '462' => ['Créances sur cessions d\'immobilisations', ['485']], '467' => ['Autres comptes débiteurs ou créditeurs', []], '471' => ['Comptes d\'attente', ['471']],
        '486' => ['Charges constatées d\'avance', ['476']], '487' => ['Produits constatés d\'avance', ['477']], '491' => ['Dépréciations des comptes clients', ['491']],
        '5112' => ['Chèques à encaisser', ['513']], '512' => ['Banques', ['521']], '517' => ['Autres organismes financiers (paiement mobile)', ['5215']],
        '519' => ['Concours bancaires courants', ['561']], '530' => ['Caisse', ['571']], '580' => ['Virements internes', ['585']],
        '601' => ['Achats stockés de matières premières', ['602']], '602' => ['Achats stockés d\'autres approvisionnements', ['604']], '6026' => ['Emballages', ['608']],
        '6031' => ['Variation des stocks de matières premières', ['6032']], '6032' => ['Variation des stocks des autres approvisionnements', ['6033']],
        '6037' => ['Variation des stocks de marchandises', ['6031']], '606' => ['Achats non stockés de matières et fournitures', ['605']],
        '60611' => ['Eau', ['6051']], '60612' => ['Électricité', ['6052']], '60613' => ['Carburants et autres énergies', ['6053']], '6063' => ['Fournitures d\'entretien et de petit équipement', ['6056']],
        '6064' => ['Fournitures administratives', ['6055']], '607' => ['Achats de marchandises', ['601']], '613' => ['Locations', ['622']], '615' => ['Entretien et réparations', ['624']],
        '616' => ['Primes d\'assurances', ['625']], '617' => ['Études et recherches', ['626']], '6185' => ['Frais de colloques, séminaires et formation', ['633']],
        '621' => ['Personnel extérieur à l\'entreprise', ['637']], '622' => ['Rémunérations d\'intermédiaires et honoraires', ['632']],
        '623' => ['Publicité, publications, relations publiques', ['627']], '6241' => ['Transports sur achats', ['611']], '6242' => ['Transports sur ventes', ['612']],
        '6247' => ['Transports collectifs du personnel', ['614']], '6248' => ['Transports divers', ['618']], '6251' => ['Voyages et déplacements', ['6181']],
        '6256' => ['Missions', ['6384']], '6257' => ['Réceptions', ['6383']], '626' => ['Frais postaux et de télécommunications', ['628']], '627' => ['Services bancaires et assimilés', ['631']],
        '628' => ['Divers (cotisations, autres charges externes)', ['638', '635']], '635' => ['Impôts, taxes et versements assimilés', ['641', '645']],
        '641' => ['Rémunérations du personnel', ['661']], '645' => ['Charges de sécurité sociale et de prévoyance', ['664']], '654' => ['Pertes sur créances irrécouvrables', ['651']],
        '658' => ['Charges diverses de gestion courante', ['658']], '661' => ['Charges d\'intérêts', ['671']], '665' => ['Escomptes accordés', ['673']], '666' => ['Pertes de change', ['676']],
        '671' => ['Charges exceptionnelles sur opérations de gestion', ['83']], '675' => ['Valeurs comptables des éléments d\'actif cédés', ['81']],
        '6811' => ['Dotations aux amortissements des immobilisations', ['681']], '6815' => ['Dotations aux provisions d\'exploitation', ['691']],
        '691' => ['Participation des salariés aux résultats', []], '695' => ['Impôts sur les bénéfices', ['891']],
        '701' => ['Ventes de produits finis', ['702']], '704' => ['Travaux', ['705']], '706' => ['Prestations de services', ['706']], '707' => ['Ventes de marchandises', ['701']],
        '708' => ['Produits des activités annexes', ['707']], '713' => ['Variation des stocks de produits', []], '72' => ['Production immobilisée', []], '74' => ['Subventions d\'exploitation', ['71']],
        '758' => ['Produits divers de gestion courante', ['758']], '761' => ['Produits de participations', ['772']], '763' => ['Revenus des autres créances (intérêts)', ['771']], '766' => ['Gains de change', ['776']],
        '771' => ['Produits exceptionnels sur opérations de gestion', ['84']], '775' => ['Produits des cessions d\'éléments d\'actif', ['82']],
        '781' => ['Reprises sur amortissements et provisions', ['791']], '791' => ['Transferts de charges d\'exploitation', ['781']],
    ];

    /** Code général de normalisation comptable (Maroc) : numéro => [libellé, comptes SYSCOHADA de référence]. */
    protected const CGNC = [
        '1111' => ['Capital social', ['101', '1013']], '1117' => ['Capital individuel', ['104']], '1140' => ['Réserve légale', ['111']], '1152' => ['Réserves facultatives', ['118']],
        '1161' => ['Report à nouveau (solde créditeur)', ['121']], '1169' => ['Report à nouveau (solde débiteur)', ['129']],
        '1191' => ['Résultat net de l\'exercice (solde créditeur)', ['131']], '1199' => ['Résultat net de l\'exercice (solde débiteur)', ['139']],
        '1311' => ['Subventions d\'investissement reçues', ['14']], '1481' => ['Emprunts auprès des établissements de crédit', ['162']], '1488' => ['Dettes de financement diverses', ['17']],
        '1518' => ['Autres provisions pour risques', ['19']], '2111' => ['Frais de constitution', []], '2220' => ['Brevets, marques, droits, logiciels et valeurs similaires', ['212', '213']],
        '2230' => ['Fonds commercial', []], '2310' => ['Terrains', ['22']], '2321' => ['Bâtiments', ['231']], '2332' => ['Matériel et outillage', ['241']],
        '2340' => ['Matériel de transport', ['245']], '2351' => ['Mobilier de bureau', ['2444']], '2352' => ['Matériel de bureau', ['2441']], '2355' => ['Matériel informatique', ['2442']],
        '2356' => ['Agencements, installations et aménagements divers', ['234']], '2486' => ['Dépôts et cautionnements versés', ['275']],
        '2822' => ['Amortissements des immobilisations incorporelles', ['2813']], '28321' => ['Amortissements des bâtiments', ['2831']],
        '28332' => ['Amortissements du matériel et outillage', ['2841']], '28340' => ['Amortissements du matériel de transport', ['2845']],
        '28350' => ['Amortissements du mobilier, matériel de bureau et informatique', ['2844']], '28356' => ['Amortissements des agencements et aménagements', ['2834']],
        '3111' => ['Marchandises', ['31']], '3121' => ['Matières premières', ['32']], '3122' => ['Matières et fournitures consommables', ['33']], '3151' => ['Produits finis', ['36']],
        '3411' => ['Fournisseurs, avances et acomptes versés', ['409']], '3421' => ['Clients', ['411']], '3424' => ['Clients douteux ou litigieux', ['416']],
        '3427' => ['Clients, factures à établir', ['418']], '3431' => ['Avances et acomptes au personnel', ['421']], '34551' => ['État, TVA récupérable sur immobilisations', ['4451']],
        '34552' => ['État, TVA récupérable sur charges', ['4452', '4454']], '3456' => ['État, crédit de TVA', ['4449']], '3481' => ['Créances sur cessions d\'immobilisations', ['485']],
        '3491' => ['Charges constatées d\'avance', ['476']], '3911' => ['Provisions pour dépréciation des marchandises', ['39']], '3942' => ['Provisions pour dépréciation des clients', ['491']],
        '4411' => ['Fournisseurs', ['401']], '4417' => ['Fournisseurs, factures non parvenues', ['408']], '4421' => ['Clients, avances et acomptes reçus', ['419']],
        '4432' => ['Rémunérations dues au personnel', ['422']], '4438' => ['Personnel, charges à payer (notes de frais)', ['4286']], '4441' => ['Caisse nationale de sécurité sociale (CNSS)', ['431']],
        '4452' => ['État, impôts, taxes et assimilés', ['442']], '44525' => ['État, impôt sur le revenu retenu à la source', ['447']], '4453' => ['État, impôt sur les résultats', ['441']],
        '4455' => ['État, TVA facturée', ['4431', '4432']], '4456' => ['État, TVA due (suivant déclarations)', ['4441']], '4463' => ['Comptes courants des associés créditeurs', ['461']], '4465' => ['Associés, dividendes à payer', ['465']],
        '4481' => ['Dettes sur acquisitions d\'immobilisations', ['481']], '4491' => ['Produits constatés d\'avance', ['477']], '4497' => ['Comptes transitoires ou d\'attente', ['471']],
        '5111' => ['Chèques et valeurs à encaisser', ['513']], '5115' => ['Virements de fonds', ['585']], '5141' => ['Banques', ['521']], '5148' => ['Autres établissements financiers (paiement mobile)', ['5215']],
        '5161' => ['Caisses', ['571']], '5530' => ['Crédits de trésorerie', ['561']],
        '6111' => ['Achats de marchandises', ['601']], '6114' => ['Variation des stocks de marchandises', ['6031']], '6121' => ['Achats de matières premières', ['602']],
        '6122' => ['Achats de matières et fournitures consommables', ['604']], '6123' => ['Achats d\'emballages', ['608']], '6124' => ['Variation des stocks de matières et fournitures', ['6032', '6033']],
        '6125' => ['Achats non stockés de matières et fournitures', ['605']], '61251' => ['Eau, électricité et carburants', ['6051', '6052', '6053']], '61253' => ['Petit outillage et petit équipement', ['6056']],
        '61254' => ['Fournitures de bureau', ['6055']], '6131' => ['Locations et charges locatives', ['622']], '6133' => ['Entretien et réparations', ['624']], '6134' => ['Primes d\'assurances', ['625']],
        '6135' => ['Rémunérations du personnel extérieur à l\'entreprise', ['637']], '6136' => ['Rémunérations d\'intermédiaires et honoraires', ['632', '633']],
        '6141' => ['Études, recherches et documentation', ['626']], '6142' => ['Transports', ['611', '612', '614', '618']], '6143' => ['Déplacements, missions et réceptions', ['6181', '6383', '6384']],
        '6144' => ['Publicité, publications et relations publiques', ['627']], '6145' => ['Frais postaux et frais de télécommunications', ['628']], '6146' => ['Cotisations et dons', ['635']],
        '6147' => ['Services bancaires', ['631']], '6167' => ['Impôts, taxes et droits assimilés', ['641', '645']], '6171' => ['Rémunérations du personnel', ['661']],
        '6174' => ['Charges sociales', ['664']], '6182' => ['Pertes sur créances irrécouvrables', ['651']], '6188' => ['Autres charges d\'exploitation', ['638', '658']],
        '6193' => ['Dotations d\'exploitation aux amortissements des immobilisations corporelles', ['681']], '6196' => ['Dotations d\'exploitation aux provisions pour dépréciation de l\'actif circulant', ['691']],
        '6311' => ['Intérêts des emprunts et dettes', ['671']], '6331' => ['Pertes de change', ['676']], '6386' => ['Escomptes accordés', ['673']],
        '6513' => ['Valeurs nettes d\'amortissements des immobilisations cédées', ['81']], '6588' => ['Autres charges non courantes', ['83']], '6701' => ['Impôts sur les bénéfices', ['891']],
        '7111' => ['Ventes de marchandises au Maroc', ['701']], '7121' => ['Ventes de biens produits au Maroc', ['702']], '7124' => ['Ventes de services produits au Maroc', ['705', '706']],
        '7127' => ['Ventes et produits accessoires', ['707']], '7161' => ['Subventions d\'exploitation reçues', ['71']], '7188' => ['Autres produits d\'exploitation', ['758']],
        '7196' => ['Reprises sur provisions pour dépréciation de l\'actif circulant', ['791']], '7197' => ['Transferts de charges d\'exploitation', ['781']],
        '7331' => ['Gains de change', ['776']], '7321' => ['Produits des titres de participation', ['772']], '7381' => ['Intérêts et produits assimilés', ['771']], '7513' => ['Produits des cessions des immobilisations corporelles', ['82']],
        '7588' => ['Autres produits non courants', ['84']],
    ];

    /** Comptes de regroupement (préfixes) utilisés par certains calculs : charges de personnel. */
    protected const GROUP_MAP = [
        'pcg' => ['66' => '64', '6' => '6', '7' => '7'],
        'cgnc' => ['66' => '617', '6' => '6', '7' => '7'],
    ];

    // ------------------------------------------------------------------------------------------------ accès

    public static function of(Company $c): string
    {
        $r = data_get($c->settings, 'compta.referential');

        return isset(self::LIST[$r]) ? $r : self::forCountry($c->country);
    }

    public static function forCountry(?string $country): string
    {
        return match (strtoupper((string) $country)) {
            'FR', 'MC', 'GP', 'MQ', 'GF', 'RE', 'YT', 'PM', 'BL', 'MF' => 'pcg',
            'MA' => 'cgnc',
            default => 'syscohada',
        };
    }

    /** Plan de comptes à créer : numéro => libellé. */
    public static function accounts(string $ref): array
    {
        return match ($ref) {
            'syscohada_smt' => array_intersect_key(Chart::ACCOUNTS, array_flip(self::SMT_CODES)),
            'sycebnl' => array_replace(array_diff_key(Chart::ACCOUNTS, array_flip(self::SYCEBNL_REMOVED)), self::SYCEBNL_EXTRA),
            'pcg' => array_map(fn ($x) => $x[0], self::PCG),
            'cgnc' => array_map(fn ($x) => $x[0], self::CGNC),
            default => Chart::ACCOUNTS,
        };
    }

    /** Correspondance compte SYSCOHADA de référence => compte du référentiel. */
    public static function map(string $ref): array
    {
        static $cache = [];
        if (isset($cache[$ref])) {
            return $cache[$ref];
        }
        $m = [];
        if (in_array($ref, ['pcg', 'cgnc'], true)) {
            foreach ($ref === 'pcg' ? self::PCG : self::CGNC as $local => [, $refs]) {
                foreach ($refs as $r) {
                    $m[$r] ??= (string) $local;
                }
            }
        } elseif ($ref === 'sycebnl') {
            $m = self::SYCEBNL_MAP;
        }

        return $cache[$ref] = $m;
    }

    /** Numéro du référentiel pour un compte SYSCOHADA de référence (le plus proche à défaut d'équivalent exact). */
    public static function code(Company|string $c, string $canonical): string
    {
        $ref = $c instanceof Company ? self::of($c) : $c;
        if ($ref === 'syscohada' || $ref === 'syscohada_smt') {
            return $canonical;
        }
        $m = self::map($ref);
        if (isset($m[$canonical])) {
            return $m[$canonical];
        }
        if ($ref === 'sycebnl') {
            return $canonical;
        }
        if (isset(self::GROUP_MAP[$ref][$canonical])) {
            return self::GROUP_MAP[$ref][$canonical];
        }
        // Sous-compte SYSCOHADA sans équivalent : compte du référentiel le plus proche (préfixe), sinon la classe
        for ($n = strlen($canonical) - 1; $n >= 2; $n--) {
            if (isset($m[substr($canonical, 0, $n)])) {
                return $m[substr($canonical, 0, $n)];
            }
        }

        return $canonical;
    }

    /** Conversion d'un numéro d'un référentiel à un autre (changement de référentiel). */
    public static function translate(string $code, string $from, string $to): string
    {
        if ($from === $to || $code === '') {
            return $code;
        }
        $reverse = array_flip(array_reverse(self::map($from), true));
        $canonical = $reverse[$code] ?? $code;

        return self::code($to, $canonical);
    }

    public static function name(string $ref, string $code): ?string
    {
        return self::accounts($ref)[$code] ?? null;
    }

    /** Rubriques simplifiées du compte de résultat (tableau de bord, analytique) : préfixe => libellé. */
    public static function groups(string $ref): array
    {
        return match ($ref) {
            'pcg' => [
                'produits' => ['70' => 'Ventes (chiffre d\'affaires)', '71' => 'Production stockée', '72' => 'Production immobilisée', '74' => 'Subventions d\'exploitation', '75' => 'Autres produits de gestion courante',
                    '76' => 'Produits financiers', '77' => 'Produits exceptionnels', '78' => 'Reprises sur amortissements et provisions', '79' => 'Transferts de charges'],
                'charges' => ['60' => 'Achats et variations de stocks', '61' => 'Services extérieurs', '62' => 'Autres services extérieurs', '63' => 'Impôts et taxes', '64' => 'Charges de personnel',
                    '65' => 'Autres charges de gestion courante', '66' => 'Charges financières', '67' => 'Charges exceptionnelles', '68' => 'Dotations aux amortissements et provisions', '69' => 'Participation et impôts sur les bénéfices'],
            ],
            'cgnc' => [
                'produits' => ['711' => 'Ventes de marchandises', '712' => 'Ventes de biens et services produits', '713' => 'Variation de stocks de produits', '714' => 'Immobilisations produites',
                    '716' => 'Subventions d\'exploitation', '718' => 'Autres produits d\'exploitation', '719' => 'Reprises d\'exploitation, transferts de charges', '73' => 'Produits financiers', '75' => 'Produits non courants'],
                'charges' => ['611' => 'Achats revendus de marchandises', '612' => 'Achats consommés de matières et fournitures', '613' => 'Autres charges externes', '614' => 'Autres charges externes',
                    '616' => 'Impôts et taxes', '617' => 'Charges de personnel', '618' => 'Autres charges d\'exploitation', '619' => 'Dotations d\'exploitation', '63' => 'Charges financières',
                    '65' => 'Charges non courantes', '67' => 'Impôts sur les résultats'],
            ],
            'sycebnl' => [
                'produits' => ['701' => 'Cotisations des adhérents', '702' => 'Fonds d\'administration transférés', '703' => 'Dotations consomptibles transférées', '704' => 'Dons, legs et mécénat',
                    '705' => 'Ventes de marchandises, services et produits', '706' => 'Revenus des manifestations', '707' => 'Produits accessoires', '708' => 'Autres revenus', '71' => 'Subventions d\'exploitation',
                    '72' => 'Production immobilisée', '75' => 'Autres produits', '77' => 'Revenus financiers', '78' => 'Transferts de charges', '79' => 'Reprises', '82' => 'Produits des cessions (HAO)',
                    '84' => 'Autres produits HAO', '86' => 'Reprises HAO', '88' => 'Subventions d\'équilibre'],
                'charges' => Ledger::RESULT_GROUPS['charges'],
            ],
            default => Ledger::RESULT_GROUPS,
        };
    }

    /** Préfixe de rattachement le plus long parmi les rubriques. */
    public static function group(array $groups, string $code): ?string
    {
        $best = null;
        foreach (array_keys($groups) as $p) {
            $p = (string) $p;
            if (str_starts_with($code, $p) && strlen($p) > strlen((string) $best)) {
                $best = $p;
            }
        }

        return $best;
    }

    /** Préfixes des comptes de TVA collectée et déductible. */
    public static function vat(string $ref): array
    {
        return match ($ref) {
            'pcg' => ['collected' => '4457', 'deductible' => '4456'],
            'cgnc' => ['collected' => '4455', 'deductible' => '3455'],
            default => ['collected' => '443', 'deductible' => '445'],
        };
    }

    /** Préfixes des comptes de tiers : clients et fournisseurs. */
    public static function tiers(string $ref): array
    {
        return match ($ref) {
            'cgnc' => ['customers' => '342', 'suppliers' => '441'],
            default => ['customers' => '41', 'suppliers' => '40'],
        };
    }

    /** Classes de comptes de gestion qui forment le résultat. */
    public static function resultClasses(string $ref): array
    {
        return in_array($ref, ['pcg', 'cgnc'], true) ? ['6', '7'] : ['6', '7', '8'];
    }

    /**
     * Change le référentiel d'une société qui n'a encore aucune écriture : plan de comptes recréé, comptes paramétrés
     * (correspondances, natures d'achat mémorisées, catégories d'immobilisations et de notes de frais, comptes de trésorerie,
     * paie) convertis vers les numéros du nouveau référentiel.
     */
    public static function switchTo(Company $c, string $to): void
    {
        if (! isset(self::LIST[$to])) {
            throw new \DomainException('Référentiel inconnu.');
        }
        $from = self::of($c);
        if (\App\Modules\Compta\Models\Entry::withoutGlobalScopes()->where('company_id', $c->id)->exists()) {
            throw new \DomainException('Des écritures existent déjà : le référentiel ne peut plus être changé. Il se choisit avant la première écriture.');
        }
        $tr = fn (?string $code) => $code === null || $code === '' ? $code : self::translate($code, $from, $to);
        \Illuminate\Support\Facades\DB::transaction(function () use ($c, $from, $to, $tr) {
            $settings = $c->settings ?? [];
            data_set($settings, 'compta.referential', $to);
            data_set($settings, 'compta.accounts', array_map($tr, (array) data_get($settings, 'compta.accounts', [])));
            foreach (['immo.counterpart', 'immo.sale_account', 'notes_frais.payable_account'] as $k) {
                if (data_get($settings, $k)) {
                    data_set($settings, $k, $tr(data_get($settings, $k)));
                }
            }
            if (is_array(data_get($settings, 'paie.comptes'))) {
                data_set($settings, 'paie.comptes', array_map($tr, data_get($settings, 'paie.comptes')));
            }
            $c->forceFill(['settings' => $settings])->save();
            \App\Modules\Compta\Models\Account::withoutGlobalScopes()->where('company_id', $c->id)->delete();
            Chart::seed($c, $to);
            $db = \Illuminate\Support\Facades\DB::table('compta_account_rules');
            foreach ((clone $db)->where('company_id', $c->id)->get(['id', 'account_code']) as $r) {
                (clone $db)->where('id', $r->id)->update(['account_code' => $tr($r->account_code)]);
            }
            foreach (\Illuminate\Support\Facades\DB::table('trade_document_lines as l')->join('trade_documents as d', 'd.id', '=', 'l.document_id')
                ->where('d.company_id', $c->id)->whereNotNull('l.account_code')->get(['l.id', 'l.account_code']) as $l) {
                \Illuminate\Support\Facades\DB::table('trade_document_lines')->where('id', $l->id)->update(['account_code' => $tr($l->account_code)]);
            }
            // Tables des autres modules (enregistrements synchronisés : version et journal de synchronisation mis à jour)
            $models = [
                \App\Modules\Immobilisations\Models\AssetCategory::class => ['gl_asset', 'gl_depreciation', 'gl_expense'],
                \App\Modules\NotesFrais\Models\ExpenseCategory::class => ['gl_code'],
                \App\Modules\Tresorerie\Models\TreasuryAccount::class => ['gl_code'],
            ];
            foreach ($models as $class => $fields) {
                if (! class_exists($class) || ! \Illuminate\Support\Facades\Schema::hasTable((new $class)->getTable())) {
                    continue;
                }
                foreach ($class::withoutGlobalScopes()->where('company_id', $c->id)->get() as $m) {
                    foreach ($fields as $f) {
                        $m->{$f} = $tr($m->{$f});
                    }
                    if ($m->isDirty()) {
                        $m->save();
                    }
                }
            }
        });
    }

    public static function options(): array
    {
        return collect(self::LIST)->map(fn ($x, $k) => ['key' => $k] + $x)->values()->all();
    }
}
