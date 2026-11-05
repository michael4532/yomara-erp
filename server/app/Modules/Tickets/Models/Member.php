<?php

namespace App\Modules\Tickets\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Member extends Model
{
    use BelongsToCompany;

    protected $table = 'tk_members';

    protected $fillable = ['department_id', 'user_id', 'role'];

    public const ROLES = ['head' => 'Chef de service', 'deputy' => 'Adjoint / second', 'member' => 'Collaborateur', 'intern' => 'Stagiaire'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
