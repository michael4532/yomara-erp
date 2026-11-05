<?php

namespace App\Services\Updates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Diffusion des mises à jour (plateforme Yomara) : paquet construit depuis ce serveur ou envoyé par morceaux,
 * version publiée sur un canal, interrogée par les installations clientes.
 */
class Publisher
{
    /** Chemins jamais diffusés (secrets, données, caches). */
    public const EXCLUDE = ['.env', '.env.backup', '.git', 'node_modules', 'storage', 'tests', 'database/database.sqlite', 'public/storage', 'public/hot', 'bootstrap/cache',
        'public/uploads', 'public/downloads', 'deploy'];

    /** Seul le code est diffusé (liste blanche) : jamais les fichiers des clients ni ce qui traîne à la racine. */
    public const INCLUDE = ['app', 'bootstrap', 'config', 'database/migrations', 'database/seeders', 'database/factories', 'lang', 'public', 'resources', 'routes', 'vendor',
        'artisan', 'composer.json', 'composer.lock', 'README.md', 'LICENSE', 'NOTICE.md', '.htaccess', 'index.php', 'package.json'];

    public static function dir(): string
    {
        $d = storage_path('app/releases');
        if (! is_dir($d)) {
            mkdir($d, 0775, true);
        }

        return $d;
    }

    protected static function excluded(string $rel): bool
    {
        $top = collect(self::INCLUDE)->contains(fn ($x) => $rel === $x || str_starts_with($rel, $x.'/') || str_starts_with($x, $rel.'/'));
        if (! $top) {
            return true;
        }
        // Fichiers déposés par les utilisateurs : seul le .htaccess de protection du dossier est diffusé
        if (str_starts_with($rel, 'public/uploads/') && $rel !== 'public/uploads/.htaccess') {
            return true;
        }
        foreach (self::EXCLUDE as $x) {
            if ($x === 'public/uploads') {
                continue;
            }
            if ($rel === $x || str_starts_with($rel, $x.'/')) {
                return true;
            }
        }

        return (bool) preg_match('#^database/.*\.sqlite$#', $rel);
    }

    /** Paquet de la version installée sur ce serveur (racine « yomara-erp/ », comme les paquets livrés). */
    public function packageSelf(string $channel, ?string $notes, bool $mandatory): array
    {
        @set_time_limit(900);
        $version = config('erp.version');
        $path = self::dir().'/yomara-erp-'.$version.'-'.$channel.'.zip';
        @unlink($path);
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \DomainException('Impossible de créer le paquet dans storage/app/releases.');
        }
        $base = rtrim(base_path(), '/');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($base))), '/');
            if (self::excluded($rel) || $f->isLink()) {
                continue;
            }
            $f->isDir() ? $zip->addEmptyDir('yomara-erp/'.$rel) : $zip->addFile($f->getPathname(), 'yomara-erp/'.$rel);
        }
        foreach (['storage/app/.gitignore', 'storage/framework/cache/.gitignore', 'storage/framework/sessions/.gitignore', 'storage/framework/views/.gitignore', 'storage/logs/.gitignore', 'bootstrap/cache/.gitignore'] as $keep) {
            $zip->addFromString('yomara-erp/'.$keep, "*\n!.gitignore\n");
        }
        $zip->close();

        return $this->register($path, $channel, $notes, $mandatory, $version);
    }

    /** Lit la version d'un paquet (config/erp.php) et vérifie sa structure. */
    public static function inspect(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \DomainException('Le fichier n\'est pas une archive ZIP valide.');
        }
        $root = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^(.*/)?config/erp\.php$#', $n, $m) && substr_count($m[1] ?? '', '/') <= 1) {
                $root = $m[1] ?? '';
                break;
            }
        }
        if ($root === null) {
            $zip->close();
            throw new \DomainException('Ce paquet ne contient pas Yomara ERP (config/erp.php introuvable).');
        }
        $cfg = (string) $zip->getFromName($root.'config/erp.php');
        $hasVendor = $zip->locateName($root.'vendor/autoload.php') !== false;
        $zip->close();
        if (! preg_match("#'version'\s*=>\s*'([0-9][0-9.]*[0-9a-z.-]*)'#", $cfg, $m)) {
            throw new \DomainException('Version introuvable dans le paquet.');
        }
        if (! $hasVendor) {
            throw new \DomainException('Paquet incomplet : le dossier vendor est absent.');
        }

        return ['version' => $m[1], 'root' => $root];
    }

    public function register(string $path, string $channel, ?string $notes, bool $mandatory, ?string $version = null): array
    {
        $info = self::inspect($path);
        $version ??= $info['version'];
        $final = self::dir().'/yomara-erp-'.$version.'-'.$channel.'.zip';
        if ($path !== $final) {
            @unlink($final);
            rename($path, $final);
        }
        $sha = hash_file('sha256', $final);
        DB::table('upd_releases')->updateOrInsert(['version' => $version, 'channel' => $channel], [
            'notes' => $notes, 'path' => $final, 'size' => filesize($final), 'sha256' => $sha, 'mandatory' => $mandatory,
            'signature' => \App\Licensing\LicenseSigner::signMessage(\App\Licensing\LicenseSigner::releaseMessage($version, $sha)),
            'php_min' => '8.2', 'published' => false, 'updated_at' => now(), 'created_at' => now(),
        ]);

        return (array) DB::table('upd_releases')->where('version', $version)->where('channel', $channel)->first();
    }

    /** Envoi par morceaux (hébergements limités à quelques Mo par requête). */
    public function chunk(string $uploadId, int $index, int $total, string $data): ?string
    {
        $uploadId = preg_replace('/[^a-z0-9-]/i', '', $uploadId);
        $tmp = self::dir().'/upload-'.$uploadId.'.part';
        if ($index === 0) {
            @unlink($tmp);
        }
        file_put_contents($tmp, $data, FILE_APPEND);

        return $index === $total - 1 ? $tmp : null;
    }

    /** Dernière version publiée plus récente que $current sur le canal. */
    public function latest(string $channel, ?string $current): ?array
    {
        $channels = $channel === 'beta' ? ['stable', 'beta'] : ['stable'];
        $best = null;
        foreach (DB::table('upd_releases')->where('published', true)->whereIn('channel', $channels)->get() as $r) {
            if (! $best || version_compare($r->version, $best->version, '>')) {
                $best = $r;
            }
        }
        if (! $best) {
            return null;
        }
        // Une mise à jour obligatoire intermédiaire rend l'ensemble obligatoire
        $mandatory = $current && DB::table('upd_releases')->where('published', true)->whereIn('channel', $channels)->where('mandatory', true)->get()
            ->contains(fn ($r) => version_compare($r->version, $current, '>'));

        return ['version' => $best->version, 'channel' => $best->channel, 'notes' => $best->notes, 'size' => (int) $best->size, 'sha256' => $best->sha256, 'signature' => $best->signature ?? null,
            'php_min' => $best->php_min, 'mandatory' => (bool) ($best->mandatory || $mandatory), 'published_at' => $best->published_at,
            'apps' => (object) (json_decode((string) $best->apps, true) ?: []), 'download' => url('/api/updates/download/'.$best->id.'/'.$best->sha256)];
    }

    public function recordInstallation(array $d, ?string $ip): void
    {
        if (empty($d['install_id']) || ! preg_match('/^[a-f0-9-]{16,64}$/i', $d['install_id'])) {
            return;
        }
        DB::table('upd_installations')->updateOrInsert(['install_id' => $d['install_id']], array_filter([
            'name' => isset($d['name']) ? mb_substr((string) $d['name'], 0, 190) : null, 'url' => isset($d['url']) ? mb_substr((string) $d['url'], 0, 255) : null,
            'version' => isset($d['version']) ? mb_substr((string) $d['version'], 0, 20) : null, 'php' => isset($d['php']) ? mb_substr((string) $d['php'], 0, 20) : null,
            'os' => isset($d['os']) ? mb_substr((string) $d['os'], 0, 40) : null, 'database' => isset($d['db']) ? mb_substr((string) $d['db'], 0, 20) : null,
            'ip' => $ip, 'last_seen_at' => now(), 'updated_at' => now(),
        ], fn ($v) => $v !== null) + ['created_at' => now()]);
    }
}
