<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Campagne de l'éditeur vers ses sociétés clientes (notification et / ou e-mail). */
class PlatformCampaign extends Model
{
    protected $fillable = ['name', 'channels', 'audience', 'subject', 'preheader', 'title', 'body_text', 'format', 'blocks', 'style', 'html',
        'cta_label', 'cta_url', 'schedule', 'send_at', 'recurrence', 'next_run_at', 'last_run_at', 'status', 'runs', 'stats', 'created_by'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'audience' => 'array', 'blocks' => 'array', 'style' => 'array', 'recurrence' => 'array', 'stats' => 'array',
            'send_at' => 'datetime', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }

    public const STATUSES = ['draft' => 'Brouillon', 'scheduled' => 'Programmée', 'sending' => 'Envoi en cours', 'sent' => 'Envoyée', 'paused' => 'En pause'];

    public function deliveries()
    {
        return $this->hasMany(PlatformDelivery::class, 'campaign_id');
    }

    /** Prochaine exécution d'une campagne récurrente après $from (null si terminée). */
    public function nextOccurrence(?\Carbon\CarbonInterface $from = null): ?\Carbon\Carbon
    {
        $r = (array) $this->recurrence;
        $from = \Carbon\Carbon::parse($from ?? now());
        [$h, $m] = array_map('intval', explode(':', ($r['time'] ?? '09:00').':0'));
        $freq = $r['freq'] ?? 'weekly';
        $next = $from->copy()->setTime($h, $m);
        if ($freq === 'daily') {
            if ($next->lte($from)) {
                $next->addDay();
            }
        } elseif ($freq === 'monthly') {
            $day = max(1, min(28, (int) ($r['day'] ?? 1)));
            $next->day($day);
            if ($next->lte($from)) {
                $next->addMonthNoOverflow()->day($day);
            }
        } else { // weekly : 1 = lundi … 7 = dimanche
            $wd = max(1, min(7, (int) ($r['weekday'] ?? 1)));
            $next->addDays(($wd - $next->dayOfWeekIso + 7) % 7);
            if ($next->lte($from)) {
                $next->addWeek();
            }
        }
        if (! empty($r['until']) && $next->gt(\Carbon\Carbon::parse($r['until'])->endOfDay())) {
            return null;
        }

        return $next;
    }
}
