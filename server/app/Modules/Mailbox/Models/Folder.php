<?php

namespace App\Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Folder extends Model
{
    protected $table = 'mb_folders';

    protected $guarded = ['id'];

    public const ROLES = ['inbox' => 'Boîte de réception', 'sent' => 'Éléments envoyés', 'drafts' => 'Brouillons', 'trash' => 'Éléments supprimés', 'junk' => 'Courrier indésirable', 'archive' => 'Archives'];

    protected function casts(): array
    {
        return ['state' => 'array', 'sync' => 'boolean', 'unread' => 'integer', 'total' => 'integer', 'account_id' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
