<?php

return [
    'key' => 'whatsapp',
    'name' => 'WhatsApp Business',
    'description' => 'Messagerie WhatsApp de l\'entreprise dans le logiciel : boîte de discussion partagée par l\'équipe (API Cloud de Meta, UltraMsg ou Green API), messages, photos, documents et notes vocales reçus en direct, réponses rapides, modèles approuvés par Meta pour relancer au-delà de 24 h, attribution automatique aux agents, message d\'accueil et d\'absence selon vos horaires, conversations reliées aux fiches clients, lien et QR code « Discuter sur WhatsApp ». Envoi des factures, devis, relances et campagnes.',
    'category' => 'Marketing & communication',
    'icon' => 'chat',
    'depends' => ['core'],
    'sort' => 82,
    'permissions' => [
        'whatsapp.use' => 'Répondre aux conversations WhatsApp (les siennes et celles non attribuées)',
        'whatsapp.manage' => 'Voir toutes les conversations, attribuer, paramétrer WhatsApp (modèles, réponses rapides, horaires)',
    ],
    'sync' => [],
];
