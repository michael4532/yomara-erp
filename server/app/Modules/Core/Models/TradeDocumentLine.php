<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TradeDocumentLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id', 'document_id', 'product_id', 'description', 'quantity', 'unit', 'unit_price',
        'discount_percent', 'tax_rate', 'subtotal', 'tax_amount', 'total', 'sort', 'analytic',
        'unit_factor', 'lots', 'account_code',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float', 'unit_price' => 'float', 'discount_percent' => 'float',
            'tax_rate' => 'float', 'subtotal' => 'float', 'tax_amount' => 'float', 'total' => 'float',
            'sort' => 'integer', 'analytic' => 'array', 'unit_factor' => 'float', 'lots' => 'array',
        ];
    }

    /** Devises sans centimes : les montants sont arrondis à l'unité. */
    public const ZERO_DECIMAL = ['XOF', 'XAF', 'GNF', 'KMF', 'RWF', 'BIF', 'DJF', 'JPY', 'KRW', 'VND', 'CLP', 'PYG', 'UGX'];

    public static function decimalsFor(?string $currency): int
    {
        return in_array(strtoupper((string) $currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * Devise sans centimes (franc CFA…) : on part du TTC arrondi au franc, ce qui
     * correspond au prix affiché en rayon (4 × 500 F = 2 000 F, jamais 2 000,01).
     * Autres devises : calcul classique HT → TVA → TTC au centime.
     */
    public function computeTotals(int $decimals = 2): void
    {
        $net = $this->quantity * $this->unit_price * (1 - $this->discount_percent / 100);
        $rate = $this->tax_rate / 100;

        if ($decimals === 0) {
            $this->total = round($net * (1 + $rate));
            $this->subtotal = round($this->total / (1 + $rate));
            $this->tax_amount = $this->total - $this->subtotal;
        } else {
            $this->subtotal = round($net, 2);
            $this->tax_amount = round($this->subtotal * $rate, 2);
            $this->total = round($this->subtotal + $this->tax_amount, 2);
        }
    }

    /** Quantité en unité de stock (conditionnement × quantité). */
    public function stockQuantity(): float
    {
        return round((float) $this->quantity * (float) ($this->unit_factor ?: 1), 4);
    }

    /** Montant HT avant remise (pour le total des remises). */
    public function grossAmount(int $decimals = 2): float
    {
        return round($this->quantity * $this->unit_price, $decimals);
    }
}
