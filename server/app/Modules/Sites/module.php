<?php

return [
    'key' => 'sites',
    'name' => 'Site vitrine',
    'description' => 'Votre site internet relié à l\'ERP : galerie de 54 modèles par secteur, personnalisation (couleurs, textes, photos, sections), produits, événements, rendez-vous et offres d\'emploi affichés en direct, formulaire de contact versé dans le CRM et le Marketing, boutique en ligne (panier, livraison, paiement en ligne ou à la livraison, commandes dans les Ventes), blog, statistiques de visites, sous-domaine offert ou nom de domaine personnel vérifié par DNS ; demande de site sur mesure avec estimation immédiate et devis.',
    'category' => 'Marketing & communication',
    'icon' => 'language',
    'depends' => ['core'],
    'sort' => 49,
    'setup' => \App\Modules\Sites\SitesSetup::class,
    'provider' => \App\Modules\Sites\SitesModuleProvider::class,
    'setup_version' => 1,
    'permissions' => [
        'sites.view' => 'Consulter le site, ses statistiques et les messages reçus',
        'sites.manage' => 'Personnaliser et publier le site, gérer le domaine, demander un site ou accepter un devis',
    ],
];
