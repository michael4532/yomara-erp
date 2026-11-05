<?php

namespace App\Modules\Compta\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryLine extends Model
{
    public $timestamps = false;

    protected $table = 'compta_entry_lines';

    protected $fillable = ['entry_id', 'company_id', 'account_id', 'partner_id', 'label', 'debit', 'credit'];

    protected function casts(): array
    {
        return ['debit' => 'float', 'credit' => 'float'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }
}
