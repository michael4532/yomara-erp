<?php

namespace App\Modules\Mailbox\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'mb_messages';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['to' => 'array', 'cc' => 'array', 'bcc' => 'array', 'attachments' => 'array', 'date' => 'datetime', 'seen' => 'boolean', 'flagged' => 'boolean',
            'answered' => 'boolean', 'draft' => 'boolean', 'has_attachments' => 'boolean', 'body_loaded' => 'boolean', 'account_id' => 'integer', 'folder_id' => 'integer', 'size' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id');
    }
}
