<?php

namespace App\Services\Updates;

use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Mise à jour à distance d'une installation (serveur cPanel / VPS, PC ou Mac en local), sans cPanel ni SSH :
 *  1. check()   : interroge le serveur de diffusion (mis en cache)
 *  2. install() : vérifications, téléchargement, contrôle d'intégrité, sauvegarde du code, remplacement des fichiers
 *  3. finish()  : appelé par une NOUVELLE requête (nouveau code chargé) : sauvegarde de la base, migrations,
 *                 modules, caches, contrôle de santé ; en cas d'échec, retour automatique à la version précédente.
 * Si le nouveau code ne démarre même pas, public/restore.php (sans Laravel) restaure la sauvegarde avec un jeton.
 */
class Updater
{
    /** Jamais écrasés par une mise à jour. */
    public const PROTECTED = ['.env', 'storage', 'public/storage', 'public/uploads', 'public/downloads', 'database/database.sqlite'];

    /** Dossiers et fichiers du code (sauvegardés avant remplacement). */
    public const CODE = ['app', 'bootstrap/app.php', 'bootstrap/providers.php', 'config', 'database/migrations', 'database/seeders', 'database/factories', 'lang', 'public',
        'resources', 'routes', 'vendor', 'artisan', 'composer.json', 'composer.lock'];

    public static function dir(string $sub = ''): string
    {
        $d = storage_path('app/updates'.($sub ? '/'.$sub : ''));
        if (! is_dir($d)) {
            mkdir($d, 0775, true);
        }

        return $d;
    }

    public static function installId(): string
    {
        $f = storage_path('app/install.json');
        $d = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        if (empty($d['id'])) {
            $d['id'] = (string) Str::uuid();
            $d['created_at'] = now()->toIso8601String();
            @file_put_contents($f, json_encode($d));
        }

        return $d['id'];
    }

    protected function server(): string
    {
        return rtrim((string) config('erp.updates.server'), '/');
    }

    /** Une installation qui diffuse elle-même (plateforme) n'interroge pas de serveur : elle est la référence. */
    public function isPublisher(): bool
    {
        return (bool) config('erp.updates.publisher');
    }

    // ------------------------------------------------------------------ Vérification

    public function status(bool $force = false): array
    {
        $cache = self::dir().'/latest.json';
        $c = is_file($cache) ? (json_decode((string) file_get_contents($cache), true) ?: []) : [];
        $fresh = ! empty($c['checked_at']) && now()->diffInMinutes(\Carbon\Carbon::parse($c['checked_at'])) < (int) config('erp.updates.check_every', 180);
        if (($force || ! $fresh) && ! $this->isPublisher() && $this->server() !== '') {
            $c = $this->check();
        }
        $current = config('erp.version');
        $latest = $c['latest'] ?? null;
        // Mise à jour interrompue (restaurée par restore.php) : on la marque comme annulée
        DB::table('upd_history')->where('status', 'installing')->where('to_version', '!=', $current)->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'rolled_back', 'updated_at' => now()]);

        return [
            'current' => $current,
            'available' => $latest && version_compare($latest['version'], $current, '>'),
            'latest' => $latest,
            'checked_at' => $c['checked_at'] ?? null,
            'error' => $c['error'] ?? null,
            'publisher' => $this->isPublisher(),
            'last' => DB::table('upd_history')->latest('id')->first(),
            'previously_failed' => $latest && DB::table('upd_history')->where('to_version', $latest['version'])->whereIn('status', ['failed', 'rolled_back'])->exists(),
        ];
    }

    public function check(): array
    {
        $c = ['checked_at' => now()->toIso8601String(), 'latest' => null, 'error' => null];
        try {
            $company = DB::table('companies')->orderBy('id')->value('name');
            $r = Http::timeout(6)->acceptJson()->get($this->server().'/api/updates/latest', [
                'version' => config('erp.version'), 'channel' => config('erp.updates.channel', 'stable'), 'install_id' => self::installId(),
                'name' => $company, 'url' => config('app.url'), 'php' => PHP_VERSION, 'db' => DB::connection()->getDriverName(), 'os' => PHP_OS_FAMILY,
            ]);
            if ($r->successful()) {
                $c['latest'] = $r->json('latest');
            } else {
                $c['error'] = 'Serveur de mises à jour : réponse '.$r->status();
            }
        } catch (\Throwable $e) {
            $c['error'] = 'Serveur de mises à jour injoignable (hors ligne ?)';
        }
        @file_put_contents(self::dir().'/latest.json', json_encode($c));

        return $c;
    }

    // ------------------------------------------------------------------ Vérifications préalables

    public function preflight(?array $latest = null): array
    {
        $latest ??= $this->status()['latest'];
        $base = base_path();
        $checks = [];
        $add = function (string $label, bool $ok, ?string $detail = null) use (&$checks) {
            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $add('Extension ZIP de PHP', class_exists(\ZipArchive::class), class_exists(\ZipArchive::class) ? null : 'Activez l\'extension « zip » de PHP.');
        $need = (int) (($latest['size'] ?? 100_000_000) * 4);
        $free = @disk_free_space($base);
        $add('Espace disque', $free === false || $free > $need, $free === false ? null : round($free / 1048576).' Mo libres, '.round($need / 1048576).' Mo nécessaires');
        $php = $latest['php_min'] ?? '8.2';
        $add('Version de PHP', version_compare(PHP_VERSION, $php, '>='), 'PHP '.PHP_VERSION.' (minimum '.$php.')');
        $notWritable = [];
        foreach (['', 'app', 'config', 'public', 'vendor', 'routes', 'resources', 'database', 'bootstrap/cache', 'storage/app'] as $d) {
            $p = $base.($d ? '/'.$d : '');
            if (file_exists($p) && ! is_writable($p)) {
                $notWritable[] = $d ?: 'dossier principal';
            }
        }
        $add('Droits d\'écriture', ! $notWritable, $notWritable ? 'Non modifiable : '.implode(', ', $notWritable) : null);
        $lock = self::dir().'/lock';
        $busy = is_file($lock) && time() - filemtime($lock) < 900;
        $add('Aucune mise à jour en cours', ! $busy);

        return ['ok' => collect($checks)->every('ok'), 'checks' => $checks];
    }

    // ------------------------------------------------------------------ Installation des fichiers

    /** @param array|null $local paquet déjà présent sur ce serveur (plateforme : version envoyée par le super admin) */
    public function install(?int $userId = null, ?array $local = null): array
    {
        @set_time_limit(900);
        @ignore_user_abort(true);
        if ($local) {
            $latest = $local;
            if (version_compare($latest['version'], config('erp.version'), '<=')) {
                throw new \DomainException('Ce serveur est déjà en version '.config('erp.version').'.');
            }
        } else {
            $status = $this->status(true);
            $latest = $status['latest'] ?? throw new \DomainException($status['error'] ?? 'Aucune mise à jour disponible.');
            if (! $status['available']) {
                throw new \DomainException('Le logiciel est déjà à jour (version '.$status['current'].').');
            }
        }
        $pre = $this->preflight($latest);
        if (! $pre['ok']) {
            throw new \DomainException('Vérifications non satisfaites : '.collect($pre['checks'])->where('ok', false)->pluck('label')->implode(', ').'.');
        }
        $lock = self::dir().'/lock';
        touch($lock);
        DB::table('upd_history')->where('status', 'installing')->update(['status' => 'rolled_back', 'updated_at' => now()]);
        $from = config('erp.version');
        $hid = DB::table('upd_history')->insertGetId(['from_version' => $from, 'to_version' => $latest['version'], 'status' => 'installing', 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $log = [];
        try {
            // 1. Téléchargement et intégrité
            $pkg = self::dir().'/yomara-erp-'.$latest['version'].'.zip';
            if (! empty($latest['path'])) {
                copy($latest['path'], $pkg);
            } else {
                $r = Http::timeout(900)->withOptions(['sink' => $pkg])->get($latest['download']);
                if (! $r->successful()) {
                    throw new \DomainException('Téléchargement impossible ('.$r->status().').');
                }
            }
            if (! hash_equals((string) $latest['sha256'], hash_file('sha256', $pkg))) {
                throw new \DomainException('Paquet corrompu (empreinte SHA-256 différente) : téléchargement à refaire.');
            }
            // Paquet téléchargé : il doit être signé par Yomara (clé officielle épinglée dans le code)
            if (empty($latest['path']) && ! \App\Licensing\LicenseSigner::verify(\App\Licensing\LicenseSigner::releaseMessage((string) $latest['version'], (string) $latest['sha256']), $latest['signature'] ?? null)) {
                throw new \DomainException('Mise à jour refusée : le paquet n\'est pas signé par Yomara Technologies.');
            }
            $info = Publisher::inspect($pkg);
            if ($info['version'] !== $latest['version']) {
                throw new \DomainException('Le paquet téléchargé ne correspond pas à la version annoncée.');
            }
            $log[] = 'Paquet '.$latest['version'].' téléchargé et vérifié ('.round(filesize($pkg) / 1048576, 1).' Mo).';

            // 2. Sauvegarde du code actuel
            $backup = $this->backupCode($from);
            $log[] = 'Code de la version '.$from.' sauvegardé.';

            // 3. Jeton de secours pour public/restore.php
            $token = Str::random(40);
            file_put_contents(self::dir().'/rescue.json', json_encode(['token' => hash('sha256', $token), 'backup' => $backup, 'from' => $from, 'to' => $latest['version'],
                'created_at' => now()->toIso8601String(), 'db_backup' => null, 'driver' => DB::connection()->getDriverName()]));

            // 4. Remplacement des fichiers
            $n = $this->extract($pkg, $info['root']);
            file_put_contents(self::dir().'/installed-files.json', json_encode($this->lastWritten));
            $this->clearBootstrapCache();
            $log[] = $n.' fichiers mis en place.';
            @unlink($pkg);
            DB::table('upd_history')->where('id', $hid)->update(['backup' => $backup, 'log' => implode("\n", $log), 'updated_at' => now()]);

            return ['history_id' => $hid, 'version' => $latest['version'], 'rescue_token' => $token, 'log' => $log];
        } catch (\Throwable $e) {
            @unlink($lock);
            DB::table('upd_history')->where('id', $hid)->update(['status' => 'failed', 'log' => implode("\n", [...$log, 'Échec : '.$e->getMessage()]), 'updated_at' => now()]);
            throw $e instanceof \DomainException ? $e : new \DomainException('Mise à jour interrompue : '.$e->getMessage());
        }
    }

    protected function backupCode(string $version): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // On ne garde que les 2 dernières sauvegardes de code
        $old = glob($dir.'/code-*.zip') ?: [];
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - 1)) as $f) {
            @unlink($f);
        }
        $path = $dir.'/code-'.$version.'-'.now()->format('Ymd-His').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $base = rtrim(base_path(), '/');
        foreach (self::CODE as $c) {
            $p = $base.'/'.$c;
            if (is_file($p)) {
                $zip->addFile($p, $c);
            } elseif (is_dir($p)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($base))), '/');
                    if ($f->isFile() && ! $this->isProtected($rel)) {
                        $zip->addFile($f->getPathname(), $rel);
                    }
                }
            }
        }
        $zip->close();

        return $path;
    }

    /** Fichier faisant partie du code sauvegardé (les autres fichiers ne sont jamais supprimés au retour arrière). */
    protected function isCode(string $rel): bool
    {
        if ($rel === 'public/downloads' || str_starts_with($rel, 'public/downloads/')) {
            return false; // logiciels à télécharger déposés sur le serveur : ni sauvegardés ni remplacés
        }
        foreach (self::CODE as $c) {
            if ($rel === $c || str_starts_with($rel, $c.'/')) {
                return true;
            }
        }

        return false;
    }

    protected function isProtected(string $rel): bool
    {
        if ($rel === 'public/uploads/.htaccess') {
            return false; // règles de sécurité du dossier des fichiers déposés : toujours à jour
        }
        foreach (self::PROTECTED as $p) {
            if ($rel === $p || str_starts_with($rel, $p.'/')) {
                return true;
            }
        }

        return (bool) preg_match('#^database/.*\.sqlite$#', $rel) || str_starts_with($rel, 'bootstrap/cache/');
    }

    /** Copie le contenu du paquet sur l'installation (sans toucher aux données ni au .env). */
    protected function extract(string $pkg, string $root, ?string $base = null): int
    {
        $base = rtrim($base ?? base_path(), '/');
        $zip = new \ZipArchive;
        $zip->open($pkg);
        $n = 0;
        $written = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($root !== '' && ! str_starts_with($name, $root)) {
                continue;
            }
            $rel = substr($name, strlen($root));
            if ($rel === '' || str_ends_with($rel, '/') || str_contains($rel, '..') || $this->isProtected($rel)) {
                continue;
            }
            $dest = $base.'/'.$rel;
            if (! is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0775, true);
            }
            // Écriture dans un fichier temporaire puis renommage : jamais de fichier PHP à moitié écrit
            $tmp = $dest.'.upd-tmp';
            $stream = $zip->getStream($name);
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
            rename($tmp, $dest);
            $written[] = $rel;
            $n++;
        }
        $zip->close();
        $this->lastWritten = $written;

        return $n;
    }

    /** @var string[] fichiers écrits par la dernière extraction */
    protected array $lastWritten = [];

    protected function clearBootstrapCache(): void
    {
        foreach (glob(base_path('bootstrap/cache/*.php')) ?: [] as $f) {
            @unlink($f);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    // ------------------------------------------------------------------ Finalisation (nouveau code)

    public function finish(): array
    {
        @set_time_limit(900);
        $h = DB::table('upd_history')->where('status', 'installing')->latest('id')->first();
        if (! $h) {
            throw new \DomainException('Aucune mise à jour en attente de finalisation.');
        }
        $log = $h->log ? explode("\n", $h->log) : [];
        try {
            if (config('erp.version') !== $h->to_version) {
                throw new \RuntimeException('Les nouveaux fichiers ne sont pas actifs (version '.config('erp.version').' au lieu de '.$h->to_version.').');
            }
            $db = $this->backupDatabase($h->from_version);
            $this->rescue(['db_backup' => $db]);
            $log[] = 'Base de données sauvegardée.';
            Artisan::call('migrate', ['--force' => true]);
            $log[] = 'Base de données mise à jour.';
            app(ModuleRegistry::class)->syncToDatabase();
            Artisan::call('optimize:clear');
            $this->health();
            $log[] = 'Contrôle de santé réussi : version '.$h->to_version.' opérationnelle.';
            DB::table('upd_history')->where('id', $h->id)->update(['status' => 'done', 'log' => implode("\n", $log), 'updated_at' => now()]);
            @unlink(self::dir().'/lock');
            @unlink(self::dir().'/latest.json');
            \App\Models\ActivityLog::record('admin.system.update', null, ['from' => $h->from_version, 'to' => $h->to_version]);

            return ['ok' => true, 'version' => $h->to_version, 'log' => $log];
        } catch (\Throwable $e) {
            $log[] = 'Échec : '.$e->getMessage();
            DB::table('upd_history')->where('id', $h->id)->update(['status' => 'failed', 'log' => implode("\n", $log), 'updated_at' => now()]);
            $restored = $this->rollback();

            return ['ok' => false, 'error' => $e->getMessage(), 'rolled_back' => $restored['ok'], 'version' => $restored['version'] ?? null, 'log' => [...$log, ...$restored['log']]];
        }
    }

    /** Vérifie que l'application fonctionne : base, modules, routes, rendu d'une réponse API. */
    public function health(): void
    {
        DB::select('select 1');
        DB::table('users')->count();
        if (count(app(ModuleRegistry::class)->manifests()) < 5) {
            throw new \RuntimeException('Modules introuvables après la mise à jour.');
        }
        if (count(app('router')->getRoutes()->getRoutes()) < 50) {
            throw new \RuntimeException('Routes de l\'API incomplètes.');
        }
        $pending = collect(DB::table('migrations')->pluck('migration'));
        $files = collect(app('migrator')->getMigrationFiles(array_merge([database_path('migrations')], app('migrator')->paths())))->keys();
        $missing = $files->diff($pending);
        if ($missing->isNotEmpty()) {
            throw new \RuntimeException('Migrations non appliquées : '.$missing->take(3)->implode(', '));
        }
    }

    protected function rescue(array $merge): void
    {
        $f = self::dir().'/rescue.json';
        $d = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        file_put_contents($f, json_encode($merge + $d));
    }

    // ------------------------------------------------------------------ Sauvegarde et restauration de la base

    public function backupDatabase(string $version): ?string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $driver = DB::connection()->getDriverName();
        $stamp = now()->format('Ymd-His');
        foreach (array_slice(array_reverse(glob($dir.'/db-*') ?: []), 2) as $f) {
            @unlink($f);
        }
        if ($driver === 'sqlite') {
            $src = DB::connection()->getDatabaseName();
            if ($src === ':memory:' || ! is_file($src)) {
                return null;
            }
            $dst = $dir.'/db-'.$version.'-'.$stamp.'.sqlite';
            copy($src, $dst);

            return $dst;
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $dst = $dir.'/db-'.$version.'-'.$stamp.'.sql';
            $out = fopen($dst, 'wb');
            fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
            $pdo = DB::connection()->getPdo();
            foreach (DB::select('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'') as $row) {
                $table = array_values((array) $row)[0];
                $create = array_values((array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`'))[1];
                fwrite($out, "DROP TABLE IF EXISTS `$table`;\n$create;\n");
                foreach (DB::table($table)->cursor() as $r) {
                    $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), (array) $r);
                    fwrite($out, 'INSERT INTO `'.$table.'` VALUES ('.implode(',', $vals).");\n");
                }
            }
            fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($out);

            return $dst;
        }

        return null;
    }

    protected function restoreDatabase(string $path): void
    {
        if (str_ends_with($path, '.sqlite')) {
            $db = DB::connection()->getDatabaseName();
            DB::disconnect();
            copy($path, $db);
            DB::reconnect();

            return;
        }
        $pdo = DB::connection()->getPdo();
        $buf = '';
        $h = fopen($path, 'rb');
        while (($line = fgets($h)) !== false) {
            $buf .= $line;
            if (str_ends_with(rtrim($line), ';')) {
                $pdo->exec($buf);
                $buf = '';
            }
        }
        fclose($h);
    }

    /** Retour à la version précédente : code (et base, si elle avait été sauvegardée avant les migrations). */
    public function rollback(): array
    {
        $f = self::dir().'/rescue.json';
        $d = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $log = [];
        if (empty($d['backup']) || ! is_file($d['backup'])) {
            return ['ok' => false, 'log' => ['Aucune sauvegarde du code disponible.']];
        }
        try {
            if (! empty($d['db_backup']) && is_file($d['db_backup'])) {
                $this->restoreDatabase($d['db_backup']);
                $log[] = 'Base de données restaurée.';
            }
            $this->extract($d['backup'], '');
            // Fichiers apportés par la nouvelle version et absents de l'ancienne : retirés
            $listFile = self::dir().'/installed-files.json';
            if (is_file($listFile)) {
                $zip = new \ZipArchive;
                $zip->open($d['backup']);
                $removed = 0;
                foreach (json_decode((string) file_get_contents($listFile), true) ?: [] as $rel) {
                    if ($zip->locateName($rel) === false && $this->isCode($rel) && ! $this->isProtected($rel) && is_file(base_path($rel))) {
                        @unlink(base_path($rel));
                        $removed++;
                    }
                }
                $zip->close();
                $log[] = $removed.' fichier(s) de la nouvelle version retiré(s).';
            }
            $this->clearBootstrapCache();
            $log[] = 'Version '.($d['from'] ?? '').' restaurée.';
            DB::table('upd_history')->where('to_version', $d['to'] ?? '')->whereIn('status', ['installing', 'failed'])->update(['status' => 'rolled_back', 'updated_at' => now()]);
            @unlink(self::dir().'/lock');

            return ['ok' => true, 'version' => $d['from'] ?? null, 'log' => $log];
        } catch (\Throwable $e) {
            return ['ok' => false, 'log' => [...$log, 'Restauration impossible : '.$e->getMessage()]];
        }
    }
}
