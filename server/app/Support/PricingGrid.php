<?php

namespace App\Support;

/** Formules commerciales de Yomara ERP (prix mensuels en F CFA, annuel = 10 mois). */
class PricingGrid
{
    /** Formule gratuite : les fonctions de base d'un ERP, sans limite de durée. */
    public const FREE = ['ventes', 'achats', 'stock', 'crm', 'migration'];

    public const STARTER = ['ventes', 'achats', 'stock', 'crm', 'migration', 'pos', 'tresorerie', 'fne', 'relances', 'etiquettes', 'import_export', 'notes_frais'];

    public const BUSINESS = ['fidelite', 'lots', 'tarifs', 'variantes', 'documents', 'approbations', 'projets',
        'rendez_vous', 'portail', 'abonnements', 'agenda', 'tickets', 'mailbox'];

    public const PROFESSIONAL = ['compta', 'rh', 'paie', 'pointage', 'recrutement', 'immobilisations', 'analytique', 'devises', 'rapports', 'signature', 'champs',
        'tableaux_excel', 'helpdesk', 'marketing', 'feuilles_temps', 'restaurant', 'sites', 'ecommerce', 'logistique', 'interventions', 'planning', 'prestations', 'appro', 'fonds', 'sms', 'whatsapp', 'avis_google', 'woocommerce', 'shopify'];

    /** Modules premium : inclus dans Enterprise, vendus à part dans les autres formules. */
    public const PREMIUM = ['agences', 'consolidation', 'production', 'evenements', 'billetterie', 'portail_fournisseurs', 'mail_server', 'hebergement', 'immobilier', 'btp', 'visio'];

    /** Paliers de prix des modules vendus à l'unité (F CFA par mois, annuel = 10 mois). */
    public const TIERS = [
        'essentiel' => ['label' => 'Essentiel', 'price' => 5000],
        'standard' => ['label' => 'Standard', 'price' => 30000],
        'premium' => ['label' => 'Premium', 'price' => 25000],
    ];

    /** Modules qui coûtent à la plateforme (envois, stockage, API payantes) : palier Standard. Tous les autres : Essentiel. */
    public const TIER_STANDARD = ['sms', 'whatsapp', 'marketing', 'mail_server', 'sites', 'ecommerce', 'documents', 'signature', 'mailbox', 'avis_google', 'social', 'veille', 'acquisition'];

    /** Modules les plus coûteux à faire tourner (IA, audio et vidéo en direct) : palier Premium. */
    public const TIER_PREMIUM = ['visio', 'ia'];

    /**
     * Packs par département : les modules d'un métier achetés ensemble, avec une remise.
     * Le prix tient compte des modules que la société a déjà par sa formule.
     */
    public const PACKS = [
        'rh' => ['name' => 'Pack RH complet', 'icon' => 'badge', 'discount' => 25,
            'description' => 'Salariés, contrats et congés, présence, recrutement, entretiens et formations, paie multi-pays, notes de frais.',
            'modules' => ['rh', 'pointage', 'recrutement', 'talents', 'paie', 'notes_frais']],
        'commerce' => ['name' => 'Pack Commerce', 'icon' => 'storefront', 'discount' => 25,
            'description' => 'Devis et factures, CRM, relances, fidélité, portail client, facture normalisée, abonnements.',
            'modules' => ['ventes', 'crm', 'relances', 'fidelite', 'portail', 'fne', 'abonnements']],
        'stocks' => ['name' => 'Pack Achats & stocks', 'icon' => 'inventory', 'discount' => 25,
            'description' => 'Achats et fournisseurs, stock et inventaires, lots et péremption, tarifs, variantes, étiquettes, importations.',
            'modules' => ['achats', 'stock', 'lots', 'tarifs', 'variantes', 'etiquettes', 'appro']],
        'finance' => ['name' => 'Pack Finance', 'icon' => 'account_balance', 'discount' => 25,
            'description' => 'Comptabilité, banque et trésorerie, immobilisations, analytique et budgets, multi-devises, rapports.',
            'modules' => ['compta', 'tresorerie', 'immobilisations', 'analytique', 'devises', 'rapports']],
        'operations' => ['name' => 'Pack Opérations', 'icon' => 'engineering', 'discount' => 25,
            'description' => 'Projets et feuilles de temps, planning, interventions terrain, sorties de matériel, maintenance, parc auto, service client.',
            'modules' => ['projets', 'feuilles_temps', 'planning', 'interventions', 'materiel', 'maintenance', 'flotte', 'helpdesk']],
        'marketing' => ['name' => 'Pack Marketing', 'icon' => 'campaign', 'discount' => 25,
            'description' => 'E-mailing et campagnes, SMS, WhatsApp, site vitrine, stratégie de contenus, réseaux sociaux, sondages, avis Google, veille concurrentielle, publicité et acquisition.',
            'modules' => ['marketing', 'sms', 'whatsapp', 'sites', 'contenus', 'social', 'liens', 'questionnaires', 'avis_google', 'veille', 'acquisition']],
        'restaurant' => ['name' => 'Pack Restaurant', 'icon' => 'restaurant', 'discount' => 20,
            'description' => 'Caisse, salle et cuisine, stock des ingrédients, fidélité des clients.',
            'modules' => ['ventes', 'stock', 'pos', 'restaurant', 'fidelite']],
    ];

    public static function tierOf(string $key, ?float $price = null): ?string
    {
        if ($price !== null) {
            foreach (self::TIERS as $code => $t) {
                if ((float) $t['price'] === $price) {
                    return $code;
                }
            }

            return $price > 0 ? null : 'free';
        }

        return in_array($key, self::TIER_PREMIUM, true) ? 'premium' : (in_array($key, self::TIER_STANDARD, true) ? 'standard' : 'essentiel');
    }

    public static function plans(): array
    {
        $all = array_keys(config('erp_catalog.modules'));
        $business = array_merge(self::STARTER, self::BUSINESS);
        $pro = array_merge($business, self::PROFESSIONAL);

        return [
            ['code' => 'gratuit', 'name' => 'Gratuit', 'price_monthly' => 0, 'price_yearly' => 0, 'max_users' => null, 'sort' => 0, 'is_active' => true, 'is_free' => true,
                'description' => 'Les fonctions de base d\'un ERP : ventes et factures, achats, stock et clients, sans limite de durée.', 'modules' => self::FREE],
            ['code' => 'starter', 'name' => 'Starter', 'price_monthly' => 15000, 'price_yearly' => 150000, 'max_users' => null, 'sort' => 1, 'is_active' => true,
                'description' => 'Commerces et TPE : la base, plus la caisse, la trésorerie, la facture normalisée FNE, les relances et les étiquettes.', 'modules' => self::STARTER],
            ['code' => 'business', 'name' => 'Business', 'price_monthly' => 35000, 'price_yearly' => 350000, 'max_users' => null, 'sort' => 2, 'is_active' => true,
                'description' => 'PME : Starter, plus fidélité, lots et tarifs, documents, validations, projets, rendez-vous, portail client et messagerie.', 'modules' => $business],
            ['code' => 'professional', 'name' => 'Professional', 'price_monthly' => 75000, 'price_yearly' => 750000, 'max_users' => null, 'sort' => 3, 'is_active' => true,
                'description' => 'PME structurées : Business + comptabilité, RH, paie, pointage, rapports, marketing, service client, logistique.', 'modules' => $pro],
            ['code' => 'entreprise', 'name' => 'Enterprise', 'price_monthly' => 150000, 'price_yearly' => 1500000, 'max_users' => null, 'sort' => 4, 'is_active' => true,
                'description' => 'Grandes entreprises : tous les modules, dont multi-agences, consolidation et fabrication, utilisateurs illimités.', 'modules' => $all],
            ['code' => 'sur_mesure', 'name' => 'Sur mesure', 'price_monthly' => 0, 'price_yearly' => 0, 'max_users' => null, 'sort' => 5, 'is_active' => false,
                'description' => 'Groupes, industries, institutions : modules, intégrations et accompagnement sur devis.', 'modules' => $all],
        ];
    }
}
