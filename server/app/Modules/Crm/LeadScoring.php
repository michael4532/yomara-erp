<?php

namespace App\Modules\Crm;

use App\Modules\Crm\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Note du prospect sur 100 : qualité du contact, origine, avancement, échanges récents, engagement marketing.
 * Chaud à partir de 70, tiède de 40 à 69, froid en dessous.
 */
class LeadScoring
{
    public const SOURCE_POINTS = ['referral' => 15, 'phone' => 12, 'website' => 10, 'whatsapp' => 10, 'event' => 10, 'landing' => 10, 'ads' => 8, 'campaign' => 8, 'social' => 6, 'prospecting' => 4, 'other' => 3];

    public static function score(Lead $l): int
    {
        if ($l->status === 'won') {
            return 100;
        }
        if ($l->status === 'lost') {
            return 0;
        }
        $s = 0;
        $s += $l->email ? 10 : 0;
        $s += $l->phone ? 10 : 0;
        $s += $l->company_name ? 5 : 0;
        $s += $l->city ? 3 : 0;
        $s += (float) $l->expected_revenue > 0 ? 7 : 0;
        $s += self::SOURCE_POINTS[$l->source ?? 'other'] ?? 3;
        $s += (int) round(min(100, max(0, (int) $l->probability)) * .25);
        $s += min(3, (int) $l->priority) * 2;
        if ($l->exists) {
            $acts = DB::table('crm_activities')->where('lead_id', $l->id)->whereNull('deleted_at');
            $s += min(16, (clone $acts)->where('done', true)->count() * 4);
            $s += (clone $acts)->where('updated_at', '>=', now()->subDays(7))->exists() ? 6 : 0;
            if (Schema::hasTable('mk_contacts')) {
                $engagement = (int) DB::table('mk_contacts')->where('lead_id', $l->id)->max('score');
                $s += min(10, intdiv($engagement, 10));
            }
            $last = $l->updated_at ?? $l->created_at;
            if ($last && $last->lt(now()->subDays(30))) {
                $s -= 10;
            }
        }

        return max(1, min(99, $s));
    }

    public static function temperature(int $score): string
    {
        return $score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold');
    }

    /** Recalcul quotidien (échanges récents, ancienneté) : seulement les fiches dont la note change. */
    public static function refresh(int $companyId): int
    {
        $n = 0;
        foreach (Lead::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->where('status', 'open')->get() as $l) {
            if (self::score($l) !== (int) $l->score) {
                $l->save();
                $n++;
            }
        }

        return $n;
    }
}
