<?php

namespace App\Modules\Visio\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    use BelongsToCompany;

    protected $table = 'vis_meetings';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'reminders_sent' => 'array', 'starts_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime',
            'locked' => 'boolean', 'registration_open' => 'boolean', 'replay_public' => 'boolean', 'create_leads' => 'boolean',
            'epoch' => 'integer', 'capacity' => 'integer', 'duration_minutes' => 'integer', 'switches' => 'integer'];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'meeting_id');
    }

    public function opt(string $key, $default = null)
    {
        return ($this->options ?? [])[$key] ?? $default;
    }

    public function isWebinar(): bool
    {
        return $this->type === 'webinar';
    }

    public function roomUrl(?string $token = null): string
    {
        return url('/visio/'.$this->code).($token ? '?t='.$token : '');
    }

    public function registrationUrl(): ?string
    {
        return $this->slug ? url('/webinaire/'.$this->slug) : null;
    }

    public function endsAt(): ?\Carbon\Carbon
    {
        return $this->starts_at?->copy()->addMinutes($this->duration_minutes ?: 60);
    }
}
