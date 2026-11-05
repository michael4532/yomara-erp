<?php

namespace App\Modules\Tickets;

use App\Models\User;
use App\Modules\Tickets\Models\Department;
use App\Modules\Tickets\Models\Ticket;
use Carbon\Carbon;

/** Statistiques des tickets reçus par un ensemble de services sur une période. */
class TicketStats
{
    /** @param array|null $departments null = tous les services */
    public function compute(?array $departments, Carbon $from, Carbon $to): array
    {
        $scoped = fn () => Ticket::query()->when($departments !== null, fn ($q) => $q->whereIn('department_id', $departments ?: [0]));
        $received = $scoped()->whereBetween('submitted_at', [$from, $to])->get();
        $done = $scoped()->whereNotNull('done_at')->whereBetween('done_at', [$from, $to])->get();
        $backlog = $scoped()->whereIn('status', Ticket::ACTIVE)->get();

        $avg = fn ($rows, string $k) => ($v = $rows->map(fn ($t) => $t->durations()[$k])->filter(fn ($x) => $x !== null))->count() ? (int) round($v->avg()) : null;
        $sla = fn ($rows) => $rows->count() ? round($rows->filter(fn ($t) => ! $t->durations()['late'])->count() * 100 / $rows->count(), 1) : null;
        $rating = fn ($rows) => ($r = $rows->whereNotNull('rating'))->count() ? round($r->avg('rating'), 2) : null;

        // Série journalière (reçus / terminés)
        $series = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to) && count($series) < 92; $d->addDay()) {
            $key = $d->toDateString();
            $series[] = [
                'date' => $key,
                'received' => $received->filter(fn ($t) => $t->submitted_at->toDateString() === $key)->count(),
                'done' => $done->filter(fn ($t) => $t->done_at->toDateString() === $key)->count(),
            ];
        }

        $depNames = Department::pluck('name', 'id');
        $users = User::where('company_id', app('tenant')->id)->pluck('name', 'id');

        $byDepartment = $received->groupBy('department_id')->map(function ($rows, $id) use ($done, $avg, $sla, $depNames) {
            $d = $done->where('department_id', $id);

            return ['id' => (int) $id, 'name' => $depNames[$id] ?? '?', 'received' => $rows->count(), 'done' => $d->count(), 'avg_resolution' => $avg($d, 'resolution'), 'sla' => $sla($d)];
        })->sortByDesc('received')->values();

        // Classement des collaborateurs sur les tickets terminés
        $ranking = $done->whereNotNull('assignee_id')->groupBy('assignee_id')->map(function ($rows, $uid) use ($avg, $sla, $rating, $users) {
            $slaPct = $sla($rows) ?? 100;
            $r = $rating($rows);
            $score = round($rows->count() * (0.5 + 0.5 * $slaPct / 100) * ($r ? $r / 5 : 1) * 10, 1);

            return [
                'user_id' => (int) $uid, 'name' => $users[$uid] ?? '?', 'done' => $rows->count(),
                'avg_resolution' => $avg($rows, 'resolution'), 'avg_work' => $avg($rows, 'work'), 'avg_response' => $avg($rows, 'response'),
                'sla' => $slaPct, 'rating' => $r, 'score' => $score,
            ];
        })->sortByDesc('score')->values();
        $fastest = $ranking->filter(fn ($r) => $r['done'] >= 2 && $r['avg_work'] !== null)->sortBy('avg_work')->first();

        $count = fn ($rows, string $field, array $labels) => $rows->groupBy(fn ($t) => $t->{$field} ?? '')->map(fn ($g, $k) => [
            'key' => $k, 'label' => $labels[$k] ?? ($k === '' ? 'Non précisé' : $k), 'count' => $g->count(),
        ])->sortByDesc('count')->values();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kpi' => [
                'received' => $received->count(),
                'done' => $done->count(),
                'backlog' => $backlog->count(),
                'late' => $backlog->filter(fn ($t) => $t->durations()['late'])->count(),
                'on_hold' => $backlog->where('status', 'on_hold')->count(),
                'purchase_waiting' => $backlog->where('status', 'on_hold')->where('purchase_requested', true)->count(),
                'avg_response' => $avg($done, 'response'),
                'avg_resolution' => $avg($done, 'resolution'),
                'avg_work' => $avg($done, 'work'),
                'sla' => $sla($done),
                'rating' => $rating($done),
            ],
            'series' => $series,
            'by_category' => $count($received, 'category', []),
            'by_urgency' => $count($received, 'urgency', Ticket::URGENCIES),
            'by_status' => $count($backlog, 'status', Ticket::STATUSES),
            'by_department' => $byDepartment,
            'ranking' => $ranking,
            'fastest' => $fastest,
        ];
    }

    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        if ($seconds < 3600) {
            return max(1, (int) round($seconds / 60)).' min';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600).' h '.str_pad((string) floor(($seconds % 3600) / 60), 2, '0', STR_PAD_LEFT);
        }

        return round($seconds / 86400, 1).' j';
    }

    /** Résumé rédigé du rapport automatique. */
    public static function summary(array $s, string $scope): string
    {
        $k = $s['kpi'];
        $lines = [
            "$scope — du ".Carbon::parse($s['from'])->format('d/m').' au '.Carbon::parse($s['to'])->format('d/m/Y').' :',
            "{$k['received']} demande(s) reçue(s), {$k['done']} traitée(s), {$k['backlog']} en cours dont {$k['late']} en retard.",
        ];
        if ($k['avg_resolution'] !== null) {
            $lines[] = 'Prise en charge moyenne '.self::duration($k['avg_response']).', résolution moyenne '.self::duration($k['avg_resolution']).($k['sla'] !== null ? ", délais respectés à {$k['sla']} %." : '.');
        }
        if ($k['purchase_waiting'] > 0) {
            $lines[] = "{$k['purchase_waiting']} ticket(s) attendent un achat de matériel.";
        }
        if ($best = $s['ranking'][0] ?? null) {
            $lines[] = "Collaborateur le plus performant : {$best['name']} ({$best['done']} ticket(s), délais respectés à {$best['sla']} %).";
        }
        if ($fast = $s['fastest']) {
            $lines[] = "Le plus rapide : {$fast['name']} (".self::duration($fast['avg_work']).' de traitement en moyenne).';
        }
        if ($cat = $s['by_category'][0] ?? null) {
            $lines[] = "Demande la plus fréquente : {$cat['label']} ({$cat['count']}).";
        }

        return implode("\n", $lines);
    }
}
