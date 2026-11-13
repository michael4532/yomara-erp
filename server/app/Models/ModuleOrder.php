<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleOrder extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'number', 'module_key', 'billing', 'amount', 'currency', 'status', 'gateway_id',
        'payer_reference', 'notes', 'paid_at', 'validated_by', 'period_start', 'period_end', 'items', 'promo_code_id', 'discount', 'base_amount', 'kind', 'credit_used', 'payment_method',
    ];

    /** Modes de paiement d'une activation manuelle par l'éditeur. */
    public const PAYMENT_METHODS = [
        'cash' => 'Espèces', 'orange_money' => 'Orange Money', 'mtn_momo' => 'MTN Mobile Money', 'moov_money' => 'Moov Money', 'wave' => 'Wave',
        'bank_transfer' => 'Virement bancaire', 'cheque' => 'Chèque', 'card' => 'Carte bancaire', 'free' => 'Offert',
    ];

    /** Mode de paiement en clair : choisi à l'activation manuelle, sinon le moyen de paiement de la boutique. */
    public function paymentLabel(): string
    {
        return self::PAYMENT_METHODS[$this->payment_method] ?? $this->gateway?->label ?? ($this->amount > 0 ? 'Paiement direct' : 'Offert');
    }

    public const BILLINGS = ['one_time' => 'Achat définitif', 'monthly' => 'Abonnement mensuel', 'yearly' => 'Abonnement annuel'];

    public const STATUSES = ['pending' => 'En attente de paiement', 'awaiting_validation' => 'Paiement à vérifier', 'paid' => 'Payée', 'failed' => 'Échouée', 'cancelled' => 'Annulée'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'discount' => 'float', 'base_amount' => 'float', 'credit_used' => 'float', 'items' => 'array', 'paid_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime'];
    }

    public function isPack(): bool
    {
        return str_starts_with((string) $this->module_key, 'pack:');
    }

    /** Libellé : nom du module ou du pack. */
    public function label(): string
    {
        if (str_starts_with((string) $this->module_key, 'plan:')) {
            return 'Formule '.(Plan::where('code', substr($this->module_key, 5))->value('name') ?? substr($this->module_key, 5));
        }
        if (str_starts_with((string) $this->module_key, 'minutes:')) {
            return 'Yomeet — '.number_format((int) ($this->items['minutes'] ?? 0), 0, ',', ' ').' minutes';
        }
        if ($this->isPack()) {
            return \App\Support\PricingGrid::PACKS[substr($this->module_key, 5)]['name'] ?? 'Pack';
        }

        return $this->module?->name ?? $this->module_key;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withoutGlobalScope('real');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_key', 'key');
    }
}
