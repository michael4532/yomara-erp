<?php

namespace App\Modules\Documents;

use App\Modules\Documents\Models\Folder;
use Illuminate\Support\Collection;

/**
 * Droits d'accès aux dossiers : chaque dossier hérite de son parent, sauf s'il fixe
 * « tout le monde », « certains rôles » ou « privé » (son créateur seulement).
 * Les administrateurs des documents voient tout.
 */
class DocumentAccess
{
    protected static array $cache = [];

    public static function flush(): void
    {
        static::$cache = [];
    }

    /** @return Collection<string, Folder> */
    public static function folders(): Collection
    {
        $key = app()->bound('tenant') ? app('tenant')->id : 0;

        return static::$cache[$key] ??= Folder::query()->get(['id', 'parent_id', 'access', 'role_ids', 'owner_id', 'name', 'system_key'])->keyBy('id');
    }

    public static function isAdmin($user): bool
    {
        return $user && $user->hasPermission('documents.admin');
    }

    /** Règle effective d'un dossier (null : racine, ouverte à tous). */
    public static function effective(?string $folderId): array
    {
        $all = static::folders();
        $seen = [];
        while ($folderId && ($f = $all->get($folderId)) && ! isset($seen[$folderId])) {
            $seen[$folderId] = true;
            if ($f->access && $f->access !== 'inherit') {
                return ['access' => $f->access, 'role_ids' => array_map('intval', (array) $f->role_ids), 'owner_id' => $f->owner_id, 'folder' => $f->id];
            }
            $folderId = $f->parent_id;
        }

        return ['access' => 'all', 'role_ids' => [], 'owner_id' => null, 'folder' => null];
    }

    public static function canSee($user, ?string $folderId): bool
    {
        if (! $user) {
            return false;
        }
        if ($folderId && ! static::folders()->has($folderId)) {
            return false;
        }
        if (static::isAdmin($user)) {
            return true;
        }
        $e = static::effective($folderId);

        return match ($e['access']) {
            'roles' => in_array((int) $user->role_id, $e['role_ids'], true),
            'private' => (int) $e['owner_id'] === (int) $user->id,
            default => true,
        };
    }

    /** Identifiants des dossiers visibles (null : tous). */
    public static function visibleFolderIds($user): ?array
    {
        if (static::isAdmin($user)) {
            return null;
        }

        return static::folders()->keys()->filter(fn ($id) => static::canSee($user, $id))->values()->all();
    }

    /** Le dossier $id est-il $ancestor ou l'un de ses sous-dossiers ? */
    public static function within(?string $id, string $ancestor): bool
    {
        $all = static::folders();
        $seen = [];
        while ($id && ! isset($seen[$id])) {
            if ($id === $ancestor) {
                return true;
            }
            $seen[$id] = true;
            $id = $all->get($id)?->parent_id;
        }

        return false;
    }

    public static function path(?string $folderId): string
    {
        $all = static::folders();
        $parts = [];
        $seen = [];
        while ($folderId && ($f = $all->get($folderId)) && ! isset($seen[$folderId])) {
            $seen[$folderId] = true;
            array_unshift($parts, $f->name);
            $folderId = $f->parent_id;
        }

        return implode(' / ', $parts);
    }
}
