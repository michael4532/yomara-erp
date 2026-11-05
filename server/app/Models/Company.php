<?php

namespace App\Models;

use App\Support\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    protected $fillable = [
        'name', 'slug', 'email', 'phone', 'address', 'city', 'country', 'currency',
        'tax_id', 'trade_register', 'logo', 'plan_id', 'status', 'trial_ends_at',
        'subscription_ends_at', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Sociétés « licence » (installations chez les clients) : jamais dans les listes, connexions ou tâches de la plateforme
        static::addGlobalScope('real', fn ($q) => $q->where('companies.status', '!=', 'license'));
        static::creating(function (Company $c) {
            if (! $c->slug) {
                $base = Str::slug($c->name) ?: 'societe';
                $slug = $base;
                $i = 1;
                while (static::withoutGlobalScope('real')->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.(++$i);
                }
                $c->slug = $slug;
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function moduleOverrides(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    public function isSuspended(): bool
    {
        if ($this->status === 'suspended') {
            return true;
        }
        if ($this->status === 'trial' && $this->trial_ends_at && $this->trial_ends_at->isPast()) {
            return true;
        }
        // Abonnement à la formule échu et non renouvelé (après le délai de grâce) : accès suspendu
        if (config('erp.mode') === 'saas' && $this->subscription_ends_at && $this->subscription_ends_at->lt(now()->subDays((int) config('erp.subscription_grace_days', 7)))) {
            return true;
        }

        return false;
    }

    /** Clés des modules réellement utilisables par cette société. */
    public function enabledModules(): array
    {
        return app(ModuleRegistry::class)->enabledFor($this);
    }

    public function hasModule(string $key): bool
    {
        return in_array($key, $this->enabledModules(), true);
    }

    public function setting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default ?? config('erp.defaults.'.$key));
    }

    /**
     * Réglages visibles par un utilisateur : les données sensibles (paie, coût horaire de chaque salarié) ne sont
     * envoyées qu'à ceux qui les gèrent.
     */
    public function settingsFor(?User $u): array
    {
        $s = (array) ($this->settings ?? []);
        if (! $u || $u->hasPermission('core.settings.manage')) {
            return $s;
        }
        if (! $u->hasPermission('paie.manage') && ! $u->hasPermission('paie.view')) {
            unset($s['paie']);
        }
        if (! $u->hasPermission('projets.manage')) {
            unset($s['projets']['user_costs'], $s['projets']['hourly_cost'], $s['user_costs'], $s['hourly_cost']);
        }

        return $s;
    }
}
