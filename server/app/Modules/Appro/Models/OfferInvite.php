<?php

namespace App\Modules\Appro\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class OfferInvite extends Model
{
    use BelongsToCompany;

    protected $table = 'ap_invites';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'opened_at' => 'datetime', 'answered_at' => 'datetime', 'reminded_at' => 'datetime', 'read_at' => 'datetime'];
    }
}
