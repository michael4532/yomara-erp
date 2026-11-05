<?php

namespace App\Services;

use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contrôle d'une installation en production : configuration, base, tâche planifiée, e-mail, sécurité.
 * Chaque point : état (ok / warn / fail), libellé, détail et ce qu'il faut faire.
 */
class ProductionCheck
{
    public const HEARTBEAT = 'app/cron-heartbeat';

    public static function beat(): void
    {
        @file_put_contents(storage_path(self::HEARTBEAT), now()->toIso8601String());
    }

    public function run(): array
    {
        $out = [];
        $add = function (string $group, string $label, string $state, ?string $detail = null, ?string $fix = null) use (&$out) {
            $out[] = compact('group', 'label', 'state', 'detail', 'fix');
        };

        // ---------------------------------------------------------------- serveur
        foreach (InstallController::requirements() as $r) {
            ['label' => $label, 'ok' => $ok, 'optional' => $optional] = $r;
            if (str_starts_with($label, 'Fichier .env modifiable')) {
                continue; // en production, .env peut être en lecture seule
            }
            $add('Serveur', $label, $ok ? 'ok' : ($optional ? 'warn' : 'fail'), null, $ok ? null : 'Activez-la dans cPanel › Sélectionner une version de PHP › Extensions (ou demandez-le à l\'hébergeur).');
        }
        foreach (['gd' => 'images et signatures', 'xmlreader' => 'lecture des fichiers Excel', 'intl' => 'formats de dates et de nombres'] as $ext => $why) {
            $add('Serveur', 'Extension PHP '.$ext.' ('.$why.')', extension_loaded($ext) ? 'ok' : 'warn', null, extension_loaded($ext) ? null : 'Recommandée : activez-la dans le sélecteur de version PHP.');
        }
        $mem = $this->bytes((string) ini_get('memory_limit'));
        $add('Serveur', 'Mémoire PHP : '.ini_get('memory_limit'), $mem < 0 || $mem >= 256 * 1048576 ? 'ok' : 'warn', null, 'Mettez memory_limit à 256M ou plus (exports Excel, PDF).');
        $up = $this->bytes((string) ini_get('upload_max_filesize'));
        $add('Serveur', 'Taille maximale d\'envoi : '.ini_get('upload_max_filesize'), $up >= 32 * 1048576 ? 'ok' : 'warn', null, 'Mettez upload_max_filesize et post_max_size à 64M (documents, paquets de mise à jour envoyés par morceaux de 4 Mo).');
        $free = @disk_free_space(storage_path());
        if ($free !== false) {
            $add('Serveur', 'Espace disque libre : '.round($free / 1073741824, 1).' Go', $free > 2 * 1073741824 ? 'ok' : ($free > 512 * 1048576 ? 'warn' : 'fail'), null, 'Libérez de l\'espace ou augmentez le quota : les sauvegardes et mises à jour en ont besoin.');
        }

        // ---------------------------------------------------------------- configuration
        $add('Configuration', 'Clé de l\'application (APP_KEY)', config('app.key') ? 'ok' : 'fail', null, 'php artisan key:generate (l\'assistant /install le fait).');
        $add('Configuration', 'Environnement : '.config('app.env'), config('app.env') === 'production' ? 'ok' : 'warn', null, 'APP_ENV=production dans .env.');
        $add('Configuration', 'Mode débogage désactivé', config('app.debug') ? 'fail' : 'ok', config('app.debug') ? 'Les erreurs affichent des informations internes.' : null, 'Super admin › Configuration du serveur › Mode débogage : Non (ou APP_DEBUG=false dans .env).');
        $url = (string) config('app.url');
        $add('Configuration', 'Adresse publique : '.$url, str_starts_with($url, 'https://') ? 'ok' : 'fail', null, 'Super admin › Configuration du serveur › Adresse publique : https://votre-domaine (liens des e-mails, portails, paiements et questionnaires).');
        if (request() && request()->getHost() && parse_url($url, PHP_URL_HOST) && request()->getHost() !== parse_url($url, PHP_URL_HOST)) {
            $add('Configuration', 'Adresse publique et domaine utilisé', 'warn', 'Ouvert via '.request()->getHost().', APP_URL indique '.parse_url($url, PHP_URL_HOST).'.', 'Corrigez APP_URL.');
        }
        $add('Configuration', 'Mode : '.(config('erp.mode') === 'saas' ? 'plateforme SaaS (plusieurs sociétés)' : 'installation unique'), 'ok');
        $add('Configuration', 'Fuseau horaire : '.config('app.timezone'), config('app.timezone') ? 'ok' : 'warn');
        $add('Configuration', 'Journal des erreurs : niveau '.config('logging.channels.'.config('logging.default').'.level', 'debug'), in_array(config('logging.channels.'.config('logging.default').'.level'), ['error', 'warning', 'critical'], true) ? 'ok' : 'warn', null, 'Super admin › Configuration du serveur › Niveau du journal : Erreurs seulement.');

        // ---------------------------------------------------------------- base de données
        try {
            DB::select('select 1');
            $add('Base de données', 'Connexion ('.DB::connection()->getDriverName().')', DB::connection()->getDriverName() === 'sqlite' && config('erp.mode') === 'saas' ? 'warn' : 'ok', null, 'En SaaS, utilisez MySQL / MariaDB.');
            $done = collect(DB::table('migrations')->pluck('migration'));
            $files = collect(app('migrator')->getMigrationFiles(array_merge([database_path('migrations')], app('migrator')->paths())))->keys();
            $missing = $files->diff($done)->count();
            $add('Base de données', $missing ? $missing.' mise(s) à jour de la base en attente' : 'Base à jour', $missing ? 'fail' : 'ok', null, 'Super admin › Vue d\'ensemble › « Appliquer la mise à jour » (ou php artisan migrate --force).');
            $add('Base de données', 'Catalogue des modules', DB::table('modules')->where('is_available', true)->count() > 20 ? 'ok' : 'warn', null, 'php artisan erp:modules-sync');
            $installed = is_file(storage_path('app/installed.lock'));
            $add('Base de données', 'Installation terminée', $installed ? 'ok' : 'warn', null, 'Ouvrez /install et terminez l\'assistant.');
        } catch (\Throwable $e) {
            $add('Base de données', 'Connexion', 'fail', $e->getMessage(), 'Vérifiez DB_HOST, DB_DATABASE, DB_USERNAME et DB_PASSWORD dans .env.');
        }

        // ---------------------------------------------------------------- tâches planifiées
        $hb = storage_path(self::HEARTBEAT);
        if (! is_file($hb)) {
            $add('Tâches planifiées', 'Tâche cron jamais exécutée', 'fail', 'Sans elle : pas de relances, de rappels, de sauvegardes ni d\'envoi des campagnes.',
                'cPanel › Tâches Cron, toutes les minutes : php '.base_path('artisan').' schedule:run >> /dev/null 2>&1');
        } else {
            $last = Carbon::parse(trim((string) file_get_contents($hb)));
            $mins = (int) $last->diffInMinutes(now());
            $add('Tâches planifiées', 'Dernier passage de la tâche cron : il y a '.$mins.' min', $mins <= 5 ? 'ok' : ($mins <= 60 ? 'warn' : 'fail'), null,
                'Vérifiez la tâche cron (toutes les minutes) : php '.base_path('artisan').' schedule:run');
        }
        try {
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count();
            $add('Tâches planifiées', $failed ? $failed.' envoi(s) en échec cette semaine' : 'Aucun envoi en échec', $failed ? 'warn' : 'ok', null, 'Consultez storage/logs pour la cause (souvent l\'e-mail).');
        } catch (\Throwable) {
        }

        // ---------------------------------------------------------------- e-mail
        $mailer = (string) config('mail.default');
        $host = (string) config('mail.mailers.smtp.host');
        $mailOk = $mailer !== 'log' && $mailer !== 'array' && ($mailer !== 'smtp' || $host !== '');
        $add('E-mail', 'Envoi des e-mails ('.$mailer.($host ? ', '.$host : '').')', $mailOk ? 'ok' : 'fail', null, 'Super admin › Configuration du serveur › E-mail d\'envoi : serveur, port, identifiant et mot de passe (compte e-mail cPanel, ex. erp@yomaratechnologies.com), puis « Tester ».');
        $from = (string) config('mail.from.address');
        $add('E-mail', 'Adresse d\'expédition : '.($from ?: '—'), $from && ! str_contains($from, 'example') ? 'ok' : 'warn', null, 'Adresse d\'expédition sur votre domaine (Configuration du serveur), avec SPF et DKIM activés dans cPanel › Délivrabilité.');

        // ---------------------------------------------------------------- sécurité et sauvegardes
        $add('Sécurité', 'Racine web sur le dossier public/', is_file(base_path('.htaccess')) || str_ends_with((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), 'public') ? 'ok' : 'warn', null,
            'Faites pointer le sous-domaine sur le dossier public/ (sinon le .htaccess racine protège .env et storage).');
        $add('Sécurité', 'Fichier .env non modifiable par le web', ! is_writable(base_path('.env')) ? 'ok' : 'warn', null, 'Après l\'installation : droits 640 sur .env.');
        $backups = glob(storage_path('app/backups/*')) ?: [];
        $recent = collect($backups)->filter(fn ($f) => filemtime($f) >= now()->subDays(2)->timestamp)->count();
        $add('Sauvegardes', $recent ? 'Sauvegarde récente présente' : 'Aucune sauvegarde des 2 derniers jours', $recent ? 'ok' : 'warn', null,
            'Super admin › Sauvegardes : activez la sauvegarde quotidienne chiffrée et sa copie vers Google Drive ou Dropbox.');
        $add('Mises à jour', 'Version installée : '.config('erp.version'), 'ok', config('erp.updates.publisher') ? 'Ce serveur diffuse les mises à jour aux installations clientes.' : null);

        $score = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($out as $c) {
            $score[$c['state']]++;
        }

        return ['checks' => $out, 'summary' => $score, 'ready' => $score['fail'] === 0, 'checked_at' => now()->toIso8601String()];
    }

    protected function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') {
            return -1;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
