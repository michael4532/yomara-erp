<?php

namespace App\Modules\Crm;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Stage;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CrmController extends Controller
{
    /** Utilisateurs de la société (attribution des opportunités). */
    public function users()
    {
        return ['users' => User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])];
    }

    /**
     * Indicateurs commerciaux sur une période (par défaut : année en cours).
     * Gagnées / perdues : par date de clôture ; pipeline et prévisions : opportunités ouvertes.
     */
    public function stats(Request $r)
    {
        $from = Carbon::parse($r->query('from', now()->startOfYear()->toDateString()))->startOfDay();
        $to = Carbon::parse($r->query('to', now()->toDateString()))->endOfDay();
        $owner = $r->query('owner_id');

        $base = fn () => Lead::query()->when($owner, fn ($q) => $q->where('owner_id', $owner));
        $stages = Stage::orderBy('sequence')->get();
        $open = $base()->where('status', 'open')->get();
        $won = $base()->where('status', 'won')->whereBetween('won_at', [$from, $to])->get();
        $lost = $base()->where('status', 'lost')->whereBetween('lost_at', [$from, $to])->get();
        $closed = $won->count() + $lost->count();

        $pipeline = $stages->map(function (Stage $s) use ($open, $won, $lost) {
            $rows = (match ($s->kind) { 'won' => $won, 'lost' => $lost, default => $open })->where('stage_id', $s->id);

            return [
                'id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'color' => $s->color,
                'count' => $rows->count(),
                'amount' => round($rows->sum('expected_revenue'), 2),
                'weighted' => round($rows->sum(fn ($l) => $l->weighted()), 2),
            ];
        })->values();

        // Prévisions : CA pondéré des opportunités ouvertes par mois de clôture prévue
        $forecast = $open->groupBy(fn ($l) => $l->expected_close_date?->format('Y-m') ?? 'sans date')
            ->map(fn ($rows, $month) => ['month' => $month, 'count' => $rows->count(), 'amount' => round($rows->sum('expected_revenue'), 2), 'weighted' => round($rows->sum(fn ($l) => $l->weighted()), 2)])
            ->sortKeys()->values();

        $group = function ($rows, string $key, callable $label) {
            return $rows->groupBy(fn ($l) => $l->{$key} ?? '')->map(fn ($g, $k) => [
                'key' => $k, 'label' => $label($k), 'count' => $g->count(), 'amount' => round($g->sum('expected_revenue'), 2),
            ])->sortByDesc('amount')->values();
        };
        $users = User::where('company_id', app('tenant')->id)->pluck('name', 'id');

        $created = $base()->whereBetween('created_at', [$from, $to])->get();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kpi' => [
                'open_count' => $open->count(),
                'open_amount' => round($open->sum('expected_revenue'), 2),
                'weighted' => round($open->sum(fn ($l) => $l->weighted()), 2),
                'won_count' => $won->count(),
                'won_amount' => round($won->sum('expected_revenue'), 2),
                'lost_count' => $lost->count(),
                'win_rate' => $closed ? round($won->count() * 100 / $closed, 1) : null,
                'avg_deal' => $won->count() ? round($won->avg('expected_revenue'), 2) : null,
                'avg_days_to_win' => $won->count() ? round($won->avg(fn ($l) => $l->created_at->diffInDays($l->won_at)), 1) : null,
                'new_count' => $created->count(),
                'late_activities' => Activity::where('done', false)->where('due_at', '<', now())->when($owner, fn ($q) => $q->where('owner_id', $owner))->count(),
                'today_activities' => Activity::where('done', false)->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->when($owner, fn ($q) => $q->where('owner_id', $owner))->count(),
            ],
            'temperature' => [
                'hot' => $open->filter(fn ($l) => (int) $l->score >= 70)->count(),
                'warm' => $open->filter(fn ($l) => (int) $l->score >= 40 && (int) $l->score < 70)->count(),
                'cold' => $open->filter(fn ($l) => (int) $l->score < 40)->count(),
            ],
            'hot_leads' => $open->sortByDesc('score')->take(8)->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'contact' => $l->contact_name ?: $l->company_name, 'score' => (int) $l->score,
                'amount' => (float) $l->expected_revenue, 'source' => Lead::SOURCES[$l->source] ?? null])->values(),
            'by_campaign' => $created->filter(fn ($l) => $l->utm_campaign)->groupBy('utm_campaign')->map(fn ($g, $k) => ['label' => $k, 'count' => $g->count(),
                'won' => $g->where('status', 'won')->count(), 'amount' => round($g->where('status', 'won')->sum('expected_revenue'), 2)])->sortByDesc('count')->values()->take(10),
            'pipeline' => $pipeline,
            'forecast' => $forecast,
            'by_source' => $group($created, 'source', fn ($k) => Lead::SOURCES[$k] ?? 'Non renseignée'),
            'by_owner' => $group($won, 'owner_id', fn ($k) => $users[$k] ?? 'Non attribuée'),
            'lost_reasons' => $lost->groupBy(fn ($l) => $l->lost_reason ?: 'Non précisée')->map(fn ($g, $k) => ['label' => $k, 'count' => $g->count()])->sortByDesc('count')->values(),
        ];
    }
}
