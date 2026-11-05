<?php

namespace App\Http\Controllers\Api;

use App\Backups\BackupService;
use App\Backups\Destination;
use App\Backups\DropboxDestination;
use App\Backups\GoogleDriveDestination;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** Sauvegardes de l'installation (administrateur du logiciel ; super administrateur en mode plateforme). */
class BackupController extends Controller
{
    public function __construct(protected BackupService $backups) {}

    protected function authorizeAdmin(Request $r): void
    {
        $u = $r->user();
        abort_unless($u && ($u->is_super_admin || (config('erp.mode') !== 'saas' && $u->hasPermission('core.settings.manage'))), 403, 'Réservé à l\'administrateur du logiciel.');
    }

    protected function dest(string $p): Destination
    {
        return match ($p) {
            'google' => app(GoogleDriveDestination::class),
            'dropbox' => app(DropboxDestination::class),
            default => abort(404),
        };
    }

    public function index(Request $r)
    {
        $this->authorizeAdmin($r);
        $s = $this->backups->settings();
        $dest = [];
        foreach (['google' => GoogleDriveDestination::class, 'dropbox' => DropboxDestination::class] as $k => $cls) {
            $d = (array) ($s['destinations'][$k] ?? []);
            $dest[$k] = ['connected' => (bool) $d, 'email' => $d['email'] ?? null, 'connected_at' => $d['connected_at'] ?? null, 'redirect_uri' => $cls::redirectUri(),
                'configured' => $k === 'google' ? (bool) (config('erp.mailbox.google_client_id') && config('erp.mailbox.google_client_secret'))
                    : (bool) ((config('erp.backups.dropbox_key') || ! empty($s['dropbox_app_key'])) && (config('erp.backups.dropbox_secret') || ! empty($s['dropbox_app_secret'])))];
        }

        return ['settings' => array_intersect_key($s, BackupService::DEFAULTS), 'passphrase' => $this->backups->passphrase(), 'encrypted' => BackupService::encryptionSupported(),
            'destinations' => $dest, 'last' => $s['last'] ?? null, 'last_restore' => $s['last_restore'] ?? null, 'backups' => $this->backups->list(),
            'dropbox_app_key' => $s['dropbox_app_key'] ?? null, 'mode' => config('erp.mode'), 'version' => config('erp.version'),
            'disk_free' => @disk_free_space(BackupService::dir()) ?: null];
    }

    public function update(Request $r)
    {
        $this->authorizeAdmin($r);
        $d = $r->validate(['enabled' => 'sometimes|boolean', 'hour' => 'sometimes|integer|min:0|max:23', 'keep_local' => 'sometimes|integer|min:1|max:90', 'keep_remote' => 'sometimes|integer|min:1|max:365',
            'include_files' => 'sometimes|boolean', 'passphrase' => 'sometimes|string|min:12|max:100', 'dropbox_app_key' => 'sometimes|nullable|string|max:100', 'dropbox_app_secret' => 'sometimes|nullable|string|max:100']);
        if (isset($d['passphrase'])) {
            $this->backups->setPassphrase($d['passphrase']);
        }
        $patch = array_intersect_key($d, BackupService::DEFAULTS);
        if (array_key_exists('dropbox_app_key', $d)) {
            $patch['dropbox_app_key'] = $d['dropbox_app_key'] ?: null;
        }
        if (! empty($d['dropbox_app_secret'])) {
            $patch['dropbox_app_secret'] = Crypt::encryptString($d['dropbox_app_secret']);
        }
        $this->backups->save($patch);

        return $this->index($r);
    }

    public function run(Request $r)
    {
        $this->authorizeAdmin($r);
        try {
            $b = $this->backups->run('manuelle');
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Sauvegarde impossible : '.$e->getMessage()], 422);
        }

        return ['data' => ['name' => $b['name'], 'size' => $b['size'], 'uploads' => $b['uploads'], 'rows' => $b['manifest']['rows'], 'files' => $b['manifest']['files']]];
    }

    public function link(Request $r, string $name)
    {
        $this->authorizeAdmin($r);
        $this->backups->path($name);

        return ['url' => URL::temporarySignedRoute('backups.file', now()->addMinutes(15), ['name' => $name])];
    }

    public function file(string $name)
    {
        return response()->download($this->backups->path($name), $name, ['Content-Type' => 'application/zip']);
    }

    public function destroy(Request $r, string $name)
    {
        $this->authorizeAdmin($r);
        @unlink($this->backups->path($name));

        return response()->noContent();
    }

    public function connect(Request $r, string $provider)
    {
        $this->authorizeAdmin($r);
        try {
            return ['url' => $this->dest($provider)->connectUrl($r->user()->id)];
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function disconnect(Request $r, string $provider)
    {
        $this->authorizeAdmin($r);
        $this->dest($provider);
        $this->backups->setDestination($provider, null);

        return $this->index($r);
    }

    public function remote(Request $r, string $provider)
    {
        $this->authorizeAdmin($r);
        try {
            return ['data' => $this->dest($provider)->list()];
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function restore(Request $r)
    {
        $this->authorizeAdmin($r);
        // Remplacer toute la base : réservé au compte propriétaire du logiciel (pas à un simple gestionnaire)
        abort_unless($r->user()->is_super_admin, 403, 'La restauration est réservée au propriétaire du logiciel (compte créé à l\'installation).');
        $d = $r->validate(['name' => 'nullable|string|max:120', 'provider' => 'nullable|in:google,dropbox', 'remote_id' => 'nullable|string|max:300', 'passphrase' => 'nullable|string|max:100',
            'files' => 'sometimes|boolean', 'archive' => 'nullable|file|max:2048000', 'confirm' => 'required|accepted']);
        $tmp = null;
        try {
            if ($r->hasFile('archive')) {
                $tmp = BackupService::dir().'/import-'.Str::random(8).'.zip';
                $r->file('archive')->move(dirname($tmp), basename($tmp));
                $file = $tmp;
            } elseif (! empty($d['provider']) && ! empty($d['remote_id'])) {
                $tmp = BackupService::dir().'/import-'.Str::random(8).'.zip';
                $this->dest($d['provider'])->download($d['remote_id'], $tmp);
                $file = $tmp;
            } else {
                $file = $this->backups->path((string) ($d['name'] ?? ''));
            }
            $res = $this->backups->restore($file, $d['passphrase'] ?? null, $r->boolean('files', true));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Restauration interrompue : '.$e->getMessage()], 422);
        } finally {
            if ($tmp) {
                @unlink($tmp);
            }
        }

        return ['data' => $res];
    }

    /** Retour OAuth de Google Drive / Dropbox (page publique). */
    public function callback(Request $r, string $provider)
    {
        try {
            if ($r->filled('error')) {
                throw new \DomainException('Connexion refusée : '.$r->query('error_description', $r->query('error')));
            }
            $d = $this->dest($provider)->complete((string) $r->query('state'), (string) $r->query('code'));
            $ok = true;
            $msg = ($provider === 'google' ? 'Google Drive' : 'Dropbox').' connecté'.(! empty($d['email']) ? ' ('.e($d['email']).')' : '').'. Vos sauvegardes y seront envoyées chaque nuit.';
        } catch (\Throwable $e) {
            $ok = false;
            $msg = e($e->getMessage());
        }

        return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sauvegardes</title>'
            .'<body style="font-family:system-ui;background:#F4F6FB;display:flex;align-items:center;justify-content:center;min-height:90vh"><div style="background:#fff;padding:28px;border-radius:16px;max-width:460px;text-align:center">'
            .'<div style="font-size:42px">'.($ok ? '✓' : '!').'</div><h2>'.($ok ? 'C\'est fait' : 'Échec').'</h2><p>'.$msg.'</p><p style="color:#5B6B85">Vous pouvez fermer cette page et revenir au logiciel.</p></div></body>');
    }
}
