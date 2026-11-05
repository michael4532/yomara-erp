<?php

namespace App\Modules\Marketing\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use BelongsToCompany;

    protected $table = 'mk_campaigns';

    protected $fillable = ['name', 'kind', 'channel', 'subject', 'preheader', 'blocks', 'html', 'text', 'style', 'list_ids', 'attachments', 'status', 'scheduled_at', 'started_at', 'sent_at', 'stats', 'user_id', 'segment_ids', 'ab'];

    public const KINDS = ['newsletter' => 'Newsletter', 'proposal' => 'Proposition commerciale', 'promotion' => 'Promotion', 'event' => 'Invitation / événement', 'reminder' => 'Relance', 'other' => 'Autre'];

    public const STATUSES = ['draft' => 'Brouillon', 'scheduled' => 'Programmée', 'sending' => 'Envoi en cours', 'sent' => 'Envoyée', 'cancelled' => 'Annulée'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'style' => 'array', 'list_ids' => 'array', 'segment_ids' => 'array', 'ab' => 'array', 'attachments' => 'array', 'stats' => 'array', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class, 'campaign_id');
    }

    public function refreshStats(): void
    {
        $q = fn () => CampaignMessage::withoutGlobalScopes()->where('campaign_id', $this->id);
        $total = $q()->count();
        $sent = $q()->where('status', 'sent')->count();
        $this->stats = [
            'recipients' => $total,
            'queued' => $q()->where('status', 'queued')->count(),
            'held' => $q()->where('status', 'held')->count(),
            'sent' => $sent,
            'failed' => $q()->where('status', 'failed')->count(),
            'opened' => $q()->whereNotNull('opened_at')->count(),
            'clicked' => $q()->whereNotNull('clicked_at')->count(),
            'unsubscribed' => $q()->whereNotNull('unsubscribed_at')->count(),
            'open_rate' => $sent ? round($q()->whereNotNull('opened_at')->count() * 100 / $sent, 1) : null,
            'click_rate' => $sent ? round($q()->whereNotNull('clicked_at')->count() * 100 / $sent, 1) : null,
        ];
        $this->saveQuietly();
    }
}
