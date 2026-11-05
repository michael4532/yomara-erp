<?php

return [
    'key' => 'avis_google',
    'name' => 'Avis Google & fiche d\'établissement',
    'description' => 'Votre fiche Google (Maps et recherche) pilotée depuis le logiciel : avis clients centralisés, réponses rédigées avec l\'assistant IA, alerte immédiate sur un avis négatif, demandes d\'avis envoyées automatiquement par WhatsApp, SMS ou e-mail après une vente, une facture payée, un rendez-vous ou un ticket résolu, QR code et affiche « Notez-nous sur Google », publications (actualités, offres, événements), horaires et informations de la fiche, statistiques de visibilité (vues, appels, itinéraires, clics vers le site) et évolution de la note.',
    'category' => 'Marketing & communication',
    'icon' => 'star_rate',
    'depends' => ['core'],
    'sort' => 84,
    'permissions' => [
        'avis.view' => 'Consulter les avis et les statistiques de la fiche Google',
        'avis.reply' => 'Répondre aux avis',
        'avis.manage' => 'Connecter la fiche, publier, modifier les informations, régler les demandes d\'avis',
    ],
    'sync' => [],
];
