<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\LandingController;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

/**
 * Logiciels à télécharger (Windows, macOS, Linux, Android, serveur) déposés dans public/downloads : envoi par morceaux
 * (hébergements limités à quelques Mo par requête), liste et suppression. La page « Télécharger » les propose aussitôt.
 */
class DownloadsAdminController extends Controller
{
    public const EXT = '/\.(exe|msi|zip|dmg|appimage|deb|rpm|tar\.gz|apk|aab|txt|asc|sig)$/i';

    protected function dir(): string
    {
        $d = public_path('downloads');
        if (! is_dir($d)) {
            @mkdir($d, 0755, true);
        }
        // jamais de script exécuté depuis ce dossier
        if (! is_file($d.'/.htaccess')) {
            @file_put_contents($d.'/.htaccess', "Options -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");
        }

        return $d;
    }

    public function index()
    {
        $files = [];
        foreach (glob($this->dir().'/*') ?: [] as $f) {
            if (is_file($f) && basename($f) !== '.htaccess') {
                $files[] = ['name' => basename($f), 'size' => filesize($f), 'updated_at' => date('c', filemtime($f)), 'url' => url('/downloads/'.rawurlencode(basename($f))),
                    'sha256' => filesize($f) < 600 * 1048576 ? hash_file('sha256', $f) : null];
            }
        }
        usort($files, fn ($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

        return ['data' => $files, 'detected' => LandingController::downloads(), 'server' => LandingController::serverZip()];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['upload_id' => 'required|alpha_num|max:64', 'index' => 'required|integer|min:0', 'total' => 'required|integer|min:1|max:4000',
            'name' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9][A-Za-z0-9._+-]*$/', 'regex:'.self::EXT], 'chunk' => 'required|file']);
        $tmp = storage_path('app/downloads-upload/'.$d['upload_id']);
        @mkdir($tmp, 0750, true);
        move_uploaded_file($r->file('chunk')->getRealPath(), $tmp.'/'.str_pad((string) $d['index'], 5, '0', STR_PAD_LEFT)) || copy($r->file('chunk')->getRealPath(), $tmp.'/'.str_pad((string) $d['index'], 5, '0', STR_PAD_LEFT));
        $parts = glob($tmp.'/*') ?: [];
        if (count($parts) < (int) $d['total']) {
            return ['received' => count($parts), 'total' => (int) $d['total']];
        }
        sort($parts);
        $final = $this->dir().'/'.$d['name'];
        $out = fopen($final.'.part', 'wb');
        foreach ($parts as $p) {
            $in = fopen($p, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            @unlink($p);
        }
        fclose($out);
        @rmdir($tmp);
        rename($final.'.part', $final);
        @chmod($final, 0644);
        ActivityLog::record('admin.downloads.upload', null, ['name' => $d['name'], 'size' => filesize($final)]);

        return response()->json(['data' => ['name' => $d['name'], 'size' => filesize($final), 'sha256' => hash_file('sha256', $final), 'url' => url('/downloads/'.rawurlencode($d['name']))]], 201);
    }

    public function destroy(string $name)
    {
        abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/', $name), 404);
        $f = $this->dir().'/'.$name;
        abort_unless(is_file($f), 404);
        @unlink($f);
        ActivityLog::record('admin.downloads.delete', null, ['name' => $name]);

        return response()->noContent();
    }
}
