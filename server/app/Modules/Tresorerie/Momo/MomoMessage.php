<?php

namespace App\Modules\Tresorerie\Momo;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** SMS de confirmation Mobile Money reçu sur un compte de trésorerie. */
class MomoMessage extends Model
{
    use BelongsToCompany, HasUuids;

    protected $table = 'tr_momo_messages';

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['company_id', 'hash'];

    public const STATUSES = ['matched' => 'Rapproché', 'pending' => 'À traiter', 'ignored' => 'Ignoré', 'outgoing' => 'Sortie', 'duplicate' => 'Doublon'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'received_at' => 'datetime'];
    }
}
