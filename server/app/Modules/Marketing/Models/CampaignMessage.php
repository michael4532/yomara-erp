<?php

namespace App\Modules\Marketing\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignMessage extends Model
{
    use BelongsToCompany;

    protected $table = 'mk_messages';

    protected $fillable = ['campaign_id', 'contact_id', 'channel', 'to', 'token', 'status', 'error', 'sent_at', 'opened_at', 'opens', 'clicked_at', 'clicks', 'unsubscribed_at', 'automation_id', 'step_id', 'variant'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id')->withoutGlobalScopes();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id')->withoutGlobalScopes();
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class, 'automation_id')->withoutGlobalScopes();
    }
}
