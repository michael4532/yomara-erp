<?php

namespace App\Modules\Marketing\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MkList extends Model
{
    use BelongsToCompany;

    protected $table = 'mk_lists';

    protected $fillable = ['name', 'description'];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'mk_list_contact', 'list_id', 'contact_id');
    }
}
