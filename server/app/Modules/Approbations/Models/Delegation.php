<?php

namespace App\Modules\Approbations\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Délégation des approbations pendant une absence. */
class Delegation extends Model
{
    use BelongsToCompany;

    protected $table = 'wf_delegations';

    protected $fillable = ['user_id', 'delegate_id', 'starts_on', 'ends_on', 'note'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'delegate_id' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function scopeActive($q, ?string $day = null)
    {
        $day ??= now()->toDateString();

        return $q->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day);
    }
}
