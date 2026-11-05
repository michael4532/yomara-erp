<?php

namespace App\Modules\Analytique\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Montant ventilé sur une section (produit > 0, charge < 0). Recalculé à chaque validation / annulation de la source. */
class AnLine extends Model
{
    use BelongsToCompany;

    protected $table = 'an_lines';

    protected $fillable = ['axis_id', 'section_id', 'date', 'amount', 'account_code', 'source_type', 'source_id', 'source_line_id', 'label', 'partner_id', 'product_id', 'quantity', 'percent', 'user_id'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'float', 'quantity' => 'float', 'percent' => 'float'];
    }
}
