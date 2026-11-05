<?php
/*
 * Yomara ERP — restauration de secours après une mise à jour.
 * Fonctionne sans Laravel : si la nouvelle version ne démarre pas, l'application appelle
 * restore.php?token=… (jeton remis au moment de la mise à jour) pour remettre la version précédente.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$base = dirname(__DIR__);
$rescue = $base.'/storage/app/updates/rescue.json';
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$d = is_file($rescue) ? json_decode((string) file_get_contents($rescue), true) : null;
if (! $d || $token === '' || ! hash_equals((string) ($d['token'] ?? ''), hash('sha256', $token))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Jeton de restauration invalide ou expiré.']);
    exit;
}
if (empty($d['backup']) || ! is_file($d['backup']) || ! class_exists('ZipArchive')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Sauvegarde introuvable.']);
    exit;
}
@set_time_limit(900);
$protected = static function (string $rel): bool {
    foreach (['.env', 'storage', 'public/storage', 'bootstrap/cache'] as $p) {
        if ($rel === $p || strpos($rel, $p.'/') === 0) {
            return true;
        }
    }

    return (bool) preg_match('#^database/.*\.sqlite$#', $rel);
};
$code = ['app/', 'bootstrap/app.php', 'bootstrap/providers.php', 'config/', 'database/migrations/', 'database/seeders/', 'database/factories/', 'lang/', 'public/', 'resources/', 'routes/', 'vendor/', 'artisan', 'composer.json', 'composer.lock'];
$isCode = static function (string $rel) use ($code): bool {
    foreach ($code as $c) {
        if ($rel === $c || strpos($rel, $c) === 0) {
            return true;
        }
    }

    return false;
};
$zip = new ZipArchive;
$zip->open($d['backup']);
$n = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $rel = $zip->getNameIndex($i);
    if (substr($rel, -1) === '/' || strpos($rel, '..') !== false || $protected($rel)) {
        continue;
    }
    $dest = $base.'/'.$rel;
    if (! is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0775, true);
    }
    file_put_contents($dest.'.upd-tmp', $zip->getFromIndex($i));
    rename($dest.'.upd-tmp', $dest);
    $n++;
}
// Fichiers ajoutés par la version défectueuse
$removed = 0;
$list = $base.'/storage/app/updates/installed-files.json';
if (is_file($list)) {
    foreach (json_decode((string) file_get_contents($list), true) ?: [] as $rel) {
        if ($zip->locateName($rel) === false && $isCode($rel) && ! $protected($rel) && is_file($base.'/'.$rel)) {
            @unlink($base.'/'.$rel);
            $removed++;
        }
    }
}
$zip->close();
// Base SQLite : copie de la sauvegarde prise avant les migrations
$db = false;
if (! empty($d['db_backup']) && is_file($d['db_backup']) && substr($d['db_backup'], -7) === '.sqlite') {
    $env = is_file($base.'/.env') ? (string) file_get_contents($base.'/.env') : '';
    $target = preg_match('/^DB_DATABASE=(.+)$/m', $env, $m) ? trim($m[1], " \"'\r") : $base.'/database/database.sqlite';
    $db = @copy($d['db_backup'], $target);
}
foreach (glob($base.'/bootstrap/cache/*.php') ?: [] as $f) {
    @unlink($f);
}
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
@unlink($base.'/storage/app/updates/lock');
$d['token'] = null; // jeton à usage unique
file_put_contents($rescue, json_encode($d));
echo json_encode(['ok' => true, 'version' => $d['from'] ?? null, 'files' => $n, 'removed' => $removed, 'database' => $db,
    'message' => 'Version '.($d['from'] ?? 'précédente').' restaurée. Rechargez l\'application.']);
