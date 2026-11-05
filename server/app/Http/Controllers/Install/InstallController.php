<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Services\CompanySetup;
use App\Support\EnvWriter;
use App\Support\Installation;
use Database\Seeders\PlatformSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PDO;

/**
 * Assistant d'installation (type CodeCanyon) — aucune ligne de commande requise.
 *   1. Prérequis   2. Base de données   3. Société & administrateur   4. Terminé
 * Une fois installé, toutes ces pages renvoient 404.
 */
class InstallController extends Controller
{
    public function __construct()
    {
        abort_if(Installation::isInstalled(), 404);
    }

    public static function requirements(): array
    {
        $checks = [
            ['PHP 8.2 ou plus (actuel : '.PHP_VERSION.')', version_compare(PHP_VERSION, '8.2.0', '>=')],
        ];
        foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl'] as $ext) {
            $checks[] = ['Extension PHP '.$ext, extension_loaded($ext)];
        }
        $checks[] = ['Extension PHP pdo_sqlite (optionnelle, pour SQLite)', extension_loaded('pdo_sqlite'), true];
        $checks[] = ['Extension PHP zip (optionnelle, mises à jour)', extension_loaded('zip'), true];

        foreach (['storage', 'storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $checks[] = ['Dossier '.$dir.' accessible en écriture', is_writable(base_path($dir))];
        }
        $envOk = file_exists(base_path('.env')) ? is_writable(base_path('.env')) : is_writable(base_path());
        $checks[] = ['Fichier .env modifiable', $envOk];

        return array_map(fn ($c) => ['label' => $c[0], 'ok' => $c[1], 'optional' => $c[2] ?? false], $checks);
    }

    protected function view(string $name, array $data = [])
    {
        return response()->view('install.'.$name, $data + ['step' => $name]);
    }

    // ---------- 1. Prérequis ----------
    public function welcome()
    {
        $checks = static::requirements();
        $ok = collect($checks)->every(fn ($c) => $c['ok'] || $c['optional']);

        return $this->view('requirements', compact('checks', 'ok'));
    }

    // ---------- 2. Base de données ----------
    public function database()
    {
        return $this->view('database', ['values' => [
            'driver' => 'mysql', 'host' => 'localhost', 'port' => '3306', 'database' => '', 'username' => '',
        ], 'error' => null]);
    }

    public function saveDatabase(Request $request)
    {
        $v = $request->only(['driver', 'host', 'port', 'database', 'username', 'password']);
        $v['driver'] = in_array($v['driver'] ?? '', ['mysql', 'sqlite'], true) ? $v['driver'] : 'mysql';

        try {
            if ($v['driver'] === 'sqlite') {
                $file = database_path('database.sqlite');
                if (! file_exists($file)) {
                    touch($file);
                }
                new PDO('sqlite:'.$file);
                $env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file];
            } else {
                if (empty($v['database']) || empty($v['username'])) {
                    throw new \RuntimeException('Le nom de la base et l\'utilisateur sont obligatoires.');
                }
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $v['host'] ?: 'localhost', $v['port'] ?: '3306', $v['database']);
                new PDO($dsn, $v['username'], $v['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $env = [
                    'DB_CONNECTION' => 'mysql', 'DB_HOST' => $v['host'] ?: 'localhost', 'DB_PORT' => $v['port'] ?: '3306',
                    'DB_DATABASE' => $v['database'], 'DB_USERNAME' => $v['username'], 'DB_PASSWORD' => $v['password'] ?? '',
                ];
            }
        } catch (\Throwable $e) {
            return $this->view('database', ['values' => $v, 'error' => 'Connexion impossible : '.$e->getMessage()]);
        }

        EnvWriter::ensureExists();
        $key = config('app.key') ?: 'base64:'.base64_encode(random_bytes(32));
        EnvWriter::set($env + [
            'APP_KEY' => $key,
            'APP_URL' => rtrim($request->getSchemeAndHttpHost().str_replace('/index.php', '', $request->getBaseUrl()), '/'),
        ]);

        return redirect('/install/setup');
    }

    // ---------- 3. Société & administrateur ----------
    public function setup()
    {
        return $this->view('setup', ['values' => ['mode' => 'single', 'app_name' => 'Yomara ERP', 'currency' => 'XOF'], 'errors' => []]);
    }

    public function saveSetup(Request $request, CompanySetup $companySetup)
    {
        $validator = Validator::make($request->all(), [
            'mode' => 'required|in:single,saas',
            'app_name' => 'required|string|max:100',
            'company_name' => 'required|string|max:255',
            'currency' => 'required|string|size:3',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email',
            'admin_password' => 'required|string|min:8|confirmed',
            'license_key' => 'nullable|string|max:255',
            'editor_key' => 'nullable|string|max:255',
        ], [], [
            'company_name' => 'nom de la société', 'admin_name' => 'nom', 'admin_email' => 'e-mail',
            'admin_password' => 'mot de passe',
        ]);

        if ($validator->fails()) {
            return $this->view('setup', ['values' => $request->except(['admin_password', 'admin_password_confirmation']), 'errors' => $validator->errors()->all()]);
        }
        $d = $validator->validated();
        $back = fn (string $msg) => $this->view('setup', ['values' => $request->except(['admin_password', 'admin_password_confirmation']), 'errors' => [$msg]]);

        // Plateforme SaaS : réservée à l'éditeur
        if ($d['mode'] === 'saas' && ! hash_equals((string) config('erp.editor_key_hash'), hash('sha256', trim((string) ($d['editor_key'] ?? ''))))) {
            return $back('Le mode plateforme SaaS est réservé à l\'éditeur : clé éditeur incorrecte. Pour installer votre entreprise, choisissez « Mon entreprise (licence) ».');
        }
        // Installation d'une entreprise : gratuite, sans licence. Les fonctions de base sont actives ;
        // pour ajouter des modules, l'administrateur relie ensuite l'installation à son compte en ligne.
        try {
            @set_time_limit(300);
            Artisan::call('migrate', ['--force' => true]);
            (new PlatformSeeder)->run();
            if ($d['mode'] === 'saas') {
                \App\Licensing\LicenseSigner::unlock((string) $d['editor_key']); // clé officielle de signature des licences
            }

            $companySetup->create(
                ['name' => $d['company_name'], 'email' => $d['admin_email'], 'currency' => strtoupper($d['currency'])],
                ['name' => $d['admin_name'], 'email' => $d['admin_email'], 'password' => $d['admin_password'], 'is_super_admin' => true],
                \App\Models\Plan::where('code', 'entreprise')->first(), // société du propriétaire : tous les modules
            );

            EnvWriter::set([
                'ERP_MODE' => $d['mode'],
                'ERP_NAME' => $d['app_name'],
                'APP_NAME' => $d['app_name'],
                'ERP_CURRENCY' => strtoupper($d['currency']),
                'ERP_LICENSE_KEY' => '',
            ]);

            try {
                Artisan::call('storage:link');
            } catch (\Throwable) {
                // liens symboliques souvent désactivés en mutualisé : non bloquant
            }

            Installation::markInstalled(['mode' => $d['mode']]);
            Artisan::call('optimize:clear');
        } catch (\Throwable $e) {
            report($e);

            return $this->view('setup', ['values' => $request->except(['admin_password', 'admin_password_confirmation']), 'errors' => ['Erreur pendant l\'installation : '.$e->getMessage()]]);
        }

        return response()->view('install.done', [
            'step' => 'done',
            'appUrl' => url('/app/'),
            'apiUrl' => url('/'),
            'email' => strtolower($d['admin_email']),
            'cron' => '* * * * * php '.base_path('artisan').' schedule:run >> /dev/null 2>&1',
        ]);
    }
}
