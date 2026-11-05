<?php

namespace App\Backups;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sauvegardes de l'installation : base de données complète (une table par fichier JSON, portable SQLite ↔ MySQL)
 * et fichiers déposés (storage/app), dans une archive ZIP chiffrée AES-256 par la clé de sauvegarde.
 * Copie locale (rétention), envoi vers Google Drive et Dropbox, restauration depuis une copie ou une archive.
 */
class BackupService
{
    /** Tables techniques jamais sauvegardées ni restaurées. */
    public const SKIP = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /** Dossiers de storage/app exclus (sauvegardes elles-mêmes, paquets de mise à jour). */
    public const SKIP_DIRS = ['backups', 'updates', 'releases', 'backup-tmp'];

    public const DEFAULTS = ['enabled' => true, 'hour' => 2, 'keep_local' => 7, 'keep_remote' => 30, 'include_files' => true];

    public static function dir(): string
    {
        $d = storage_path('app/backups');
        if (! is_dir($d)) {
            @mkdir($d, 0775, true);
        }

        return $d;
    }

    // ------------------------------------------------------------------ réglages

    public function settings(): array
    {
        return array_replace(self::DEFAULTS, (array) PlatformSetting::get('backups', []));
    }

    public function save(array $patch): array
    {
        $s = array_replace($this->settings(), $patch);
        PlatformSetting::put('backups', $s);

        return $s;
    }

    /** Clé de sauvegarde (générée une fois, à noter : sans elle, une archive ne peut pas être restaurée ailleurs). */
    public function passphrase(): string
    {
        $s = $this->settings();
        if (! empty($s['passphrase']) && ($p = rescue(fn () => Crypt::decryptString($s['passphrase']), null, false))) {
            return $p;
        }
        $p = implode('-', str_split(Str::upper(Str::random(24)), 6));
        $this->save(['passphrase' => Crypt::encryptString($p)]);

        return $p;
    }

    public function setPassphrase(string $p): void
    {
        $this->save(['passphrase' => Crypt::encryptString(trim($p))]);
    }

    public function destination(string $key): array
    {
        return (array) ($this->settings()['destinations'][$key] ?? []);
    }

    public function setDestination(string $key, ?array $d): void
    {
        $dest = (array) ($this->settings()['destinations'] ?? []);
        if ($d === null) {
            unset($dest[$key]);
        } else {
            $dest[$key] = $d;
        }
        $this->save(['destinations' => $dest]);
    }

    public static function encryptionSupported(): bool
    {
        return class_exists(\ZipArchive::class) && defined('ZipArchive::EM_AES_256') && \ZipArchive::isEncryptionMethodSupported(\ZipArchive::EM_AES_256);
    }

    // ------------------------------------------------------------------ création

    protected function tables(): array
    {
        return collect(Schema::getTables())->pluck('name')->reject(fn ($t) => in_array($t, self::SKIP, true) || str_starts_with($t, 'sqlite_'))->sort()->values()->all();
    }

    /** Crée l'archive ; renvoie son chemin et son manifeste. */
    public function create(string $reason = 'manuelle', ?bool $includeFiles = null): array
    {
        @set_time_limit(0);
        $s = $this->settings();
        $includeFiles ??= (bool) $s['include_files'];
        $name = 'yomara-sauvegarde-'.now()->format('Ymd-His').'-v'.config('erp.version').'.zip';
        for ($i = 2; is_file(self::dir().'/'.$name); $i++) { // deux sauvegardes dans la même seconde
            $name = 'yomara-sauvegarde-'.now()->format('Ymd-His').'-'.$i.'-v'.config('erp.version').'.zip';
        }
        $path = self::dir().'/'.$name;
        $tmp = storage_path('app/backup-tmp/'.Str::random(12));
        @mkdir($tmp, 0775, true);
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer l\'archive dans '.self::dir().'.');
        }
        $encrypt = self::encryptionSupported();
        $pass = $this->passphrase();
        if ($encrypt) {
            $zip->setPassword($pass);
        }
        $add = function (string $file, string $entry) use ($zip, $encrypt) {
            $zip->addFile($file, $entry);
            if ($encrypt) {
                $zip->setEncryptionName($entry, \ZipArchive::EM_AES_256);
            }
        };
        $counts = [];
        foreach ($this->tables() as $t) {
            $f = $tmp.'/'.$t.'.jsonl';
            $h = fopen($f, 'w');
            $n = 0;
            foreach (DB::table($t)->cursor() as $row) {
                fwrite($h, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                $n++;
            }
            fclose($h);
            $counts[$t] = $n;
            $add($f, 'db/'.$t.'.jsonl');
        }
        $files = 0;
        $bytes = 0;
        if ($includeFiles) {
            $root = storage_path('app');
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
                if (! $file->isFile() || in_array(explode('/', $rel)[0], self::SKIP_DIRS, true) || $rel === 'installed.lock' || str_ends_with($rel, '.gitignore')) {
                    continue;
                }
                $add($file->getPathname(), 'files/'.$rel);
                $files++;
                $bytes += $file->getSize();
            }
        }
        $manifest = ['app' => 'Yomara ERP', 'version' => config('erp.version'), 'created_at' => now()->toIso8601String(), 'driver' => DB::connection()->getDriverName(),
            'mode' => config('erp.mode'), 'reason' => $reason, 'encrypted' => $encrypt, 'tables' => $counts, 'rows' => array_sum($counts), 'files' => $files, 'files_bytes' => $bytes,
            'companies' => Schema::hasTable('companies') ? DB::table('companies')->count() : null, 'check' => hash('sha256', $pass.'|yomara')];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); // lisible sans la clé
        $zip->close();
        \Illuminate\Support\Facades\File::deleteDirectory($tmp);
        $this->prune();

        return ['path' => $path, 'name' => $name, 'size' => filesize($path), 'manifest' => $manifest];
    }

    /** Sauvegarde complète : archive locale, puis envoi vers chaque destination branchée. */
    public function run(string $reason = 'manuelle'): array
    {
        $started = now();
        try {
            $b = $this->create($reason);
        } catch (\Throwable $e) {
            $this->save(['last' => ['at' => $started->toIso8601String(), 'status' => 'error', 'error' => Str::limit($e->getMessage(), 400, '')]]);
            $this->alert('La sauvegarde a échoué : '.$e->getMessage());

            throw $e;
        }
        $uploads = [];
        foreach (['google' => GoogleDriveDestination::class, 'dropbox' => DropboxDestination::class] as $key => $cls) {
            if (! $this->destination($key)) {
                continue;
            }
            try {
                app($cls)->upload($b['path'], $b['name']);
                app($cls)->prune((int) $this->settings()['keep_remote']);
                $uploads[$key] = ['status' => 'ok'];
            } catch (\Throwable $e) {
                $uploads[$key] = ['status' => 'error', 'error' => Str::limit($e->getMessage(), 300, '')];
            }
        }
        $failed = array_filter($uploads, fn ($u) => $u['status'] !== 'ok');
        $this->save(['last' => ['at' => $started->toIso8601String(), 'status' => $failed ? 'partial' : 'ok', 'file' => $b['name'], 'size' => $b['size'],
            'rows' => $b['manifest']['rows'], 'files' => $b['manifest']['files'], 'seconds' => $started->diffInSeconds(now()), 'uploads' => $uploads, 'reason' => $reason]]);
        if ($failed) {
            $this->alert('Sauvegarde faite sur le serveur, mais l\'envoi a échoué vers '.implode(', ', array_map(fn ($k) => $k === 'google' ? 'Google Drive' : 'Dropbox', array_keys($failed))).'.');
        }

        return $b + ['uploads' => $uploads];
    }

    /** Sauvegarde quotidienne à l'heure réglée (tâche planifiée). */
    public function runDue(): ?array
    {
        $s = $this->settings();
        if (! $s['enabled']) {
            return null;
        }
        $last = $s['last']['at'] ?? null;
        $tz = config('erp.defaults.timezone', 'UTC');
        $now = now($tz);
        $dueToday = $now->copy()->setTime((int) $s['hour'], 0);
        if ($now->lt($dueToday) || ($last && \Illuminate\Support\Carbon::parse($last)->tz($tz)->gte($dueToday))) {
            return null;
        }

        return $this->run('automatique');
    }

    protected function alert(string $msg): void
    {
        if (! Schema::hasTable('app_notifications')) {
            return;
        }
        $admins = DB::table('users')->where('is_active', true)->where(fn ($q) => $q->where('is_super_admin', true)->when(config('erp.mode') !== 'saas', fn ($w) => $w->orWhereNotNull('company_id')))
            ->get(['id', 'company_id', 'is_super_admin', 'role_id']);
        foreach ($admins as $u) {
            $user = \App\Models\User::find($u->id);
            if ($u->company_id && ($u->is_super_admin || $user?->hasPermission('core.settings.manage'))) {
                \App\Models\AppNotification::send((int) $u->company_id, [$u->id], 'warning', 'Sauvegarde', $msg, 'admin');
            }
        }
    }

    // ------------------------------------------------------------------ copies locales

    public function list(): array
    {
        $out = [];
        foreach (glob(self::dir().'/*.zip') ?: [] as $f) {
            $m = $this->manifest($f);
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'created_at' => $m['created_at'] ?? date(DATE_ATOM, filemtime($f)), 'version' => $m['version'] ?? null,
                'rows' => $m['rows'] ?? null, 'files' => $m['files'] ?? null, 'reason' => $m['reason'] ?? null, 'encrypted' => $m['encrypted'] ?? false];
        }
        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    public function path(string $name): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]+\.zip$/', $name) && is_file(self::dir().'/'.$name), 404);

        return self::dir().'/'.$name;
    }

    public function prune(): void
    {
        $keep = max(1, (int) $this->settings()['keep_local']);
        $all = collect(glob(self::dir().'/*.zip') ?: [])->sortByDesc(fn ($f) => filemtime($f))->values();
        foreach ($all->slice($keep) as $f) {
            @unlink($f);
        }
    }

    public function manifest(string $file): array
    {
        $z = new \ZipArchive;
        if ($z->open($file) !== true) {
            return [];
        }
        $m = json_decode((string) $z->getFromName('manifest.json'), true) ?: [];
        $z->close();

        return $m;
    }

    // ------------------------------------------------------------------ restauration

    /**
     * Restaure une archive : vérifie la clé et la version, fait une sauvegarde de sécurité de l'état actuel,
     * puis remplace le contenu des tables (seulement les colonnes connues) et les fichiers.
     */
    public function restore(string $file, ?string $passphrase = null, bool $files = true): array
    {
        @set_time_limit(0);
        $m = $this->manifest($file);
        if (($m['app'] ?? null) !== 'Yomara ERP' || empty($m['tables'])) {
            throw new \DomainException('Ce fichier n\'est pas une sauvegarde de Yomara ERP.');
        }
        if (version_compare((string) $m['version'], (string) config('erp.version'), '>')) {
            throw new \DomainException('Sauvegarde faite avec la version '.$m['version'].', plus récente que ce logiciel ('.config('erp.version').') : mettez d\'abord le logiciel à jour.');
        }
        $pass = trim((string) ($passphrase ?: $this->passphrase()));
        if (! empty($m['encrypted']) && ! empty($m['check']) && ! hash_equals($m['check'], hash('sha256', $pass.'|yomara'))) {
            throw new \DomainException('Clé de sauvegarde incorrecte.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($file) !== true) {
            throw new \DomainException('Archive illisible.');
        }
        if (! empty($m['encrypted'])) {
            $zip->setPassword($pass);
        }
        $safety = $this->create('avant restauration', false); // retour en arrière possible
        $keep = $this->settings(); // la configuration des sauvegardes de cette installation est conservée
        $existing = array_flip($this->tables());
        $restored = 0;
        Schema::disableForeignKeyConstraints();
        try {
            foreach (array_keys($m['tables']) as $t) {
                if (! isset($existing[$t]) || in_array($t, self::SKIP, true)) {
                    continue;
                }
                $stream = $zip->getStream('db/'.$t.'.jsonl');
                if (! $stream) {
                    throw new \DomainException('Table '.$t.' illisible (clé de sauvegarde incorrecte ?).');
                }
                $cols = array_flip(Schema::getColumnListing($t));
                DB::table($t)->delete();
                $batch = [];
                while (($line = fgets($stream)) !== false) {
                    $row = json_decode($line, true);
                    if (! is_array($row)) {
                        continue;
                    }
                    $batch[] = array_intersect_key($row, $cols);
                    if (count($batch) >= 300) {
                        DB::table($t)->insert($batch);
                        $restored += count($batch);
                        $batch = [];
                    }
                }
                fclose($stream);
                if ($batch) {
                    DB::table($t)->insert($batch);
                    $restored += count($batch);
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        $nf = 0;
        if ($files) {
            $root = storage_path('app');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (! str_starts_with($entry, 'files/') || str_contains($entry, '..') || str_contains($entry, '\\') || str_ends_with($entry, '/')) {
                    continue;
                }
                // Jamais de code exécutable ni de fichier de configuration du serveur web restauré
                if (preg_match('/\.(php\d?|phtml|pht|phar|htaccess|htpasswd|user\.ini|cgi|pl|py|sh|exe|html?|svg|js)$/i', $entry) || str_contains(basename($entry), '.user.ini')) {
                    continue;
                }
                $dest = $root.'/'.substr($entry, 6);
                @mkdir(dirname($dest), 0775, true);
                $in = $zip->getStream($entry);
                if ($in) {
                    $out = fopen($dest, 'w');
                    stream_copy_to_stream($in, $out);
                    fclose($out);
                    fclose($in);
                    $nf++;
                }
            }
        }
        $zip->close();
        \Illuminate\Support\Facades\Cache::flush();
        // La table des réglages vient d'être remplacée : on garde la configuration des sauvegardes de cette installation
        PlatformSetting::put('backups', array_replace($keep, ['last_restore' => ['at' => now()->toIso8601String(), 'from' => basename($file), 'version' => $m['version'], 'rows' => $restored, 'files' => $nf, 'safety' => $safety['name']]]));

        return ['rows' => $restored, 'files' => $nf, 'safety' => $safety['name'], 'version' => $m['version']];
    }
}
