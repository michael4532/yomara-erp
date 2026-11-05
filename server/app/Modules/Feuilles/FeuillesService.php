<?php

namespace App\Modules\Feuilles;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Feuilles\Models\Week;
use Carbon\Carbon;

/** Feuilles de temps hebdomadaires : rassemble le temps des projets, des forfaits et des tickets. */
class FeuillesService
{
    public const DEFAULTS = ['require_approval' => false, 'remind_friday' => true];

    public function settings(Company $c): array
    {
        return array_merge(self::DEFAULTS, (array) data_get($c->settings, 'feuilles_temps', []));
    }

    public function saveSettings(Company $c, array $v): array
    {
        $s = (array) ($c->settings ?? []);
        $s['feuilles_temps'] = array_merge($this->settings($c), $v);
        $c->settings = $s;
        $c->save();

        return $s['feuilles_temps'];
    }

    public static function monday(string|Carbon $d): string
    {
        return Carbon::parse($d)->startOfWeek()->toDateString();
    }

    public static function enabled(): bool
    {
        return app()->bound('tenant') && app('tenant')->hasModule('feuilles_temps');
    }

    /** À appeler avant toute saisie de temps : refuse si la semaine est envoyée ou validée. */
    public static function check(?int $userId, ?string $date, ?string $oldDate = null): void
    {
        if ($userId && self::enabled()) {
            app(self::class)->assertOpen($userId, $date, $oldDate);
        }
    }

    public function assertOpen(int $userId, ?string $date, ?string $oldDate = null): void
    {
        foreach (array_unique(array_filter([$date, $oldDate])) as $d) {
            $w = Week::where('user_id', $userId)->where('week_start', self::monday($d))->whereIn('status', ['submitted', 'approved'])->first();
            if ($w) {
                throw new \DomainException('Semaine du '.Carbon::parse($w->week_start)->format('d/m').($w->status === 'approved' ? ' déjà validée' : ' envoyée pour validation')
                    .' : '.($w->status === 'approved' ? 'demandez à un responsable de la rouvrir.' : 'retirez la feuille pour modifier le temps.'));
            }
        }
    }

    /** Semaines validées d'une personne (pour la facturation). */
    public function approvedWeeks(array $userIds): array
    {
        $out = [];
        foreach (Week::whereIn('user_id', $userIds)->where('status', 'approved')->get(['user_id', 'week_start']) as $w) {
            $out[$w->user_id][$w->week_start->toDateString()] = true;
        }

        return $out;
    }

    public function isApproved(array $approved, ?int $userId, string $date): bool
    {
        return $userId && isset($approved[$userId][self::monday($date)]);
    }

    /** Lignes de temps de la semaine : projets, forfaits (hors temps recopiés), tickets. */
    public function entries(int $userId, string $monday): array
    {
        $c = app('tenant');
        $from = self::monday($monday);
        $to = Carbon::parse($from)->addDays(6)->toDateString();
        $rows = [];
        if ($c->hasModule('projets')) {
            $projects = \App\Modules\Projets\Models\Project::pluck('name', 'id');
            $tasks = \App\Modules\Projets\Models\Task::pluck('title', 'id');
            foreach (\App\Modules\Projets\Models\Timesheet::where('user_id', $userId)->whereBetween('date', [$from, $to])->orderBy('date')->get() as $t) {
                $rows[] = ['source' => 'project', 'id' => $t->id, 'date' => $t->date->toDateString(), 'hours' => (float) $t->hours, 'label' => $projects[$t->project_id] ?? 'Projet',
                    'detail' => trim(($t->task_id ? ($tasks[$t->task_id] ?? '').' · ' : '').($t->description ?? ''), ' ·'), 'billable' => (bool) $t->billable, 'invoiced' => (bool) $t->invoice_id];
            }
        }
        if ($c->hasModule('prestations')) {
            $packs = \App\Modules\Prestations\Models\Pack::get(['id', 'name', 'partner_name'])->keyBy('id');
            foreach (\App\Modules\Prestations\Models\Entry::where('user_id', $userId)->whereNull('timesheet_id')->whereNull('ticket_time_id')->whereBetween('date', [$from, $to])->orderBy('date')->get() as $e) {
                $p = $packs[$e->pack_id] ?? null;
                $rows[] = ['source' => 'pack', 'id' => $e->id, 'date' => $e->date->toDateString(), 'hours' => (float) $e->hours, 'label' => $p ? $p->partner_name.' — '.$p->name : 'Forfait',
                    'detail' => $e->description, 'billable' => true, 'invoiced' => false];
            }
        }
        if ($c->hasModule('helpdesk')) {
            $tickets = \App\Modules\Helpdesk\Models\Ticket::get(['id', 'number', 'subject'])->keyBy('id');
            foreach (\App\Modules\Helpdesk\Models\TimeEntry::where('user_id', $userId)->whereBetween('date', [$from, $to])->orderBy('date')->get() as $h) {
                $t = $tickets[$h->ticket_id] ?? null;
                $rows[] = ['source' => 'ticket', 'id' => $h->id, 'date' => $h->date->toDateString(), 'hours' => round($h->minutes / 60, 2), 'label' => $t ? $t->number.' — '.$t->subject : 'Ticket',
                    'detail' => $h->note, 'billable' => (bool) $h->billable, 'invoiced' => false];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $rows;
    }

    public function total(int $userId, string $monday): float
    {
        return round(array_sum(array_column($this->entries($userId, $monday), 'hours')), 2);
    }

    public function detail(Week $w): array
    {
        $rows = $this->entries($w->user_id, $w->week_start->toDateString());
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $w->week_start->copy()->addDays($i)->toDateString();
            $days[$d] = round(array_sum(array_column(array_filter($rows, fn ($r) => $r['date'] === $d), 'hours')), 2);
        }

        return ['week' => $w->toSyncArray(), 'entries' => $rows, 'days' => $days, 'total' => round(array_sum($days), 2),
            'billable' => round(array_sum(array_column(array_filter($rows, fn ($r) => $r['billable']), 'hours')), 2)];
    }

    public function notify(Week $w, string $from): void
    {
        $c = app('tenant');
        $label = 'semaine du '.$w->week_start->format('d/m');
        if ($w->status === 'submitted') {
            $to = User::where('company_id', $c->id)->where('is_active', true)->where('id', '!=', $w->user_id)->get()->filter(fn ($u) => $u->hasPermission('feuilles_temps.approve'))->pluck('id');
            AppNotification::send($c->id, $to, 'feuilles', 'Feuille de temps à valider : '.$w->user_name, $label.' · '.rtrim(rtrim(number_format($w->hours, 2, ',', ''), '0'), ',').' h', 'feuilles_temps', $w->id);
        } elseif (in_array($w->status, ['approved', 'rejected'], true) || ($from === 'approved' && $w->status === 'draft')) {
            $title = match ($w->status) { 'approved' => 'Feuille de temps validée', 'rejected' => 'Feuille de temps à corriger', default => 'Feuille de temps rouverte' };
            AppNotification::send($c->id, [$w->user_id], 'feuilles', $title, $label.($w->comment ? ' · '.$w->comment : ''), 'feuilles_temps', $w->id);
        }
    }

    /** Vendredi : rappel à ceux qui ont du temps mais n'ont pas envoyé leur semaine ; lundi : rappel aux responsables. */
    public function runDaily(Company $c): array
    {
        $out = ['reminded' => 0, 'pending' => 0];
        $s = $this->settings($c);
        if (now()->isFriday() && $s['remind_friday']) {
            $monday = self::monday(now());
            foreach (User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('feuilles_temps.submit')) as $u) {
                $w = Week::where('user_id', $u->id)->where('week_start', $monday)->first();
                if ((! $w || in_array($w->status, ['draft', 'rejected'], true)) && $this->total($u->id, $monday) > 0) {
                    AppNotification::send($c->id, [$u->id], 'feuilles', 'Pensez à envoyer votre feuille de temps', 'Semaine du '.Carbon::parse($monday)->format('d/m'), 'feuilles_temps', null);
                    $out['reminded']++;
                }
            }
        }
        $pending = Week::where('status', 'submitted')->count();
        $out['pending'] = $pending;
        if (now()->isMonday() && $pending) {
            $to = User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('feuilles_temps.approve'))->pluck('id');
            AppNotification::send($c->id, $to, 'feuilles', $pending.' feuille(s) de temps à valider', 'Validez-les pour pouvoir facturer le temps.', 'feuilles_temps', null);
        }

        return $out;
    }
}
