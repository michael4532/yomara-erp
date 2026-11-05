<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'gateway_id', 'purpose', 'purpose_id', 'amount', 'currency', 'status', 'reference', 'payment_url', 'meta', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'meta' => 'array', 'paid_at' => 'datetime'];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }
}
