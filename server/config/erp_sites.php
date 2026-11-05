<?php

/*
 * Sites vitrines : grille tarifaire du site sur mesure (F CFA HT), utilisée pour l'estimation immédiate
 * affichée au client et pour le devis généré dans l'ERP du prestataire (modifiable avant envoi).
 */
return [
    'base' => ['label' => 'Conception et intégration du site sur mesure (jusqu\'à 5 pages)', 'price' => 150000],
    'included_pages' => 5,
    'extra_page' => ['label' => 'Page supplémentaire', 'price' => 20000],
    'features' => [
        'custom_design' => ['label' => 'Maquette graphique 100 % personnalisée', 'price' => 150000],
        'ecommerce' => ['label' => 'Boutique en ligne (catalogue, panier, commande)', 'price' => 250000],
        'payment' => ['label' => 'Paiement en ligne (Mobile Money, carte)', 'price' => 100000],
        'booking' => ['label' => 'Réservation / prise de rendez-vous en ligne', 'price' => 75000],
        'blog' => ['label' => 'Blog / actualités', 'price' => 60000],
        'multilingual' => ['label' => 'Version anglaise (bilingue)', 'price' => 80000],
        'member_area' => ['label' => 'Espace membres / clients', 'price' => 120000],
        'forms' => ['label' => 'Formulaires avancés (devis, inscription, candidature)', 'price' => 40000],
        'gallery' => ['label' => 'Galerie photos et vidéos', 'price' => 30000],
        'seo' => ['label' => 'Référencement naturel (SEO) de départ', 'price' => 75000],
        'copywriting' => ['label' => 'Rédaction des textes', 'price' => 60000],
        'logo' => ['label' => 'Création du logo', 'price' => 50000],
        'photos' => ['label' => 'Séance photo professionnelle', 'price' => 80000],
        'domain_hosting' => ['label' => 'Nom de domaine et hébergement (1 an)', 'price' => 45000],
        'emails' => ['label' => 'Adresses e-mail professionnelles (5)', 'price' => 30000],
        'training' => ['label' => 'Formation à la gestion du site', 'price' => 25000],
    ],
    'urgent' => ['label' => 'Livraison express (moins de 2 semaines)', 'percent' => 25],
    'tax_rate' => 18,
    'quote_validity_days' => 30,
];
