<?php

namespace App\Modules\Fidelite\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class GiftCardMove extends Model
{
    use BelongsToCompany;

    protected $fillable = ['gift_card_id', 'amount', 'payment_id', 'document_id', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }
}
