<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mode de fonctionnement
    |--------------------------------------------------------------------------
    | single : une seule société (installation chez le client, licence).
    | saas   : plateforme multi-sociétés (inscriptions, plans, abonnements).
    */
    'mode' => env('ERP_MODE', 'single'),

    'name' => env('ERP_NAME', 'Yomara ERP'),

    'version' => '0.99.38',

    // Fiches officielles des applications (proposées dans Administration › Boutique › Liens de téléchargement)
    'store_links' => [
        'android_store' => env('ERP_STORE_PLAY', 'https://play.google.com/store/apps/details?id=com.yomaratechnologies.yomara_erp'),
        'ios' => env('ERP_STORE_APPLE', 'https://apps.apple.com/app/id6831012647'),
        'mac_store' => env('ERP_STORE_MAC', 'https://apps.apple.com/app/id6831012647'),
        'windows_store' => env('ERP_STORE_MICROSOFT', 'https://apps.microsoft.com/detail/9P92L2X189ZD'),
    ],
    // Dépôt public du code source (édition libre AGPL), utilisé si l'administration n'en indique pas
    'source_repository' => env('ERP_SOURCE_REPOSITORY'),

    // Délai (secondes) pendant lequel un utilisateur est considéré "en ligne"
    // après sa dernière requête / son dernier heartbeat.
    'presence_window' => (int) env('ERP_PRESENCE_WINDOW', 120),
    // Domaine court des liens (yma.ci/abc12), à pointer vers ce serveur ; vide : https://domaine-du-logiciel/l/abc12
    // Notifications push (Firebase Cloud Messaging) : clé JSON du compte de service du projet Firebase
    'push' => [
        'fcm_service_account' => env('FCM_SERVICE_ACCOUNT'),
    ],
    // Connexion des boîtes mail par Google (Gmail, Workspace) et Microsoft (Outlook, 365) : désactivables par le super admin
    'mailbox_oauth' => [
        'google' => filter_var(env('MAILBOX_GOOGLE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'microsoft' => filter_var(env('MAILBOX_MICROSOFT_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],
    // Publicité en ligne : Google Ads (jeton développeur de la plateforme) et version de l'API Graph de Meta
    'ads' => [
        'google_developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'google_login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        'google_version' => env('GOOGLE_ADS_API_VERSION', 'v21'),
        'meta_version' => env('META_GRAPH_VERSION', 'v21.0'),
    ],
    // « Se connecter avec Google » (facultatif) : seulement pour un compte qui existe déjà avec la même adresse
    'google_signin' => [
        'enabled' => filter_var(env('GOOGLE_SIGNIN_ENABLED', true), FILTER_VALIDATE_BOOL),
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_SIGNIN_CLIENT_IDS',
            '400573441933-pp4h4gfg4dqjrkj0oc90mdu865lbimv9.apps.googleusercontent.com,400573441933-nkgqpohhleqe73566n09hg34e9krqmdo.apps.googleusercontent.com'))))),
    ],
    'links_domain' => strtolower(trim((string) env('LINKS_DOMAIN', ''), " /")),

    // Fréquence max d'écriture de last_seen_at (évite une écriture par requête).
    'presence_write_every' => (int) env('ERP_PRESENCE_WRITE_EVERY', 30),

    // Assistant IA : fournisseur par défaut de la plateforme (chaque société peut saisir sa propre clé)
    'ai' => [
        'provider' => env('AI_PROVIDER', 'anthropic'),     // anthropic | openai (ou compatible : Mistral, DeepSeek, Ollama…)
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL'),
        'base_url' => env('AI_BASE_URL'),
        'monthly_limit' => (int) env('AI_MONTHLY_LIMIT', 300), // questions par société et par mois avec la clé de la plateforme
    ],

    // Synchronisation hors ligne
    'sync' => [
        'max_push' => 500,   // mutations max par envoi
        'page_size' => 500,  // enregistrements max par page de pull
    ],

    // Valeurs par défaut d'une nouvelle société (Côte d'Ivoire)
    'defaults' => [
        'currency' => env('ERP_CURRENCY', 'XOF'),
        'country' => env('ERP_COUNTRY', 'CI'),
        'tax_rate' => 18,
        'timezone' => 'Africa/Abidjan',
        'locale' => 'fr',
    ],

    // SaaS : durée d'essai pour une nouvelle inscription
    'trial_days' => (int) env('ERP_TRIAL_DAYS', 14),

    // Mises à jour à distance : serveur de diffusion (la plateforme Yomara) et canal suivi
    'updates' => [
        'server' => env('ERP_UPDATE_SERVER', 'https://erp.yomaratechnologies.com'),
        'channel' => env('ERP_UPDATE_CHANNEL', 'stable'), // stable | beta
        // Cette installation diffuse les mises à jour (plateforme) : par défaut en mode SaaS
        'publisher' => (bool) env('ERP_UPDATE_PUBLISHER', env('ERP_MODE', 'single') === 'saas'),
        'check_every' => (int) env('ERP_UPDATE_CHECK_MINUTES', 180),
        // Installations (édition libre) : installation automatique de la version stable la nuit (sauvegarde + retour arrière)
        'auto_install' => (bool) env('ERP_UPDATE_AUTO_INSTALL', true),
        'auto_install_at' => env('ERP_UPDATE_AUTO_INSTALL_AT', '02:40'),
        // Plateforme : chaque nouvelle version appliquée ici est publiée toute seule pour les installations
        'auto_publish' => (bool) env('ERP_UPDATE_AUTO_PUBLISH', true),
    ],

    // Visioconférence (module « visio ») : comptes ZEGOCLOUD et Agora de la plateforme, utilisés par les sociétés
    // qui n'ont pas saisi les leurs. Les secrets restent sur le serveur (jetons générés à la demande).
    'visio' => [
        'zego_app_id' => env('ZEGO_APP_ID'),
        'zego_secret' => env('ZEGO_SERVER_SECRET'),
        'zego_server' => env('ZEGO_SERVER'),
        'agora_app_id' => env('AGORA_APP_ID'),
        'agora_certificate' => env('AGORA_APP_CERTIFICATE'),
    ],

    // Synchronisation bancaire : GoCardless Bank Account Data (banques européennes, gratuit) et domaine de réception
    // des relevés par e-mail (adresse releves+jeton@domaine transférée vers /api/bank-inbound/{jeton}).
    // Boîte mail : application Microsoft 365 (Azure) et Google Cloud de la plateforme, utilisées si la société n'a pas la sienne.
    // Adresses de retour à déclarer : APP_URL/oauth/microsoft/retour et APP_URL/oauth/google/retour
    // Avis Google : clé API Google Maps (API Places) de la plateforme, pour le mode lecture seule des avis
    'google' => ['places_api_key' => env('GOOGLE_PLACES_API_KEY')],
    'backups' => ['dropbox_key' => env('DROPBOX_APP_KEY'), 'dropbox_secret' => env('DROPBOX_APP_SECRET')],

    // Application mobile : liens des boutiques (page /application-mobile, QR code affiché après l'ajout d'une boîte mail)
    'mobile' => ['android_url' => env('MOBILE_ANDROID_URL'), 'ios_url' => env('MOBILE_IOS_URL')],

    'mailbox' => [
        'microsoft_client_id' => env('MICROSOFT_CLIENT_ID'),
        'microsoft_client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'microsoft_tenant' => env('MICROSOFT_TENANT', 'common'),
        'google_client_id' => env('GOOGLE_CLIENT_ID'),
        'google_client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'verify_tls' => env('MAILBOX_VERIFY_TLS', true),
        // SaaS : serveurs de messagerie du réseau interne refusés (sauf réglage explicite, ex. serveur de test local)
        'allow_private_hosts' => (bool) env('MAILBOX_ALLOW_PRIVATE_HOSTS', false),
        // Adresses fournies par Yomara (module Messagerie hébergée) : boîtes créées sur le cPanel de la plateforme.
        'hosted' => [
            'cpanel_host' => env('MAILBOX_CPANEL_HOST'),
            'cpanel_port' => env('MAILBOX_CPANEL_PORT', 2083),
            'cpanel_user' => env('MAILBOX_CPANEL_USER'),
            'cpanel_token' => env('MAILBOX_CPANEL_TOKEN'),
            'domain' => env('MAILBOX_HOSTED_DOMAIN'),
            'imap_host' => env('MAILBOX_HOSTED_IMAP_HOST'),
            'smtp_host' => env('MAILBOX_HOSTED_SMTP_HOST'),
            'quota_mb' => env('MAILBOX_HOSTED_QUOTA_MB', 2048),
            'max_per_company' => env('MAILBOX_HOSTED_MAX', 10),
            'verify_tls' => env('MAILBOX_CPANEL_VERIFY_TLS', true),
        ],
        // Protection anti-spam : plafonds d'envoi par compte
        'limits' => [
            'hosted' => ['per_hour' => env('MAILBOX_HOSTED_PER_HOUR', 60), 'per_day' => env('MAILBOX_HOSTED_PER_DAY', 300), 'recipients' => env('MAILBOX_HOSTED_RECIPIENTS', 30)],
            'own' => ['per_hour' => env('MAILBOX_OWN_PER_HOUR', 200), 'per_day' => env('MAILBOX_OWN_PER_DAY', 1500), 'recipients' => env('MAILBOX_OWN_RECIPIENTS', 100)],
        ],
    ],

    'bank' => [
        'gocardless_secret_id' => env('GOCARDLESS_SECRET_ID'),
        'gocardless_secret_key' => env('GOCARDLESS_SECRET_KEY'),
        'inbound_domain' => env('ERP_BANK_INBOUND_DOMAIN'),
    ],

    // Licence (mode single) : clé générée depuis « Mes licences » sur la plateforme, liée à cet appareil
    'license_key' => env('ERP_LICENSE_KEY'),
    'license_server' => env('ERP_LICENSE_SERVER', 'https://erp.yomaratechnologies.com'),
    // Installation unique : modules limités à ceux de la licence (les installations antérieures à 0.98 sans clé restent ouvertes)
    'license_enforce' => (bool) env('ERP_LICENSE_ENFORCE', true),
    // Le mode plateforme SaaS (vente aux entreprises) est réservé à l'éditeur : clé demandée par l'assistant d'installation
    'editor_key_hash' => env('ERP_EDITOR_KEY_HASH', 'ee2474b6d73c7199ba81c8a1b6be8670afb98440c2cc22ed4797b0812cc1e4d6'),
    // Clé publique officielle de Yomara : une installation n'accepte que les licences signées par l'éditeur
    'license_public_key' => env('ERP_LICENSE_PUBLIC_KEY', 'UtX5PjTTImcGBeuhYNsxRt5M16eladuTfON+zzjeJYc='),
    // Clé privée de signature, chiffrée : seule la clé éditeur la déverrouille (installation de la plateforme)
    'license_signing_sealed' => 'yFI93+IHxq+SXtkWAhw4Px2ptVdwPJu6ekKVklJM0CUIMYYXG5/nXayxd1JvMX0XXokWm4c7UYj4wi7537ovT/e/e4EhTa4dZqh/8fkeENqz8XSszjmJO/cAlce4Aae5lM2miqjBDlY=',
];
