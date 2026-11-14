<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'price_monthly', 'price_yearly', 'currency',
        'max_users', 'modules', 'is_active', 'sort', 'is_free', 'yearly_discount', 'intro_discount', 'intro_months', 'badge',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'is_active' => 'boolean',
            'price_monthly' => 'float',
            'price_yearly' => 'float',
            'max_users' => 'integer',
            'is_free' => 'boolean',
            'yearly_discount' => 'float',
            'intro_discount' => 'float',
            'intro_months' => 'integer',
        ];
    }

    /** Socle : modules des formules gratuites actives, compris d'office dans toutes les formules (mémorisé le temps d'une requête). */
    protected static function booted(): void
    {
        $flush = fn () => static::flushSocle();
        static::saved($flush);
        static::deleted($flush);
    }

    public static function socle(): array
    {
        $app = app();
        if ($app->bound('plan.socle')) {
            return $app->make('plan.socle');
        }
        // formules marquées gratuites seulement : une formule « sur devis » à 0 F n'est pas le socle
        $v = static::query()->where('is_active', true)->where('is_free', true)
            ->get(['modules'])->flatMap(fn (Plan $p) => (array) ($p->modules ?? []))->unique()->values()->all();
        $app->instance('plan.socle', $v);

        return $v;
    }

    public static function flushSocle(): void
    {
        app()->forgetInstance('plan.socle');
    }

    /** Modules réellement compris : ceux cochés dans la formule + le socle gratuit. */
    public function effectiveModules(): array
    {
        return array_values(array_unique(array_merge((array) ($this->modules ?? []), static::socle())));
    }

    /** Modules compris pour une société par sa formule (sans formule, ex. licence d'installation : aucun). */
    public static function modulesFor(?self $plan): array
    {
        return $plan ? $plan->effectiveModules() : [];
    }

    /** Prix annuel : 12 mois moins la remise annuelle (arrondi à la centaine). */
    public function yearlyPrice(): float
    {
        if ($this->yearly_discount > 0) {
            return round($this->price_monthly * 12 * (100 - $this->yearly_discount) / 100 / 100) * 100;
        }

        return (float) ($this->price_yearly ?: $this->price_monthly * 12);
    }

    public function introPrice(): float
    {
        return round($this->price_monthly * (100 - $this->intro_discount) / 100 / 100) * 100;
    }

    /** Vue publique : prix, remises et économies. */
    public function offer(?int $paidMonths = null): array
    {
        $introLeft = $this->intro_discount > 0 && $this->intro_months > 0 ? max(0, $this->intro_months - (int) $paidMonths) : 0;

        return [
            'code' => $this->code, 'name' => $this->name, 'description' => $this->description, 'is_free' => (bool) $this->is_free || $this->price_monthly <= 0,
            'max_users' => $this->max_users, 'badge' => $this->badge, 'currency' => $this->currency,
            'monthly' => (float) $this->price_monthly,
            'monthly_now' => $introLeft > 0 ? $this->introPrice() : (float) $this->price_monthly,
            'intro_discount' => $this->intro_discount, 'intro_months' => $this->intro_months, 'intro_left' => $introLeft,
            'yearly' => $this->yearlyPrice(), 'yearly_discount' => $this->yearly_discount,
            'yearly_saving' => max(0, $this->price_monthly * 12 - $this->yearlyPrice()),
            'modules' => $this->effectiveModules(),
        ];
    }
}
