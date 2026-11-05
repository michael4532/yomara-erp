<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformDelivery extends Model
{
    protected $fillable = ['campaign_id', 'automation_id', 'run', 'company_id', 'user_id', 'channel', 'email', 'token', 'status', 'error', 'sent_at', 'opened_at', 'clicked_at', 'clicks'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime'];
    }

    public function campaign()
    {
        return $this->belongsTo(PlatformCampaign::class, 'campaign_id');
    }

    public function automation()
    {
        return $this->belongsTo(PlatformAutomation::class, 'automation_id');
    }
}
