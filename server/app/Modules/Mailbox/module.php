<?php

return [
    'key' => 'mailbox',
    'name' => 'Boîte mail',
    'description' => 'Messagerie intégrée façon Outlook : comptes IMAP / POP3 de votre hébergement, Microsoft 365 (Outlook, Exchange Online) et Gmail réunis dans une boîte unifiée, boîtes partagées, dossiers, conversations, recherche, signatures avec logo, modèles, envoi programmé, pièces jointes depuis les documents, copie locale sur chaque ordinateur pour travailler hors ligne, mails reliés aux fiches clients et transformés en tâches, tickets ou opportunités. Calendriers Outlook et Google synchronisés dans les deux sens avec l\'Agenda, réunions Teams et Google Meet créées automatiquement.',
    'category' => 'Collaboration',
    'icon' => 'mail',
    'depends' => ['core'],
    'sort' => 38,
    'permissions' => [
        'mailbox.use' => 'Utiliser la boîte mail (ses comptes et les boîtes partagées qui lui sont attribuées)',
        'mailbox.manage' => 'Gérer les boîtes partagées, les signatures de la société et les paramètres Microsoft 365 / Google',
    ],
    'sync' => [],
];
