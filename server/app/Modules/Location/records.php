<?php

use App\Modules\Location\LocationHooks as H;

$view = 'location.view';
$manage = 'location.manage';

return [
    'hooks' => H::class,
    'resources' => [
        'rentals' => [
            'table' => 'lo_rentals', 'title_field' => 'number', 'label' => 'Contrat de location', 'plural' => 'Contrats de location', 'icon' => 'key',
            'view' => $view, 'manage' => $manage, 'order' => 'start_at desc', 'date_field' => 'start_at', 'search' => ['number', 'customer_name', 'phone', 'delivery_address', 'notes'],
            'hint' => 'Devis, réservation, sortie, retour, caution et facture.',
            'fields' => [
                ['key' => 'number', 'label' => 'N°', 'readonly' => true, 'list' => true, 'half' => true],
                ['key' => 'status', 'label' => 'Étape', 'type' => 'select', 'default' => 'quote', 'readonly' => true, 'list' => true, 'half' => true, 'filter' => true, 'options' => H::STATUSES],
                ['key' => 'partner_id', 'label' => 'Client', 'type' => 'partner', 'half' => true],
                ['key' => 'customer_name', 'label' => 'Client de passage', 'hint' => 'S\'il n\'est pas dans vos contacts', 'half' => true],
                ['key' => 'customer', 'label' => 'Client', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'phone', 'label' => 'Téléphone', 'half' => true],
                ['key' => 'owner_id', 'label' => 'Suivi par', 'type' => 'user', 'default' => '@me', 'half' => true, 'filter' => true],
                ['key' => 'start_at', 'label' => 'Départ', 'type' => 'datetime', 'required' => true, 'list' => true, 'half' => true],
                ['key' => 'end_at', 'label' => 'Retour prévu', 'type' => 'datetime', 'required' => true, 'list' => true, 'half' => true],
                ['key' => 'period', 'label' => 'Durée', 'type' => 'text', 'computed' => true],
                ['key' => 'alert', 'label' => 'Alerte', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'delivery', 'label' => 'Livraison et reprise', 'type' => 'bool', 'default' => false, 'half' => true],
                ['key' => 'delivery_fee', 'label' => 'Frais de transport', 'type' => 'money', 'half' => true, 'show_if' => ['delivery' => [true]]],
                ['key' => 'delivery_address', 'label' => 'Adresse de livraison', 'show_if' => ['delivery' => [true]]],
                ['key' => 'discount_percent', 'label' => 'Remise (%)', 'type' => 'number', 'half' => true],
                ['key' => 'tax_rate', 'label' => 'TVA (%)', 'type' => 'number', 'default' => 18, 'half' => true],
                ['key' => 'total', 'label' => 'Total HT', 'type' => 'money', 'readonly' => true, 'list' => true, 'half' => true],
                ['key' => 'deposit_amount', 'label' => 'Caution demandée', 'type' => 'money', 'half' => true, 'hint' => 'Calculée d\'après le matériel si vide'],
                ['key' => 'deposit_status', 'label' => 'Caution', 'type' => 'select', 'default' => 'none', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::DEPOSIT_STATUSES],
                ['key' => 'deposit_method', 'label' => 'Caution versée par', 'type' => 'select', 'half' => true, 'options' => H::DEPOSIT_METHODS],
                ['key' => 'deposit_kept', 'label' => 'Retenu sur la caution', 'type' => 'money', 'half' => true],
                ['key' => 'late_fee', 'label' => 'Retard facturé', 'type' => 'money', 'half' => true],
                ['key' => 'damage_fee', 'label' => 'Dégâts / pertes facturés', 'type' => 'money', 'half' => true],
                ['key' => 'out_at', 'label' => 'Sorti le', 'type' => 'datetime', 'readonly' => true, 'half' => true],
                ['key' => 'returned_at', 'label' => 'Rendu le', 'type' => 'datetime', 'readonly' => true, 'half' => true],
                ['key' => 'invoice', 'label' => 'Facture', 'type' => 'text', 'computed' => true],
                ['key' => 'notes', 'label' => 'Notes et conditions', 'type' => 'textarea'],
            ],
        ],
        'lines' => [
            'table' => 'lo_lines', 'label' => 'Matériel loué', 'plural' => 'Matériel du contrat', 'icon' => 'inventory_2',
            'view' => $view, 'manage' => $manage, 'order' => 'rental_id desc,id asc', 'search' => ['condition_out', 'condition_in'],
            'hint' => 'Le prix se calcule d\'après le tarif du matériel et la durée du contrat.',
            'fields' => [
                ['key' => 'rental_id', 'label' => 'Contrat', 'type' => 'ref', 'resource' => 'rentals', 'required' => true, 'list' => true, 'filter' => true],
                ['key' => 'item_id', 'label' => 'Matériel', 'type' => 'ref', 'resource' => 'items', 'required' => true, 'list' => true, 'filter' => true],
                ['key' => 'quantity', 'label' => 'Quantité', 'type' => 'number', 'default' => 1, 'list' => true, 'half' => true],
                ['key' => 'pricing', 'label' => 'Tarif', 'type' => 'select', 'default' => 'day', 'list' => true, 'half' => true, 'options' => H::PRICING],
                ['key' => 'unit_price', 'label' => 'Prix unitaire', 'type' => 'money', 'half' => true, 'hint' => 'Tarif du matériel si vide'],
                ['key' => 'amount', 'label' => 'Montant', 'type' => 'money', 'readonly' => true, 'list' => true, 'half' => true],
                ['key' => 'condition_out', 'label' => 'État au départ', 'half' => true],
                ['key' => 'condition_in', 'label' => 'État au retour', 'list' => true, 'half' => true],
                ['key' => 'damage_fee', 'label' => 'Dégâts / pertes', 'type' => 'money', 'half' => true],
            ],
        ],
        'items' => [
            'table' => 'lo_items', 'label' => 'Matériel', 'plural' => 'Matériel à louer', 'icon' => 'construction',
            'view' => $view, 'manage' => $manage, 'order' => 'category asc,title asc', 'search' => ['title', 'category', 'reference', 'location', 'notes'],
            'hint' => 'Un article par matériel unique (avec son numéro) ou par lot identique (200 chaises).',
            'fields' => [
                ['key' => 'title', 'label' => 'Matériel', 'required' => true, 'list' => true],
                ['key' => 'category', 'label' => 'Catégorie', 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'reference', 'label' => 'Référence / n° de série', 'half' => true],
                ['key' => 'quantity', 'label' => 'Quantité au parc', 'type' => 'number', 'default' => 1, 'list' => true, 'half' => true],
                ['key' => 'status', 'label' => 'État', 'type' => 'select', 'default' => 'available', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::ITEM_STATUSES],
                ['key' => 'availability', 'label' => 'Disponible aujourd\'hui', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'rate_day', 'label' => 'Prix par jour', 'type' => 'money', 'required' => true, 'list' => true, 'half' => true],
                ['key' => 'rate_week', 'label' => 'Prix par semaine', 'type' => 'money', 'half' => true],
                ['key' => 'rate_month', 'label' => 'Prix par mois', 'type' => 'money', 'half' => true],
                ['key' => 'deposit', 'label' => 'Caution par unité', 'type' => 'money', 'half' => true],
                ['key' => 'replacement_value', 'label' => 'Valeur de remplacement', 'type' => 'money', 'half' => true],
                ['key' => 'location', 'label' => 'Dépôt / emplacement', 'half' => true],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],
    ],
];
