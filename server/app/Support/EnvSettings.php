<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Réglages du serveur modifiables depuis l'application (Super admin › Configuration du serveur)
 * au lieu du fichier .env. Une valeur enregistrée ici remplace celle du .env ; vide = valeur du .env.
 * Restent dans le .env : base de données, clé de l'application, mode (SaaS / installation unique), pilotes de session et de cache.
 */
class EnvSettings
{
    public const CACHE = 'erp-env-settings';

    /** Groupes → [clé, libellé, type, options|null, aide|null] ; type : text, secret, bool, number, select, url, email. */
    public static function groups(): array
    {
        $tz = ['Africa/Abidjan' => 'Abidjan (GMT)', 'Africa/Dakar' => 'Dakar (GMT)', 'Africa/Lagos' => 'Lagos, Douala, Kinshasa (GMT+1)', 'Africa/Casablanca' => 'Casablanca',
            'Europe/Paris' => 'Paris (GMT+1/+2)', 'Europe/London' => 'Londres', 'UTC' => 'UTC'];

        return [
            'Application' => [
                ['APP_NAME', 'Nom de l\'application', 'text', null, 'Affiché dans les e-mails et le navigateur'],
                ['APP_URL', 'Adresse publique', 'url', null, 'https://erp.votredomaine.com — liens des e-mails, portails, paiements et questionnaires'],
                ['APP_TIMEZONE', 'Fuseau horaire du serveur', 'select', $tz, null],
                ['APP_LOCALE', 'Langue par défaut', 'select', ['fr' => 'Français', 'en' => 'English'], null],
                ['APP_DEBUG', 'Mode débogage (jamais en production)', 'bool', null, 'Affiche le détail des erreurs : à n\'activer que le temps d\'un diagnostic'],
                ['LOG_LEVEL', 'Niveau du journal', 'select', ['error' => 'Erreurs seulement (recommandé)', 'warning' => 'Avertissements', 'info' => 'Informations', 'debug' => 'Tout (diagnostic)'], null],
                ['LOG_DAILY_DAYS', 'Jours de journal conservés', 'number', null, null],
            ],
            'Plateforme' => [
                ['ERP_NAME', 'Nom de la plateforme', 'text', null, null],
                ['ERP_CURRENCY', 'Devise par défaut des nouvelles sociétés', 'select', ['XOF' => 'F CFA (XOF)', 'XAF' => 'F CFA (XAF)', 'GNF' => 'Franc guinéen', 'NGN' => 'Naira', 'GHS' => 'Cedi', 'EUR' => 'Euro', 'USD' => 'Dollar'], null],
                ['ERP_COUNTRY', 'Pays par défaut (code ISO)', 'text', null, 'CI, SN, BF, ML, CM…'],
                ['ERP_TRIAL_DAYS', 'Jours d\'essai gratuit (SaaS)', 'number', null, null],
                ['ERP_PRESENCE_WINDOW', 'Délai avant « hors ligne » (secondes)', 'number', null, 'Personnes connectées en temps réel'],
            ],
            'Liens courts' => [
                ['LINKS_DOMAIN', 'Domaine des liens courts', 'text', null, 'Ex. yma.ci : un nom de domaine court pointé vers ce serveur. Les liens deviennent yma.ci/abc12 et ne montrent ni l\'adresse du logiciel ni la destination.'],
            ],
            'Notifications push et connexions externes' => [
                ['FCM_SERVICE_ACCOUNT', 'Firebase : clé du compte de service (JSON)', 'secret', null, 'Console Firebase › Paramètres du projet › Comptes de service › Générer une nouvelle clé privée : collez tout le contenu du fichier .json. Active les notifications sur les téléphones.'],
                ['GOOGLE_SIGNIN_ENABLED', 'Autoriser « Se connecter avec Google »', 'bool', null, 'Facultatif : la connexion par e-mail et mot de passe reste disponible. Seuls les comptes déjà créés avec la même adresse peuvent se connecter.'],
                ['MAILBOX_GOOGLE_ENABLED', 'Autoriser la connexion des boîtes Gmail / Google Workspace', 'bool', null, 'Désactivé : le bouton « Gmail » disparaît de la Boîte mail ; les comptes déjà reliés ne sont plus relevés. La connexion IMAP classique reste possible.'],
                ['MAILBOX_MICROSOFT_ENABLED', 'Autoriser la connexion des boîtes Outlook / Microsoft 365', 'bool', null, 'Désactivé : le bouton « Outlook » disparaît de la Boîte mail ; les comptes déjà reliés ne sont plus relevés.'],
                ['GOOGLE_SIGNIN_CLIENT_IDS', 'ID clients Google acceptés', 'text', null, 'Séparés par des virgules ; par défaut ceux de l\'application Yomara ERP'],
            ],
            'Publicité en ligne' => [
                ['GOOGLE_ADS_DEVELOPER_TOKEN', 'Google Ads : jeton développeur', 'secret', null, 'Google Ads › Outils › Centre API. Permet aux sociétés de relier leurs comptes Google Ads (dépenses, clics, conversions). L\'application Google utilisée est celle des Réseaux sociaux.'],
                ['GOOGLE_ADS_LOGIN_CUSTOMER_ID', 'Google Ads : compte administrateur (MCC)', 'text', null, 'Facultatif : numéro du compte administrateur sans tirets, si les comptes des clients y sont rattachés.'],
                ['GOOGLE_ADS_API_VERSION', 'Google Ads : version de l\'API', 'text', null, 'Par défaut v21'],
                ['META_GRAPH_VERSION', 'Meta : version de l\'API Graph', 'text', null, 'Par défaut v21.0'],
            ],
            'Mises à jour' => [
                ['ERP_UPDATE_SERVER', 'Serveur de mises à jour', 'url', null, 'https://erp.yomaratechnologies.com'],
                ['ERP_UPDATE_CHANNEL', 'Canal', 'select', ['stable' => 'Stable', 'beta' => 'Bêta'], null],
                ['ERP_UPDATE_PUBLISHER', 'Ce serveur diffuse les mises à jour', 'bool', null, 'Activez-le seulement sur la plateforme principale'],
                ['ERP_UPDATE_CHECK_MINUTES', 'Vérification toutes les (minutes)', 'number', null, null],
                ['ERP_UPDATE_AUTO_INSTALL', 'Installer les mises à jour automatiquement la nuit', 'bool', null, 'Installation chez le client : sauvegarde puis retour arrière automatique en cas de problème'],
                ['ERP_UPDATE_AUTO_INSTALL_AT', 'Heure de l\'installation automatique', 'text', null, 'Ex. 02:40'],
                ['ERP_UPDATE_AUTO_PUBLISH', 'Publier automatiquement chaque nouvelle version', 'bool', null, 'Plateforme : la version appliquée ici est proposée aux installations, sans clic'],
            ],
            'E-mail d\'envoi' => [
                ['MAIL_MAILER', 'Méthode d\'envoi', 'select', ['smtp' => 'Serveur SMTP', 'sendmail' => 'Sendmail du serveur', 'log' => 'Aucun envoi (journal seulement)'], null],
                ['MAIL_HOST', 'Serveur SMTP', 'text', null, 'mail.votredomaine.com'],
                ['MAIL_PORT', 'Port', 'number', null, '465 (SSL) ou 587 (TLS)'],
                ['MAIL_SCHEME', 'Chiffrement', 'select', ['smtps' => 'SSL (port 465)', 'smtp' => 'TLS / STARTTLS (port 587)'], null],
                ['MAIL_USERNAME', 'Identifiant', 'text', null, null],
                ['MAIL_PASSWORD', 'Mot de passe', 'secret', null, null],
                ['MAIL_FROM_ADDRESS', 'Adresse d\'expédition', 'email', null, 'De préférence sur votre domaine (SPF et DKIM activés)'],
                ['MAIL_FROM_NAME', 'Nom de l\'expéditeur', 'text', null, null],
            ],
            'Assistant IA' => [
                ['AI_PROVIDER', 'Fournisseur', 'select', ['anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI ou compatible (Mistral, DeepSeek, Ollama…)'], null],
                ['AI_API_KEY', 'Clé API de la plateforme', 'secret', null, 'Utilisée par les sociétés qui n\'ont pas leur propre clé'],
                ['AI_MODEL', 'Modèle', 'text', null, 'Vide : modèle par défaut du fournisseur'],
                ['AI_BASE_URL', 'Adresse de l\'API (compatible OpenAI)', 'url', null, null],
                ['AI_MONTHLY_LIMIT', 'Questions par société et par mois', 'number', null, null],
            ],
            'Réunions vidéo' => [
                ['ZEGO_APP_ID', 'ZEGOCLOUD App ID', 'text', null, null],
                ['ZEGO_SERVER_SECRET', 'ZEGOCLOUD Server Secret', 'secret', null, null],
                ['ZEGO_SERVER', 'ZEGOCLOUD serveur', 'text', null, null],
                ['AGORA_APP_ID', 'Agora App ID', 'text', null, null],
                ['AGORA_APP_CERTIFICATE', 'Agora App Certificate', 'secret', null, null],
            ],
            'Boîtes mail Microsoft et Google' => [
                ['MICROSOFT_CLIENT_ID', 'Microsoft 365 : Client ID', 'text', null, 'Inscription d\'application Azure (connexion des boîtes Outlook)'],
                ['MICROSOFT_CLIENT_SECRET', 'Microsoft 365 : Client secret', 'secret', null, null],
                ['MICROSOFT_TENANT', 'Microsoft 365 : tenant', 'text', null, 'common'],
                ['GOOGLE_CLIENT_ID', 'Google : Client ID', 'text', null, 'Console Google Cloud (connexion des boîtes Gmail)'],
                ['GOOGLE_CLIENT_SECRET', 'Google : Client secret', 'secret', null, null],
                ['MAILBOX_VERIFY_TLS', 'Vérifier les certificats des serveurs IMAP', 'bool', null, null],
                ['MAILBOX_OWN_PER_HOUR', 'Envois par heure (boîtes des sociétés)', 'number', null, null],
                ['MAILBOX_OWN_PER_DAY', 'Envois par jour (boîtes des sociétés)', 'number', null, null],
            ],
            'Boîtes mail hébergées (cPanel)' => [
                ['MAILBOX_CPANEL_HOST', 'Serveur cPanel', 'text', null, 'Pour créer des adresses @domaine pour les sociétés'],
                ['MAILBOX_CPANEL_PORT', 'Port cPanel', 'number', null, '2083'],
                ['MAILBOX_CPANEL_USER', 'Utilisateur cPanel', 'text', null, null],
                ['MAILBOX_CPANEL_TOKEN', 'Jeton API cPanel', 'secret', null, null],
                ['MAILBOX_CPANEL_VERIFY_TLS', 'Vérifier le certificat cPanel', 'bool', null, null],
                ['MAILBOX_HOSTED_DOMAIN', 'Domaine des adresses', 'text', null, null],
                ['MAILBOX_HOSTED_IMAP_HOST', 'Serveur IMAP', 'text', null, null],
                ['MAILBOX_HOSTED_SMTP_HOST', 'Serveur SMTP', 'text', null, null],
                ['MAILBOX_HOSTED_QUOTA_MB', 'Quota par boîte (Mo)', 'number', null, null],
                ['MAILBOX_HOSTED_MAX', 'Boîtes maximum par société', 'number', null, null],
                ['MAILBOX_HOSTED_PER_HOUR', 'Envois par heure', 'number', null, null],
                ['MAILBOX_HOSTED_PER_DAY', 'Envois par jour', 'number', null, null],
            ],
            'Banque et paiements' => [
                ['GOCARDLESS_SECRET_ID', 'GoCardless Bank Account Data : Secret ID', 'text', null, 'Synchronisation des comptes bancaires'],
                ['GOCARDLESS_SECRET_KEY', 'GoCardless : Secret key', 'secret', null, null],
                ['ERP_BANK_INBOUND_DOMAIN', 'Domaine de réception des relevés par e-mail', 'text', null, null],
            ],
            'Services externes' => [
                ['GOOGLE_PLACES_API_KEY', 'Clé Google Places', 'secret', null, 'Recherche des fiches Google (avis)'],
                ['DROPBOX_APP_KEY', 'Dropbox : App key', 'text', null, 'Copie des sauvegardes vers Dropbox'],
                ['DROPBOX_APP_SECRET', 'Dropbox : App secret', 'secret', null, null],
                ['MOBILE_ANDROID_URL', 'Lien de l\'application Android', 'url', null, null],
                ['MOBILE_IOS_URL', 'Lien de l\'application iPhone', 'url', null, null],
            ],
        ];
    }

    /** Clé → définition. */
    public static function flat(): array
    {
        $out = [];
        foreach (self::groups() as $g => $fields) {
            foreach ($fields as [$key, $label, $type, $options, $hint]) {
                $out[$key] = compact('key', 'label', 'type', 'options', 'hint') + ['group' => $g];
            }
        }

        return $out;
    }

    /** Valeurs enregistrées (en clair), mises en cache. */
    public static function stored(): array
    {
        return Cache::rememberForever(self::CACHE, function () {
            $out = [];
            foreach (DB::table('platform_settings')->where('key', 'like', 'env.%')->get(['key', 'value']) as $r) {
                $v = json_decode((string) $r->value, true)['v'] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                $k = substr($r->key, 4);
                if ((self::flat()[$k]['type'] ?? null) === 'secret') {
                    try {
                        $v = Crypt::decryptString((string) $v);
                    } catch (\Throwable) {
                        continue;
                    }
                }
                $out[$k] = (string) $v;
            }

            return $out;
        });
    }

    /** Au démarrage : les valeurs enregistrées remplacent celles du .env, puis la configuration concernée est relue. */
    public static function apply(): void
    {
        try {
            $values = self::stored();
        } catch (\Throwable) {
            return; // installation en cours, base absente
        }
        if (! $values) {
            return;
        }
        $defs = self::flat();
        foreach ($values as $k => $v) {
            if (! isset($defs[$k])) {
                continue;
            }
            $_ENV[$k] = $_SERVER[$k] = $v;
            putenv($k.'='.$v);
        }
        $keys = array_keys(array_intersect_key($values, $defs));
        foreach (glob(config_path('*.php')) ?: [] as $file) {
            $src = (string) file_get_contents($file);
            foreach ($keys as $k) {
                if (str_contains($src, "env('".$k."'")) {
                    config([basename($file, '.php') => require $file]);
                    break;
                }
            }
        }
        if (in_array('APP_TIMEZONE', $keys, true)) {
            date_default_timezone_set((string) config('app.timezone'));
        }
    }

    /** Vue pour l'écran : valeur effective, origine, secrets masqués. */
    public static function view(): array
    {
        $stored = self::stored();
        $groups = [];
        foreach (self::groups() as $g => $fields) {
            $items = [];
            foreach ($fields as [$key, $label, $type, $options, $hint]) {
                $fromDb = array_key_exists($key, $stored);
                $fileValue = self::fileValue($key);
                $value = $fromDb ? $stored[$key] : $fileValue;
                $items[] = [
                    'key' => $key, 'label' => $label, 'type' => $type, 'options' => $options, 'hint' => $hint,
                    'source' => $fromDb ? 'settings' : ($fileValue !== null && $fileValue !== '' ? 'env' : 'default'),
                    'value' => $type === 'secret' ? null : $value,
                    'is_set' => $value !== null && $value !== '',
                    'masked' => $type === 'secret' && $value ? str_repeat('•', 6).substr((string) $value, -4) : null,
                ];
            }
            $groups[] = ['name' => $g, 'fields' => $items];
        }

        return [
            'groups' => $groups,
            'locked' => [
                ['key' => 'ERP_MODE', 'label' => 'Mode', 'value' => config('erp.mode') === 'saas' ? 'Plateforme SaaS' : 'Installation unique'],
                ['key' => 'DB_CONNECTION', 'label' => 'Base de données', 'value' => config('database.default').' · '.config('database.connections.'.config('database.default').'.database')],
                ['key' => 'APP_ENV', 'label' => 'Environnement', 'value' => (string) config('app.env')],
            ],
        ];
    }

    /** Valeur du fichier .env (avant remplacement). */
    protected static function fileValue(string $key): ?string
    {
        static $file = null;
        if ($file === null) {
            $file = [];
            $path = app()->environmentFilePath();
            if (is_file($path)) {
                try {
                    $file = \Dotenv\Dotenv::parse((string) file_get_contents($path));
                } catch (\Throwable) {
                    $file = [];
                }
            }
        }

        return isset($file[$key]) ? (string) $file[$key] : null;
    }

    /** Enregistre des valeurs : vide = revenir au .env ; secret vide = inchangé (« __clear__ » pour l'effacer). */
    public static function save(array $values): array
    {
        $defs = self::flat();
        $errors = [];
        foreach ($values as $k => $v) {
            $d = $defs[$k] ?? null;
            if (! $d) {
                $errors[$k] = 'Réglage inconnu.';

                continue;
            }
            if ($d['type'] === 'secret' && ($v === '' || $v === null)) {
                continue; // secret laissé vide : inchangé
            }
            if ($v === null || $v === '' || $v === '__clear__') {
                \App\Models\PlatformSetting::where('key', 'env.'.$k)->delete();

                continue;
            }
            $v = is_bool($v) ? ($v ? 'true' : 'false') : trim((string) $v);
            $bad = match ($d['type']) {
                'bool' => ! in_array($v, ['true', 'false'], true),
                'number' => ! preg_match('/^\d{1,9}$/', $v),
                'url' => ! filter_var($v, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#', $v),
                'email' => ! filter_var($v, FILTER_VALIDATE_EMAIL),
                'select' => ! array_key_exists($v, (array) $d['options']),
                default => $k === 'FCM_SERVICE_ACCOUNT' ? ! self::validServiceAccount($v) : (strlen($v) > 2000 || str_contains($v, "\n")),
            };
            if ($bad) {
                $errors[$k] = 'Valeur invalide pour « '.$d['label'].' ».';

                continue;
            }
            if ($k === 'FCM_SERVICE_ACCOUNT') {
                $v = json_encode(json_decode($v, true), JSON_UNESCAPED_SLASHES); // sur une ligne
                \Illuminate\Support\Facades\Cache::forget('fcm:access');
            }
            \App\Models\PlatformSetting::put('env.'.$k, $d['type'] === 'secret' ? Crypt::encryptString($v) : $v);
        }
        Cache::forget(self::CACHE);

        return $errors;
    }

    /** Clé JSON d'un compte de service Google (projet, adresse et clé privée). */
    public static function validServiceAccount(string $v): bool
    {
        $j = json_decode($v, true);

        return strlen($v) < 12000 && is_array($j) && ($j['type'] ?? '') === 'service_account'
            && ! empty($j['project_id']) && ! empty($j['client_email']) && str_contains((string) ($j['private_key'] ?? ''), 'PRIVATE KEY');
    }
}
