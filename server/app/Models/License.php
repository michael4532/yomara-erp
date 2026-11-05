<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class License extends Model
{
    protected $fillable = [
        'owner_company_id', 'company_id', 'created_by', 'key', 'company_name', 'target', 'status', 'fingerprint',
        'device_name', 'device', 'install_url', 'installed_company', 'app_version', 'users_count', 'resets',
        'activated_at', 'last_seen_at', 'last_ip', 'notes',
    ];

    protected $hidden = ['fingerprint'];

    public const TARGETS = ['server' => 'Serveur web (hébergement)', 'windows' => 'PC Windows', 'mac' => 'Mac', 'linux' => 'PC Linux'];

    public const STATUSES = ['unused' => 'En attente', 'active' => 'Reliée', 'suspended' => 'Suspendue', 'revoked' => 'Déliée'];

    /** Une installation qui a contacté le serveur depuis moins de 7 h est « en ligne » (vérification toutes les 6 h). */
    public const ONLINE_HOURS = 7;

    protected function casts(): array
    {
        return ['device' => 'array', 'activated_at' => 'datetime', 'last_seen_at' => 'datetime', 'users_count' => 'integer', 'resets' => 'integer'];
    }

    public function ownerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'owner_company_id');
    }

    /** Société « licence » qui porte les modules achetés (hors des listes habituelles). */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withoutGlobalScope('real');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LicenseEvent::class);
    }

    public function isOnline(): bool
    {
        return $this->status === 'active' && $this->last_seen_at && $this->last_seen_at->gt(now()->subHours(self::ONLINE_HOURS));
    }

    public function maskedKey(): string
    {
        return substr($this->key, 0, 9).'…'.substr($this->key, -5);
    }

    public function log(string $type, array $data = [], ?string $ip = null): void
    {
        $this->events()->create(['type' => $type, 'data' => $data ?: null, 'ip' => $ip ?? request()?->ip(), 'created_at' => now()]);
    }
}
