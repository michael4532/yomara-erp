<?php

namespace App\Modules\Rapports\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Partage des rapports et tableaux de bord : privé (son auteur), toute la société, ou certains rôles.
 * Partager ou modifier ce qui est partagé demande « rapports.manage ».
 */
trait SharedVisibility
{
    public const VISIBILITY = ['private' => 'Privé', 'company' => 'Toute la société', 'roles' => 'Certains rôles'];

    public function isVisibleTo($user): bool
    {
        if (! $user) {
            return false;
        }
        if ($this->owner_id && (int) $this->owner_id === (int) $user->id) {
            return true;
        }

        return match ($this->visibility) {
            'company' => true,
            'roles' => in_array((int) $user->role_id, array_map('intval', $this->role_ids ?? []), true),
            default => false,
        };
    }

    public function canEdit($user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasPermission('rapports.manage')) {
            return $this->visibility !== 'private' || ! $this->owner_id || (int) $this->owner_id === (int) $user->id;
        }

        return $this->owner_id && (int) $this->owner_id === (int) $user->id && $this->visibility === 'private';
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        $ids = static::query()->get(['id', 'owner_id', 'visibility', 'role_ids'])->filter(fn ($r) => $r->isVisibleTo($user))->pluck('id')->all();
        $q->whereIn('id', $ids ?: ['00000000-0000-0000-0000-000000000000']);
    }

    /** Contrôles communs à l'écriture (appelés depuis beforeEntityWrite). */
    protected function guardSharing(array &$data, bool $creating): void
    {
        $user = auth()->user();
        if (! $creating && ! $this->canEdit($user)) {
            throw new \DomainException('Seul son auteur ou un responsable des rapports peut le modifier.');
        }
        if ($creating) {
            $this->owner_id = $user?->id;
        }
        $visibility = $data['visibility'] ?? ($creating ? 'private' : $this->visibility);
        if ($visibility !== 'private' && ! $user?->hasPermission('rapports.manage')) {
            throw new \DomainException('Le partage avec la société ou des rôles est réservé aux responsables des rapports.');
        }
        if (array_key_exists('role_ids', $data) || array_key_exists('visibility', $data)) {
            $roles = $visibility === 'roles' ? array_values(array_unique(array_map('intval', (array) ($data['role_ids'] ?? $this->role_ids ?? [])))) : [];
            if ($visibility === 'roles') {
                $valid = \App\Models\Role::whereIn('id', $roles)->pluck('id')->map(fn ($i) => (int) $i)->all();
                if (! $valid) {
                    throw new \DomainException('Choisissez au moins un rôle.');
                }
                $roles = $valid;
            }
            $data['role_ids'] = $roles;
        }
    }

    public function beforeEntityDelete(): void
    {
        if (! $this->canEdit(auth()->user())) {
            throw new \DomainException('Seul son auteur ou un responsable des rapports peut le supprimer.');
        }
    }
}
