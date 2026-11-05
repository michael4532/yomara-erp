<?php

namespace App\Modules\Tickets\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use BelongsToCompany;

    protected $table = 'tk_tickets';

    protected $guarded = ['id', 'company_id'];

    public const STATUSES = [
        'submitted' => 'Soumis', 'open' => 'Ouvert', 'accepted' => 'Accepté', 'in_progress' => 'En cours',
        'on_hold' => 'En attente', 'done' => 'Terminé', 'closed' => 'Clôturé', 'cancelled' => 'Annulé', 'rejected' => 'Refusé',
    ];

    public const URGENCIES = ['low' => 'Faible', 'normal' => 'Normale', 'high' => 'Haute', 'critical' => 'Critique'];

    public const ACTIVE = ['submitted', 'open', 'accepted', 'in_progress', 'on_hold'];

    public const FINISHED = ['done', 'closed'];

    protected function casts(): array
    {
        return [
            'purchase_requested' => 'boolean',
            'submitted_at' => 'datetime', 'opened_at' => 'datetime', 'accepted_at' => 'datetime', 'started_at' => 'datetime',
            'hold_since' => 'datetime', 'done_at' => 'datetime', 'closed_at' => 'datetime', 'due_at' => 'datetime',
            'hold_seconds' => 'integer', 'rating' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requesterDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'requester_department_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'ticket_id')->orderBy('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'ticket_id');
    }

    /** Délais (secondes) : prise en charge, traitement effectif (hors attente), total, attente. */
    public function durations(): array
    {
        $end = $this->done_at ?? ($this->status === 'cancelled' || $this->status === 'rejected' ? $this->updated_at : now());
        $hold = $this->hold_seconds + ($this->hold_since ? (int) $this->hold_since->diffInSeconds(now()) : 0);
        $taken = $this->accepted_at ?? $this->started_at;
        $total = (int) $this->submitted_at->diffInSeconds($end);

        return [
            'response' => $taken ? (int) $this->submitted_at->diffInSeconds($taken) : null,
            'resolution' => $this->done_at ? $total : null,
            'work' => $this->done_at ? max(0, $total - $hold) : null,
            'hold' => $hold,
            'elapsed' => $total,
            'late' => $this->due_at && ($this->done_at ? $this->done_at->gt($this->due_at) : now()->gt($this->due_at)),
        ];
    }

    public function toApi(): array
    {
        $this->loadMissing(['department:id,name,color', 'requesterDepartment:id,name', 'requester:id,name', 'assignee:id,name']);

        return $this->toArray() + [
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'urgency_label' => self::URGENCIES[$this->urgency] ?? $this->urgency,
            'durations' => $this->durations(),
        ];
    }
}
