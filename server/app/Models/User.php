<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'company_id', 'role_id', 'manager_id', 'job_title', 'name', 'email', 'phone', 'password', 'is_super_admin', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'permission_grants' => 'array',
            'permission_denies' => 'array',
            'modules_denied' => 'array',
            'ui_prefs' => 'array',
        ];
    }

    /** E-mails toujours stockés en minuscules (connexion insensible à la casse). */
    protected function email(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(set: fn ($v) => strtolower(trim((string) $v)));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScopes();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        // Alternatives : "ventes.manage|pos.use" = l'une ou l'autre suffit.
        foreach (explode('|', $permission) as $p) {
            if ($this->deniedByUser($p)) {
                continue;
            }
            if ($this->role?->allows($p) || $this->grantedByUser($p)) {
                return true;
            }
        }

        return false;
    }

    /** Droit retiré à cet utilisateur, directement ou parce que le module lui est masqué. */
    public function deniedByUser(string $p): bool
    {
        foreach ((array) $this->permission_denies as $d) {
            if ($d === $p || fnmatch($d, $p)) {
                return true;
            }
        }
        $mods = (array) $this->modules_denied;
        if ($mods) {
            $module = app(\App\Support\ModuleRegistry::class)->permissions()[$p]['module'] ?? null;
            if ($module && in_array($module, $mods, true)) {
                return true;
            }
        }

        return false;
    }

    protected function grantedByUser(string $p): bool
    {
        foreach ((array) $this->permission_grants as $g) {
            if ($g === $p || Role::wildcardGrants($g, $p)) {
                return true;
            }
        }

        return false;
    }

    public function permissions(): array
    {
        return $this->is_super_admin ? ['*'] : array_values(array_unique(array_merge($this->role?->permissions ?? [], (array) $this->permission_grants)));
    }

    /** Droits explicitement refusés (pour l'application : priment sur les jokers du rôle). */
    public function deniedPermissions(): array
    {
        if ($this->is_super_admin) {
            return [];
        }
        $out = (array) $this->permission_denies;
        $mods = (array) $this->modules_denied;
        if ($mods) {
            foreach (app(\App\Support\ModuleRegistry::class)->permissions() as $perm => $m) {
                if (in_array($m['module'], $mods, true)) {
                    $out[] = $perm;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Modules de la société accessibles à cet utilisateur. */
    public function allowedModules(array $companyModules): array
    {
        $deny = $this->is_super_admin ? [] : (array) $this->modules_denied;

        return array_values(array_diff($companyModules, array_diff($deny, ['core'])));
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subSeconds(config('erp.presence_window')));
    }
}
