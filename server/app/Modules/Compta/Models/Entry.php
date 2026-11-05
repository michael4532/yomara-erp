<?php

namespace App\Modules\Compta\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pièce comptable (en-tête). Toujours équilibrée : Σ débits = Σ crédits. */
class Entry extends Model
{
    use BelongsToCompany;

    protected $table = 'compta_entries';

    protected $fillable = [
        'company_id', 'journal_id', 'number', 'date', 'label', 'reference', 'source_type', 'source_id',
        'kind', 'reverses_id', 'reversed_by_id', 'user_id', 'total',
    ];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'total' => 'float'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EntryLine::class, 'entry_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class)->withoutGlobalScopes();
    }
}
