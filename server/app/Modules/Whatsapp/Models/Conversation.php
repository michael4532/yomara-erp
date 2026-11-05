<?php

namespace App\Modules\Whatsapp\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use BelongsToCompany;

    protected $table = 'wa_conversations';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['labels' => 'array', 'assigned_to' => 'integer', 'unread' => 'integer', 'last_message_at' => 'datetime', 'last_inbound_at' => 'datetime',
            'welcome_sent_at' => 'datetime', 'away_sent_at' => 'datetime'];
    }

    /** Fenêtre de 24 h ouverte par le dernier message du client (règle de Meta pour les messages libres). */
    public function windowOpen(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(24));
    }
}
