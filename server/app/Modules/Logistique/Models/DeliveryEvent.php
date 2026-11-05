<?php

namespace App\Modules\Logistique\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DeliveryEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'lg_delivery_events';

    protected $fillable = ['company_id', 'delivery_id', 'status', 'note', 'user_id'];
}
