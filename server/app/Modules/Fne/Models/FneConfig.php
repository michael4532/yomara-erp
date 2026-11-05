<?php

namespace App\Modules\Fne\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Paramètres de connexion à la plateforme FNE de la DGI (un par entreprise, clé API chiffrée). */
class FneConfig extends Model
{
    use BelongsToCompany;

    protected $table = 'fne_configs';

    public const TEST_URL = 'http://54.247.95.108/ws';

    protected $fillable = [
        'enabled', 'environment', 'base_url', 'api_key', 'point_of_sale', 'establishment', 'default_template',
        'zero_tax_code', 'auto_certify', 'certify_receipts', 'certify_credit_notes', 'commercial_message', 'footer', 'sticker_alert',
    ];

    protected $hidden = ['api_key', 'company_id'];

    protected $attributes = [
        'enabled' => false, 'environment' => 'test', 'default_template' => 'B2C', 'zero_tax_code' => 'TVAD',
        'auto_certify' => true, 'certify_receipts' => true, 'certify_credit_notes' => true, 'sticker_alert' => 100,
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'enabled' => 'boolean', 'auto_certify' => 'boolean', 'certify_receipts' => 'boolean', 'certify_credit_notes' => 'boolean',
            'sticker_alert' => 'integer', 'sticker_balance' => 'integer',
            'sticker_balance_at' => 'datetime', 'sticker_alerted_at' => 'datetime',
        ];
    }

    public function baseUrl(): string
    {
        $url = $this->environment === 'prod' && $this->base_url ? $this->base_url : ($this->base_url && $this->environment === 'test' ? $this->base_url : self::TEST_URL);

        return rtrim($url, '/');
    }

    /** Racine du site (sans /ws) pour l'URL de vérification. */
    public function siteUrl(): string
    {
        return preg_replace('#/ws$#', '', $this->baseUrl());
    }

    public function ready(): bool
    {
        return $this->enabled && filled($this->api_key) && ($this->environment === 'test' || filled($this->base_url));
    }

    public function toPublicArray(): array
    {
        return array_merge($this->only($this->fillable), [
            'api_key' => null,
            'has_api_key' => filled($this->api_key),
            'api_key_hint' => filled($this->api_key) ? '••••'.substr((string) $this->api_key, -4) : null,
            'effective_url' => $this->baseUrl(),
            'ready' => $this->ready(),
            'sticker_balance' => $this->sticker_balance,
            'sticker_balance_at' => $this->sticker_balance_at?->toIso8601String(),
            'test_url' => self::TEST_URL,
        ]);
    }
}
