<?php

use App\Modules\Juridique\JuridiqueHooks as H;

$view = 'juridique.view';
$manage = 'juridique.manage';
$owner = ['key' => 'owner_id', 'label' => 'Responsable', 'type' => 'user', 'default' => '@me', 'half' => true, 'filter' => true];

return [
    'hooks' => H::class,
    'resources' => [
        'contracts' => [
            'table' => 'jr_contracts', 'label' => 'Contrat', 'plural' => 'Registre des contrats', 'icon' => 'description',
            'view' => $view, 'manage' => $manage, 'order' => 'id desc', 'date_field' => 'end_date', 'search' => ['title', 'reference', 'counterparty', 'body'],
            'hint' => 'Contrats clients, fournisseurs, baux, partenariats, confidentialité, travail : échéances, préavis et renouvellements suivis.',
            'fields' => [
                ['key' => 'title', 'label' => 'Objet du contrat', 'required' => true, 'list' => true],
                ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'default' => 'service', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::KINDS],
                ['key' => 'status', 'label' => 'Statut', 'type' => 'select', 'default' => 'draft', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::CONTRACT_STATUSES],
                ['key' => 'partner_id', 'label' => 'Client / fournisseur', 'type' => 'partner', 'half' => true],
                ['key' => 'employee_id', 'label' => 'Salarié', 'type' => 'employee', 'half' => true, 'show_if' => ['kind' => ['employment']], 'hint' => 'Il retrouve son contrat dans « Mon espace » dès qu\'il est « À signer » ou « En vigueur ».'],
                ['key' => 'counterparty', 'label' => 'Autre partie', 'hint' => 'Si elle n\'est pas dans vos contacts', 'list' => true],
                ['key' => 'reference', 'label' => 'Référence', 'half' => true],
                ['key' => 'signed_on', 'label' => 'Signé le', 'type' => 'date', 'half' => true],
                ['key' => 'start_date', 'label' => 'Prise d\'effet', 'type' => 'date', 'half' => true],
                ['key' => 'end_date', 'label' => 'Échéance', 'type' => 'date', 'list' => true, 'half' => true],
                ['key' => 'tacit_renewal', 'label' => 'Renouvellement par tacite reconduction', 'type' => 'bool', 'half' => true],
                ['key' => 'notice_days', 'label' => 'Préavis (jours)', 'type' => 'number', 'half' => true],
                ['key' => 'alert', 'label' => 'Alerte', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'amount', 'label' => 'Montant', 'type' => 'money', 'list' => true, 'half' => true],
                ['key' => 'periodicity', 'label' => 'Périodicité', 'type' => 'select', 'half' => true, 'options' => H::PERIODICITY],
                $owner,
                ['key' => 'file_url', 'label' => 'Contrat signé (lien GED ou fichier)', 'type' => 'url', 'half' => true],
                ['key' => 'body', 'label' => 'Texte du contrat', 'type' => 'textarea', 'hint' => 'Rédigez-le ou partez d\'un modèle (bouton « Rédiger depuis un modèle »)'],
                ['key' => 'notes', 'label' => 'Remarques', 'type' => 'textarea'],
            ],
        ],
        'templates' => [
            'table' => 'jr_templates', 'label' => 'Modèle', 'plural' => 'Modèles de contrats', 'icon' => 'article',
            'view' => $view, 'manage' => $manage, 'order' => 'title asc', 'search' => ['title', 'body'],
            'hint' => 'Variables : {societe}, {societe_adresse}, {societe_rccm}, {representant}, {tiers}, {tiers_adresse}, {objet}, {date_debut}, {date_fin}, {montant}, {periodicite}, {preavis}, {date}, {ville} ; pour un salarié : {nom_complet}, {fonction}, {salaire_base}, {date_embauche}…',
            'fields' => [
                ['key' => 'title', 'label' => 'Nom du modèle', 'required' => true, 'list' => true],
                ['key' => 'kind', 'label' => 'Type de contrat', 'type' => 'select', 'default' => 'service', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::KINDS],
                ['key' => 'active', 'label' => 'Actif', 'type' => 'bool', 'default' => true, 'list' => true, 'half' => true],
                ['key' => 'body', 'label' => 'Texte', 'type' => 'textarea', 'required' => true],
            ],
        ],
        'cases' => [
            'table' => 'jr_cases', 'label' => 'Dossier', 'plural' => 'Contentieux & dossiers', 'icon' => 'gavel',
            'view' => $view, 'manage' => $manage, 'order' => 'id desc', 'search' => ['title', 'reference', 'opposing_party', 'court', 'summary'],
            'hint' => 'Litiges commerciaux, sociaux, recouvrements, procédures : juridiction, avocat, enjeu, risque et audiences.',
            'fields' => [
                ['key' => 'title', 'label' => 'Intitulé', 'required' => true, 'list' => true],
                ['key' => 'kind', 'label' => 'Nature', 'type' => 'select', 'default' => 'commercial', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::CASE_KINDS],
                ['key' => 'status', 'label' => 'Étape', 'type' => 'select', 'default' => 'open', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::CASE_STATUSES],
                ['key' => 'role', 'label' => 'Notre position', 'type' => 'select', 'default' => 'plaintiff', 'half' => true, 'options' => H::ROLES],
                ['key' => 'risk', 'label' => 'Risque', 'type' => 'select', 'default' => 'medium', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::RISKS],
                ['key' => 'opposing_party', 'label' => 'Partie adverse', 'list' => true, 'half' => true],
                ['key' => 'partner_id', 'label' => 'Tiers concerné', 'type' => 'partner', 'half' => true],
                ['key' => 'employee_id', 'label' => 'Salarié concerné', 'type' => 'employee', 'half' => true, 'show_if' => ['kind' => ['labour']]],
                ['key' => 'court', 'label' => 'Juridiction', 'half' => true],
                ['key' => 'lawyer', 'label' => 'Avocat / huissier', 'half' => true],
                ['key' => 'reference', 'label' => 'N° de rôle / référence', 'half' => true],
                ['key' => 'opened_on', 'label' => 'Ouvert le', 'type' => 'date', 'default' => '@today', 'half' => true],
                ['key' => 'next_deadline', 'label' => 'Prochaine échéance', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'amount_at_stake', 'label' => 'Montant en jeu', 'type' => 'money', 'list' => true, 'half' => true],
                ['key' => 'costs', 'label' => 'Frais engagés', 'type' => 'money', 'half' => true],
                $owner,
                ['key' => 'summary', 'label' => 'Résumé des faits', 'type' => 'textarea'],
                ['key' => 'outcome', 'label' => 'Décision / issue', 'type' => 'textarea'],
            ],
        ],
        'deadlines' => [
            'table' => 'jr_deadlines', 'label' => 'Échéance', 'plural' => 'Audiences & échéances', 'icon' => 'event',
            'view' => $view, 'manage' => $manage, 'order' => 'done asc,due_at asc', 'date_field' => 'due_at', 'search' => ['title', 'place', 'notes'],
            'hint' => 'Rappel au responsable 3 jours avant, puis la veille.',
            'fields' => [
                ['key' => 'title', 'label' => 'Échéance', 'required' => true, 'list' => true],
                ['key' => 'due_at', 'label' => 'Date et heure', 'type' => 'datetime', 'required' => true, 'list' => true, 'half' => true],
                ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'default' => 'hearing', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::DEADLINE_KINDS],
                ['key' => 'case_id', 'label' => 'Dossier', 'type' => 'ref', 'resource' => 'cases', 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'contract_id', 'label' => 'Contrat', 'type' => 'ref', 'resource' => 'contracts', 'half' => true, 'filter' => true],
                ['key' => 'place', 'label' => 'Lieu', 'half' => true],
                ['key' => 'done', 'label' => 'Fait', 'type' => 'bool', 'default' => false, 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'owner_id', 'label' => 'Responsable', 'type' => 'user', 'default' => '@me', 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
        ],
    ],
];
