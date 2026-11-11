<?php

namespace App\Storage;

use App\Models\StorageBackend;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Plusieurs stockages pour les documents, avec bascule automatique :
 * un fichier va dans le premier stockage actif (ordre de priorité) qui a encore la place ;
 * si ce stockage est plein ou ne répond pas, le suivant prend le relais. Chaque version garde son stockage.
 */
class StoragePool
{
    /** Marge gardée libre sur chaque stockage (évite de le remplir à ras bord). */
    public const MARGIN = 0.98;

    public static function enabled(): bool
    {
        return Schema::hasTable('storage_backends');
    }

    public function client(StorageBackend $b): S3Lite
    {
        $c = new S3Lite((string) $b->endpoint, (string) $b->region, (string) $b->bucket, (string) $b->access_key, (string) $b->secret_key);
        $c->allowPrivate = $b->provider === 'minio';

        return $c;
    }

    /** Dépose le fichier ; renvoie l'identifiant du stockage utilisé (null : disque du serveur, sans table des stockages). */
    public function put(string $path, string $bytes, string $mime = 'application/octet-stream'): ?int
    {
        if (! self::enabled()) {
            Storage::disk('local')->put($path, $bytes);

            return null;
        }
        $size = strlen($bytes);
        $errors = [];
        foreach (StorageBackend::where('is_active', true)->orderBy('priority')->orderBy('id')->get() as $b) {
            if ($b->capacity_mb && $b->used_bytes + $size > $b->capacity_mb * 1048576 * self::MARGIN) {
                continue; // plein : on passe au suivant
            }
            try {
                $b->isLocal() ? Storage::disk('local')->put($path, $bytes) : $this->client($b)->put($path, $bytes, $mime);
                $b->increment('used_bytes', $size);
                $b->forceFill(['last_ok_at' => now()])->saveQuietly();

                return $b->id;
            } catch (\Throwable $e) {
                $b->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 480), 'last_error_at' => now()])->saveQuietly();
                $errors[] = $b->name;
            }
        }
        throw new \DomainException($errors
            ? 'Aucun stockage n\'a accepté le fichier ('.implode(', ', $errors).'). Réessayez dans un instant.'
            : 'Tous les stockages de la plateforme sont pleins. Prévenez l\'éditeur.');
    }

    public function get(?int $backendId, string $path): string
    {
        $b = $backendId && self::enabled() ? StorageBackend::find($backendId) : null;
        if (! $b || $b->isLocal()) {
            return Storage::disk('local')->get($path) ?? throw new \RuntimeException('Fichier introuvable.');
        }

        return $this->client($b)->get($path);
    }

    public function exists(?int $backendId, string $path): bool
    {
        $b = $backendId && self::enabled() ? StorageBackend::find($backendId) : null;

        return ! $b || $b->isLocal() ? Storage::disk('local')->exists($path) : true;
    }

    /** Chemin sur le disque du serveur, ou copie temporaire pour un stockage externe (à supprimer après usage). */
    public function localPath(?int $backendId, string $path): array
    {
        $b = $backendId && self::enabled() ? StorageBackend::find($backendId) : null;
        if (! $b || $b->isLocal()) {
            return [Storage::disk('local')->path($path), false];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ged');
        file_put_contents($tmp, $this->client($b)->get($path));

        return [$tmp, true];
    }

    public function delete(?int $backendId, string $path, int $size = 0): void
    {
        $b = $backendId && self::enabled() ? StorageBackend::find($backendId) : null;
        try {
            ! $b || $b->isLocal() ? Storage::disk('local')->delete($path) : $this->client($b)->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }
        if ($b && $size) {
            $b->forceFill(['used_bytes' => max(0, $b->used_bytes - $size)])->saveQuietly();
        }
    }

    /** Essai : dépose, relit et supprime un petit fichier. */
    public function test(StorageBackend $b): array
    {
        $path = 'yomara-test/'.bin2hex(random_bytes(6)).'.txt';
        $probe = 'Yomara ERP '.now()->toIso8601String();
        try {
            if ($b->isLocal()) {
                Storage::disk('local')->put($path, $probe);
                $ok = Storage::disk('local')->get($path) === $probe;
                Storage::disk('local')->delete($path);
            } else {
                $c = $this->client($b);
                $c->put($path, $probe, 'text/plain');
                $ok = $c->get($path) === $probe;
                $c->delete($path);
            }
            if (! $ok) {
                throw new \RuntimeException('Le fichier relu ne correspond pas.');
            }
            $b->forceFill(['last_ok_at' => now(), 'last_error' => null])->saveQuietly();

            return ['ok' => true, 'message' => 'Connexion réussie : dépôt, lecture et suppression fonctionnent.'];
        } catch (\Throwable $e) {
            $b->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 480), 'last_error_at' => now()])->saveQuietly();

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
