<?php

namespace App\Modules\Rapports;

/** Modèles de rapports prêts à l'emploi (galerie « Nouveau rapport »). */
class Templates
{
    public static function all(): array
    {
        return [
            'ca_mois' => ['name' => 'CA HT du mois', 'folder' => 'Ventes', 'source' => 'ventes', 'description' => 'Comparé au mois précédent.',
                'config' => ['measures' => ['subtotal'], 'period' => 'this_month', 'compare' => 'previous_period', 'chart' => 'kpi']],
            'reste_encaisser' => ['name' => 'Reste à encaisser', 'folder' => 'Ventes', 'source' => 'ventes', 'description' => 'Factures clients non soldées, toutes dates.',
                'config' => ['measures' => ['balance'], 'period' => 'all', 'chart' => 'kpi']],
            'ca_mensuel' => ['name' => 'Chiffre d\'affaires mensuel', 'folder' => 'Ventes', 'source' => 'ventes', 'description' => 'CA HT des 12 derniers mois.',
                'config' => ['dimensions' => ['month'], 'measures' => ['subtotal'], 'period' => 'last_12_months', 'chart' => 'bar']],
            'ca_cumule' => ['name' => 'CA cumulé de l\'année', 'folder' => 'Ventes', 'source' => 'ventes', 'description' => 'Progression du CA HT depuis janvier, comparée à l\'an dernier.',
                'config' => ['dimensions' => ['month'], 'measures' => ['subtotal'], 'period' => 'this_year', 'cumulative' => true, 'compare' => 'previous_year', 'chart' => 'line']],
            'top_clients' => ['name' => 'Top 10 clients', 'folder' => 'Ventes', 'source' => 'ventes', 'description' => 'Meilleurs clients de l\'année (CA HT).',
                'config' => ['dimensions' => ['partner'], 'measures' => ['subtotal', 'count'], 'period' => 'this_year', 'limit' => 10, 'chart' => 'hbar']],
            'top_articles' => ['name' => 'Top 10 articles', 'folder' => 'Ventes', 'source' => 'ventes_articles', 'description' => 'Articles les plus vendus de l\'année avec leur marge.',
                'config' => ['dimensions' => ['product'], 'measures' => ['subtotal', 'quantity', 'margin', 'margin_rate'], 'period' => 'this_year', 'limit' => 10, 'chart' => 'table']],
            'ventes_categories' => ['name' => 'Ventes par catégorie', 'folder' => 'Ventes', 'source' => 'ventes_articles', 'description' => 'Répartition du CA HT de l\'année.',
                'config' => ['dimensions' => ['category'], 'measures' => ['subtotal'], 'period' => 'this_year', 'limit' => 8, 'chart' => 'pie']],
            'marge_mensuelle' => ['name' => 'Marge mensuelle', 'folder' => 'Ventes', 'source' => 'ventes_articles', 'description' => 'Ventes HT et marge estimée des 12 derniers mois.',
                'config' => ['dimensions' => ['month'], 'measures' => ['subtotal', 'margin'], 'period' => 'last_12_months', 'chart' => 'line']],
            'ventes_vendeurs' => ['name' => 'Ventes par vendeur', 'folder' => 'Ventes', 'source' => 'ventes', 'description' => 'CA, nombre de documents et panier moyen du mois.',
                'config' => ['dimensions' => ['user'], 'measures' => ['subtotal', 'count', 'avg'], 'period' => 'this_month', 'compare' => 'previous_period', 'chart' => 'table']],
            'jours_semaine' => ['name' => 'Ventes par jour de la semaine', 'folder' => 'Ventes', 'source' => 'ventes', 'description' => 'Affluence des 90 derniers jours (tickets et factures).',
                'config' => ['dimensions' => ['weekday'], 'measures' => ['total', 'count'], 'period' => 'last_90_days', 'sort' => ['by' => 'weekday', 'dir' => 'asc'], 'chart' => 'bar']],
            'transformation_devis' => ['name' => 'Transformation des devis', 'folder' => 'Ventes', 'source' => 'ventes_pipeline', 'description' => 'Devis émis et part transformée en commande ou facture.',
                'config' => ['dimensions' => ['month'], 'measures' => ['count', 'total', 'conversion'], 'filters' => [['field' => 'type', 'op' => 'eq', 'value' => 'quote']], 'period' => 'last_12_months', 'chart' => 'table']],
            'pipeline_crm' => ['name' => 'Pipeline commercial', 'folder' => 'CRM', 'source' => 'crm', 'description' => 'Opportunités en cours par étape.',
                'config' => ['dimensions' => ['stage'], 'measures' => ['expected', 'weighted', 'count'], 'filters' => [['field' => 'status', 'op' => 'eq', 'value' => 'open']], 'period' => 'all', 'chart' => 'hbar']],
            'reussite_commerciaux' => ['name' => 'Taux de réussite par commercial', 'folder' => 'CRM', 'source' => 'crm', 'description' => 'Opportunités de l\'année, gagnées et perdues.',
                'config' => ['dimensions' => ['owner'], 'measures' => ['count', 'won_amount', 'win_rate'], 'period' => 'this_year', 'chart' => 'table']],
            'achats_fournisseurs' => ['name' => 'Achats par fournisseur', 'folder' => 'Achats', 'source' => 'achats', 'description' => 'Top 10 des fournisseurs de l\'année (HT).',
                'config' => ['dimensions' => ['partner'], 'measures' => ['subtotal', 'balance'], 'period' => 'this_year', 'limit' => 10, 'chart' => 'hbar']],
            'achats_articles' => ['name' => 'Prix d\'achat moyen par article', 'folder' => 'Achats', 'source' => 'achats_articles', 'description' => 'Quantités et prix moyen payés cette année.',
                'config' => ['dimensions' => ['product'], 'measures' => ['quantity', 'subtotal', 'avg_price'], 'period' => 'this_year', 'limit' => 20, 'chart' => 'table']],
            'flux_tresorerie' => ['name' => 'Encaissements et décaissements', 'folder' => 'Finance', 'source' => 'paiements', 'description' => 'Flux mensuels des 12 derniers mois.',
                'config' => ['dimensions' => ['month'], 'measures' => ['in', 'out', 'net'], 'period' => 'last_12_months', 'chart' => 'bar']],
            'moyens_paiement' => ['name' => 'Encaissements par moyen de paiement', 'folder' => 'Finance', 'source' => 'paiements', 'description' => 'Espèces, Mobile Money, virements… ce mois-ci.',
                'config' => ['dimensions' => ['method'], 'measures' => ['amount'], 'filters' => [['field' => 'direction', 'op' => 'eq', 'value' => 'sale']], 'period' => 'this_month', 'chart' => 'pie']],
            'resultat_mensuel' => ['name' => 'Résultat mensuel', 'folder' => 'Finance', 'source' => 'compta', 'description' => 'Produits, charges et résultat comptables de l\'année.',
                'config' => ['dimensions' => ['month'], 'measures' => ['revenue', 'expense', 'result'], 'period' => 'this_year', 'chart' => 'bar']],
            'principales_charges' => ['name' => 'Principales charges', 'folder' => 'Finance', 'source' => 'compta', 'description' => 'Comptes de charges (classe 6) les plus importants de l\'année.',
                'config' => ['dimensions' => ['account'], 'measures' => ['balance'], 'filters' => [['field' => 'class', 'op' => 'eq', 'value' => '6']], 'period' => 'this_year', 'limit' => 12, 'chart' => 'hbar']],
            'notes_frais_categories' => ['name' => 'Notes de frais par catégorie', 'folder' => 'Finance', 'source' => 'notes_frais', 'description' => 'Dépenses approuvées de l\'année.',
                'config' => ['dimensions' => ['category'], 'measures' => ['amount', 'count'], 'period' => 'this_year', 'chart' => 'pie']],
            'valeur_stock' => ['name' => 'Valeur du stock par catégorie', 'folder' => 'Stock', 'source' => 'stock', 'description' => 'Stock actuel valorisé au prix d\'achat.',
                'config' => ['dimensions' => ['category'], 'measures' => ['quantity', 'value', 'sale_value'], 'period' => 'all', 'chart' => 'table']],
            'masse_salariale' => ['name' => 'Masse salariale mensuelle', 'folder' => 'Ressources humaines', 'source' => 'paie', 'description' => 'Brut, net et coût employeur des 12 derniers mois.',
                'config' => ['dimensions' => ['month'], 'measures' => ['gross', 'net', 'cost'], 'period' => 'last_12_months', 'chart' => 'line']],
            'masse_services' => ['name' => 'Coût salarial par service', 'folder' => 'Ressources humaines', 'source' => 'paie', 'description' => 'Tableau croisé service × mois (coût employeur).',
                'config' => ['dimensions' => ['department', 'month'], 'measures' => ['cost'], 'period' => 'this_year', 'chart' => 'pivot']],
            'effectifs' => ['name' => 'Effectif par service', 'folder' => 'Ressources humaines', 'source' => 'rh', 'description' => 'Salariés en poste.',
                'config' => ['dimensions' => ['department'], 'measures' => ['count'], 'filters' => [['field' => 'active', 'op' => 'eq', 'value' => '1']], 'period' => 'all', 'chart' => 'pie']],
            'temps_projets' => ['name' => 'Temps par projet', 'folder' => 'Projets', 'source' => 'projets', 'description' => 'Heures du mois, part facturable et valeur.',
                'config' => ['dimensions' => ['project'], 'measures' => ['hours', 'billable_hours', 'value'], 'period' => 'this_month', 'chart' => 'table']],
        ];
    }

    /** Tableau de bord installé à l'activation du module (seuls les rapports dont la source est active). */
    public const DEFAULT_BOARD = [
        ['ca_mois', 1], ['reste_encaisser', 1], ['pipeline_crm', 2],
        ['ca_mensuel', 2], ['top_clients', 2],
        ['flux_tresorerie', 2], ['resultat_mensuel', 2],
        ['top_articles', 4],
    ];
}
