<?php

namespace App\Modules\Visio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Participant extends Model
{
    protected $table = 'vis_participants';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mic' => 'boolean', 'cam' => 'boolean', 'screen' => 'boolean', 'hand_at' => 'datetime', 'first_joined_at' => 'datetime', 'joined_at' => 'datetime',
            'left_at' => 'datetime', 'last_seen_at' => 'datetime', 'invited_at' => 'datetime', 'seconds_total' => 'integer'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id')->withoutGlobalScopes();
    }

    public function isHost(): bool
    {
        return in_array($this->role, ['host', 'cohost'], true);
    }

    /** Peut publier son micro / sa caméra (les spectateurs d'un webinaire ne le peuvent pas). */
    public function canPublish(): bool
    {
        return $this->role !== 'attendee';
    }

    public function online(): bool
    {
        return $this->status === 'admitted' && $this->last_seen_at && $this->last_seen_at->gt(now()->subSeconds(25));
    }
}
