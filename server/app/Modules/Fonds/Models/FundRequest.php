<?php

namespace App\Modules\Fonds\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FundRequest extends Model
{
    use BelongsToCompany;

    protected $table = 'af_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['needed_by' => 'date:Y-m-d', 'total_requested' => 'float', 'total_approved' => 'float', 'total_provided' => 'float',
            'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'provided_at' => 'datetime', 'closed_at' => 'datetime', 'reminded_at' => 'datetime',
            'attachments' => 'array', 'taxes' => 'array', 'discount_value' => 'float', 'total_gross' => 'float', 'total_discount' => 'float', 'total_taxes' => 'float',
            'budget_available' => 'boolean', 'documents_compliant' => 'boolean'];
    }

    public function lines()
    {
        return $this->hasMany(FundLine::class, 'request_id')->orderBy('sort')->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(FundEvent::class, 'request_id')->orderBy('id');
    }
}
