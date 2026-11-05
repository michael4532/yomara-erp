<?php

namespace App\Platform;

use App\Http\Controllers\LandingController;
use App\Messaging\MessagingService;
use App\Models\Module;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Store\ModuleStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * « Ma configuration » (super admin) : tout ce qu'il faut régler pour que les clients utilisent la plateforme sans
 * accroc, avec l'état de chaque point et l'écran où le régler. Les points « essentiels » bloquent un usage normal ;
 * les « recommandés » améliorent l'expérience ; les « facultatifs » activent des fonctions précises.
 */
class SetupChecklist
{
    /** @return array{progress:int, essentials_left:int, sections:array} */
    public function run(): array
    {
        $saas = config('erp.mode') === 'saas';
        $sections = [];
        $add = function (string $section, string $key, string $label, bool $ok, string $level, string $todo, ?string $screen = null, ?string $action = null, ?string $done = null) use (&$sections) {
            $sections[$section][] = [
                'key' => $key, 'label' => $label, 'ok' => $ok, 'level' => $level, // essential | recommended | optional
                'detail' => $ok ? $done : $todo, 'screen' => $screen, 'action' => $action ?? 'Configurer',
            ];
        };

        // 1. Serveur et mises à jour
        $pending = $this->pendingMigrations();
        $add('Serveur', 'migrations', 'Base de données à jour', $pending === 0, 'essential',
            $pending.' mise(s) à jour de la base en attente : ouvrez « Mises à jour » et cliquez sur « Appliquer ».', 'admin_updates', 'Appliquer', 'Toutes les mises à jour sont appliquées.');
        $hb = storage_path(\App\Services\ProductionCheck::HEARTBEAT);
        $cronOk = is_file($hb) && rescue(fn () => \Illuminate\Support\Carbon::parse(trim((string) file_get_contents($hb)))->diffInMinutes(now()) <= 10, false, false);
        $add('Serveur', 'cron', 'Tâche planifiée (cron) active', (bool) $cronOk, 'essential',
            'Sans elle : pas de rappels d\'abonnement, de relances, de campagnes ni de sauvegardes. Dans cPanel › Tâches cron, chaque minute : php '.base_path('artisan').' schedule:run', 'admin', 'Voir l\'aide',
            'Dernier passage il y a moins de 10 minutes.');
        $add('Serveur', 'https', 'Adresse publique en https', str_starts_with((string) config('app.url'), 'https://'), 'essential',
            'APP_URL doit commencer par https:// : liens des e-mails, paiements et applications mobiles en dépendent.', 'admin_env', null, (string) config('app.url'));
        $add('Serveur', 'debug', 'Mode débogage désactivé', ! config('app.debug'), 'essential', 'APP_DEBUG=true affiche des informations techniques aux visiteurs.', 'admin_env');
        $backups = $this->recentBackup();
        $add('Serveur', 'backups', 'Sauvegardes automatiques', $backups, 'recommended', 'Aucune sauvegarde des 2 derniers jours : programmez-les (et copiez-les hors du serveur).', $saas ? 'admin_backups' : 'backups', null, 'Une sauvegarde récente existe.');

        // 2. E-mails
        $platformMail = MessagingService::platformMailReady();
        $envMail = filled(config('mail.mailers.'.config('mail.default').'.host')) && config('mail.default') === 'smtp';
        $add('E-mails', 'smtp', 'Serveur d\'envoi des e-mails', $platformMail || $envMail, 'essential',
            'Sans lui, vos clients ne reçoivent ni e-mail de bienvenue, ni mot de passe oublié, ni rappel d\'abonnement, ni facture.', $platformMail ? 'admin_store' : 'admin_env', null,
            $platformMail ? 'Serveur e-mail de la plateforme configuré.' : 'Envoi par le serveur du fichier .env.');
        $from = (string) (MessagingService::platformMail()['from_email'] ?? config('mail.from.address'));
        $add('E-mails', 'from', 'Adresse d\'expédition à votre domaine', $from !== '' && ! str_contains($from, 'example') && ! preg_match('/@(gmail|yahoo|hotmail|outlook)\./i', $from), 'recommended',
            'Utilisez une adresse de votre domaine (ex. contact@votre-domaine.com) pour ne pas finir dans les indésirables.', 'admin_store', null, $from);

        // 3. Paiements des clients (boutique de modules)
        $online = PaymentGateway::store()->where('enabled', true)->get()->filter(fn ($g) => $g->isOnline())->count();
        $offline = PaymentGateway::store()->where('enabled', true)->get()->reject(fn ($g) => $g->isOnline())->count() + count((array) PlatformSetting::get('store.bank_accounts', []));
        if ($saas) {
            $add('Paiements', 'online', 'Paiement en ligne (Mobile Money, carte)', $online > 0, 'essential',
                'Ajoutez Paystack, CinetPay, KKiaPay, FedaPay ou Stripe : l\'abonnement s\'active tout seul après paiement.', 'payments', 'Ajouter', $online.' moyen(s) en ligne actif(s).');
            $add('Paiements', 'offline', 'Paiement hors ligne (virement, dépôt Mobile Money)', $offline > 0, 'recommended',
                'Pour les clients qui préfèrent payer à la main : vous validez la commande à réception.', 'admin_store', 'Ajouter', $offline.' moyen(s) hors ligne.');
        }

        // 4. Offre commerciale
        if ($saas) {
            $plans = Plan::where('is_active', true)->count();
            $add('Offre', 'plans', 'Formules publiées', $plans >= 2, 'essential', 'Publiez au moins une formule gratuite et une payante.', 'admin_plans', null, $plans.' formule(s) active(s).');
            $offer = SubscriptionLifecycle::offer();
            $add('Offre', 'trial', 'Durée de l\'essai gratuit', (int) ($offer['standard_days'] ?? 0) > 0, 'recommended',
                'Réglez la durée de l\'essai (ex. 30 jours) et, si vous voulez, une offre limitée dans le temps.', 'admin_plans', null,
                ($offer['promo_active'] ?? false) ? 'Offre en cours : '.$offer['days'].' jours ('.($offer['promo_label'] ?: 'promotion').').' : ($offer['standard_days'] ?? 0).' jours d\'essai.');
            $unpriced = Module::where('is_core', false)->where('is_active', true)->where('in_store', true)->where(fn ($q) => $q->whereNull('price_monthly')->orWhere('price_monthly', '<=', 0))->count();
            $add('Offre', 'prices', 'Prix des modules', $unpriced === 0, 'recommended', $unpriced.' module(s) vendu(s) sans prix mensuel.', 'admin_modules', null, 'Tous les modules en vente ont un prix.');
            $auto = Schema::hasTable('platform_automations') ? DB::table('platform_automations')->where('is_active', true)->count() : 0;
            $add('Offre', 'automations', 'Relances automatiques (fin d\'essai, impayés…)', $auto > 0, 'recommended', 'Activez les relances : elles transforment les essais en abonnements.', 'admin_comms', null, $auto.' relance(s) active(s).');
        }

        // 5. Site et contact
        $contact = ModuleStore::contact();
        $add('Site et contact', 'contact', 'Coordonnées affichées aux clients', filled($contact['email'] ?? null) && (filled($contact['phone'] ?? null) || filled($contact['whatsapp'] ?? null)), 'essential',
            'E-mail et téléphone ou WhatsApp : affichés sur le site, dans la boutique et dans les e-mails.', 'admin_store', null, trim(($contact['email'] ?? '').' · '.($contact['whatsapp'] ?? $contact['phone'] ?? ''), ' ·'));
        if ($saas) {
            $add('Site et contact', 'livechat', 'Discussion en direct sur le site', (bool) PlatformSetting::get('livechat.enabled', true), 'optional', 'Répondez aux visiteurs du site depuis l\'application.', 'admin_livechat', 'Activer', 'Activée.');
            $dl = array_filter(LandingController::downloads());
            $add('Site et contact', 'downloads', 'Logiciels à télécharger (Windows, Mac, Linux, Android, iPhone)', count($dl) >= 3, 'recommended',
                'Ajoutez les liens ou déposez les fichiers des applications : la page « Télécharger » les propose à vos clients.', 'admin_store', null, count($dl).' plateforme(s) disponible(s).');
        }

        // 6. Fonctions qui demandent une clé de service
        $add('Services connectés', 'ai', 'Assistant IA et Sensya (suggestions de textes)', filled(config('erp.ai.api_key')), 'recommended',
            'Clé Anthropic, OpenAI, Mistral… : rédaction assistée, publications, réponses aux clients. Chaque société peut aussi mettre la sienne.', 'admin_env', null, 'Clé de la plateforme enregistrée.');
        $social = class_exists(\App\Modules\Social\Networks::class) ? count(\App\Modules\Social\Networks::configuredProviders()) : 0;
        $add('Services connectés', 'social', 'Applications des réseaux sociaux (Meta, LinkedIn, X, TikTok…)', $social > 0, 'optional',
            'Nécessaire pour que vos clients connectent leurs pages Facebook, Instagram, LinkedIn… au module Réseaux sociaux.', 'social', null, $social.' réseau(x) prêt(s).');
        $add('Services connectés', 'visio', 'Visioconférence (ZEGOCLOUD ou Agora)', filled(config('erp.visio.zego_app_id')) || filled(config('erp.visio.agora_app_id')), 'optional',
            'Comptes de visioconférence de la plateforme pour le module Yomeet.', 'admin_env');
        $add('Services connectés', 'oauth_mail', 'Connexion Microsoft 365 / Google pour la boîte mail', filled(config('erp.mailbox.microsoft_client_id')) || filled(config('erp.mailbox.google_client_id')), 'optional',
            'Permet à vos clients de connecter Outlook / Gmail en un clic (sinon IMAP avec mot de passe).', 'admin_env');
        $add('Services connectés', 'hosted_mail', 'Adresses e-mail hébergées (cPanel)', \App\Modules\Mailbox\HostedMail::available(), 'optional',
            'Vos clients sans serveur de messagerie obtiennent une adresse @votre-domaine.', 'admin_env');

        // synthèse
        $all = collect($sections)->flatten(1);
        $weight = fn ($i) => ['essential' => 3, 'recommended' => 2, 'optional' => 1][$i['level']];
        $total = $all->sum($weight);
        $done = $all->where('ok', true)->sum($weight);
        $out = [];
        foreach ($sections as $name => $items) {
            $out[] = ['name' => $name, 'items' => $items, 'done' => collect($items)->where('ok', true)->count(), 'total' => count($items)];
        }

        return [
            'progress' => $total ? (int) round($done * 100 / $total) : 100,
            'essentials_left' => $all->where('level', 'essential')->where('ok', false)->count(),
            'sections' => $out,
        ];
    }

    public function pendingMigrations(): int
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->flip();
            $files = collect(app('migrator')->getMigrationFiles(array_merge([database_path('migrations')], app('migrator')->paths())))->keys();

            return $files->reject(fn ($m) => isset($ran[$m]))->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function recentBackup(): bool
    {
        $dirs = [storage_path('app/backups'), storage_path('backups')];
        foreach ($dirs as $d) {
            foreach (glob($d.'/*') ?: [] as $f) {
                if (is_file($f) && filemtime($f) > time() - 2 * 86400) {
                    return true;
                }
            }
        }

        return false;
    }
}
