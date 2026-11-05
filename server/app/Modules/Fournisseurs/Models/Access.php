<?php

namespace App\Modules\Fournisseurs\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Accès d'un fournisseur à son espace (lien personnel). */
class Access extends Model
{
    use BelongsToCompany;

    protected $table = 'sp_accesses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function url(): string
    {
        return url('/fournisseur/'.$this->token);
    }
}
