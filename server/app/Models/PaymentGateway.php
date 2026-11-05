<?php

namespace App\Models;

use App\Payments\GatewayRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Moyen de paiement configuré (plateforme si company_id est vide, sinon propre à une société). */
class PaymentGateway extends Model
{
    protected $fillable = ['company_id', 'driver', 'label', 'mode', 'credentials', 'instructions', 'enabled', 'sort', 'treasury_account_id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'enabled' => 'boolean', 'sort' => 'integer'];
    }

    public function scopePlatform(Builder $q): Builder
    {
        return $q->whereNull('company_id');
    }

    /**
     * Moyens de paiement proposés dans la boutique de modules : ceux de la plateforme, plus (SaaS) les paiements
     * en ligne configurés par l'éditeur dans les « Moyens de paiement » de sa propre société (Paystack, CinetPay…).
     */
    public function scopeStore(Builder $q): Builder
    {
        $editor = config('erp.mode') === 'saas' ? \App\Services\SiteTraffic::editorCompany()?->id : null;
        $online = collect(GatewayRegistry::catalog())->filter(fn ($d) => ! empty($d['online']))->pluck('driver')->all();

        return $q->where(fn ($w) => $w->whereNull('company_id')->when($editor, fn ($x) => $x->orWhere(fn ($y) => $y->where('company_id', $editor)->whereIn('driver', $online))));
    }

    public function isOnline(): bool
    {
        return (bool) (GatewayRegistry::meta($this->driver)['online'] ?? false);
    }

    public function credential(string $key, $default = null)
    {
        return $this->credentials[$key] ?? $default;
    }

    /** Vue publique : jamais les clés, seulement ce qu'il faut pour payer. */
    public function toPublic(): array
    {
        $meta = GatewayRegistry::meta($this->driver);

        return [
            'id' => $this->id, 'driver' => $this->driver, 'label' => $this->label, 'online' => (bool) ($meta['online'] ?? false),
            'instructions' => $this->instructions, 'logo' => $meta['logo'] ?? null, 'channels' => $meta['channels'] ?? null,
            // champs publics (lien marchand) : jamais les clés secrètes
            'link' => in_array('url', $meta['public_fields'] ?? [], true) ? $this->publicUrl() : null,
        ];
    }

    /** Lien marchand public (https uniquement). */
    public function publicUrl(): ?string
    {
        $u = trim((string) $this->credential('url', ''));

        return preg_match('#^https://[^\s]+$#i', $u) ? $u : null;
    }

    /** Vue d'administration : clés masquées. */
    public function toAdmin(): array
    {
        $masked = [];
        foreach ((array) $this->credentials as $k => $v) {
            $masked[$k] = is_string($v) && strlen($v) > 6 ? str_repeat('•', 6).substr($v, -4) : ($v === null || $v === '' ? '' : '••••');
        }

        return $this->toArray() + ['credentials_masked' => $masked, 'online' => $this->isOnline()];
    }
}
