<?php

namespace App\Modules\Maintenance;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Maintenance (GMAO) : demandes, ordres de travail, plans préventifs par date ou par compteur, historique et indicateurs. */
class MaintenanceHooks
{
    public const STATUSES = ['requested' => ['Demande', '#64748B'], 'planned' => ['Planifié', '#1F6FEB'], 'in_progress' => ['En cours', '#D97706'],
        'on_hold' => ['En attente (pièces)', '#9333EA'], 'done' => ['Terminé', '#16A34A'], 'cancelled' => ['Annulé', '#94A3B8']];

    public const OPEN = ['requested', 'planned', 'in_progress', 'on_hold'];

    public const KINDS = ['corrective' => 'Curative (panne)', 'preventive' => 'Préventive', 'inspection' => 'Contrôle / inspection', 'improvement' => 'Amélioration'];

    public const PRIORITIES = ['low' => ['Basse', '#94A3B8'], 'normal' => ['Normale', '#1F6FEB'], 'high' => ['Haute', '#D97706'], 'urgent' => ['Urgente', '#DC2626']];

    public const CAUSES = ['wear' => 'Usure', 'mechanical' => 'Mécanique', 'electrical' => 'Électrique', 'hydraulic' => 'Hydraulique / fuite', 'misuse' => 'Mauvaise utilisation',
        'power' => 'Coupure / surtension', 'lack' => 'Défaut d\'entretien', 'external' => 'Cause extérieure', 'unknown' => 'Indéterminée'];

    public const EQUIPMENT_STATUSES = ['running' => ['En service', '#16A34A'], 'down' => ['En panne', '#DC2626'], 'maintenance' => ['En maintenance', '#D97706'], 'retired' => ['Hors service', '#94A3B8']];

    public const CRITICALITY = ['low' => ['Faible', '#94A3B8'], 'medium' => ['Moyenne', '#D97706'], 'high' => ['Critique', '#DC2626']];

    public const METER_UNITS = ['h' => 'Heures de marche', 'km' => 'Kilomètres', 'cycles' => 'Cycles'];

    public const TRIGGERS = ['date' => 'Par date (calendrier)', 'meter' => 'Par compteur'];

    public const UNITS = ['days' => 'jours', 'weeks' => 'semaines', 'months' => 'mois', 'h' => 'heures', 'km' => 'km', 'cycles' => 'cycles'];

    protected static function days(?string $date): ?int
    {
        return $date ? (int) now()->startOfDay()->diffInDays(Carbon::parse($date)->startOfDay(), false) : null;
    }

    public static function nextDate(string $from, int $every, string $unit): string
    {
        $d = Carbon::parse($from);

        $next = match ($unit) {
            'days' => $d->addDays($every),
            'weeks' => $d->addWeeks($every),
            default => $d->addMonthsNoOverflow($every),
        };

        return $next->toDateString();
    }

    public function present(string $res, array $row): array
    {
        switch ($res) {
            case 'orders':
                $row['total_cost'] = (float) $row['labour_cost'] + (float) $row['parts_cost'] + (float) $row['external_cost'];
                $row['alert'] = null;
                if (in_array($row['status'], self::OPEN, true)) {
                    $d = self::days($row['planned_on']);
                    $row['alert'] = match (true) {
                        $d !== null && $d < 0 => 'En retard de '.abs($d).' j',
                        $row['priority'] === 'urgent' => 'Urgent',
                        $row['equipment_stopped'] => 'Équipement à l\'arrêt',
                        $d === 0 => 'Prévu aujourd\'hui',
                        default => null,
                    };
                }
                break;
            case 'equipment':
                $plans = DB::table('mt_plans')->where('company_id', $row['company_id'])->where('equipment_id', $row['id'])->where('active', true)->get();
                $next = null;
                foreach ($plans as $p) {
                    if ($p->trigger === 'date' && $p->next_due_on && (! $next || $p->next_due_on < $next[0])) {
                        $next = [$p->next_due_on, $p->title.' — '.date('d/m/Y', strtotime($p->next_due_on))];
                    }
                }
                $meter = $plans->where('trigger', 'meter')->whereNotNull('next_meter')->sortBy('next_meter')->first();
                $row['next_maintenance'] = $next[1] ?? ($meter ? $meter->title.' — à '.rtrim(rtrim(number_format((float) $meter->next_meter, 1, ',', ' '), '0'), ',').' '.$row['meter_unit'] : null);
                $h = DB::table('mt_orders')->where('company_id', $row['company_id'])->where('equipment_id', $row['id'])->where('status', 'done')->where('finished_at', '>=', now()->subYear());
                $fails = (clone $h)->where('kind', 'corrective')->count();
                $down = (float) (clone $h)->sum('downtime_hours');
                $cost = (float) (clone $h)->sum(DB::raw('coalesce(labour_cost, 0) + coalesce(parts_cost, 0) + coalesce(external_cost, 0)'));
                $row['breakdowns'] = $fails;
                $row['downtime'] = $down;
                $row['cost_year'] = $cost;
                $row['history'] = $fails || $cost ? $fails.' panne(s) · '.rtrim(rtrim(number_format($down, 1, ',', ''), '0'), ',').' h d\'arrêt · '.number_format($cost, 0, ',', ' ') : null;
                $row['open_orders'] = DB::table('mt_orders')->where('company_id', $row['company_id'])->where('equipment_id', $row['id'])->whereIn('status', self::OPEN)->count();
                break;
            case 'plans':
                $row['frequency'] = 'Tous les '.$row['every'].' '.(self::UNITS[$row['unit']] ?? $row['unit']);
                break;
        }

        return $row;
    }

    protected function canManage(User $u): bool
    {
        return $u->hasPermission('maintenance.manage');
    }

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        $c = app('tenant');
        if ($res === 'orders') {
            if ($old && ! $this->canManage($u)) {
                $mine = (int) $old['requester_id'] === $u->id && $old['status'] === 'requested';
                $assigned = (int) $old['assignee_id'] === $u->id && $u->hasPermission('maintenance.work');
                if (! $mine && ! $assigned) {
                    throw new \DomainException('Vous ne pouvez modifier que vos demandes en attente ou les interventions qui vous sont confiées.');
                }
                if (array_key_exists('assignee_id', $data) && (int) $data['assignee_id'] !== (int) $old['assignee_id']) {
                    throw new \DomainException('Seul le responsable maintenance attribue les interventions.');
                }
            }
            if (! $old) {
                if (! $this->canManage($u)) {
                    unset($data['assignee_id'], $data['labour_cost'], $data['parts_cost'], $data['external_cost']);
                    $data['requester_id'] = $u->id;
                }
                $data['status'] = $this->canManage($u) && ! empty($data['assignee_id']) ? 'planned' : 'requested';
                $data['number'] = 'OT-'.now()->format('Y').'-'.str_pad((string) (DB::table('mt_orders')->where('company_id', $c->id)->whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT);
                $data['planned_on'] ??= $data['status'] === 'planned' ? now()->toDateString() : null;
                // panne d'un équipement critique à l'arrêt : urgence automatique
                if (! empty($data['equipment_id']) && ! empty($data['equipment_stopped']) && ($data['kind'] ?? 'corrective') === 'corrective' && in_array($data['priority'] ?? 'normal', ['low', 'normal'], true)
                    && DB::table('mt_equipment')->where('id', $data['equipment_id'])->value('criticality') === 'high') {
                    $data['priority'] = 'urgent';
                }
            }
        } elseif ($res === 'plans') {
            $p = array_merge(['last_done_on' => null, 'last_meter' => null, 'trigger' => 'date', 'lead_days' => 7, 'unit' => 'months', 'every' => 1], $old ?? [], $data);
            $meterUnits = ['h', 'km', 'cycles'];
            if (($p['trigger'] ?? 'date') === 'meter' && ! in_array($p['unit'], $meterUnits, true)) {
                $data['unit'] = DB::table('mt_equipment')->where('id', $p['equipment_id'])->value('meter_unit') ?: 'h';
                $p['unit'] = $data['unit'];
            } elseif (($p['trigger'] ?? 'date') === 'date' && in_array($p['unit'], $meterUnits, true)) {
                throw new \DomainException('Pour un plan par date, choisissez jours, semaines ou mois.');
            }
            if ((int) $p['every'] < 1) {
                throw new \DomainException('Fréquence invalide.');
            }
            $recalc = ! $old || ($data['last_done_on'] ?? $old['last_done_on']) !== $old['last_done_on'] || (int) $p['every'] !== (int) $old['every'] || $p['unit'] !== $old['unit']
                || (($data['last_meter'] ?? null) !== null && (float) $data['last_meter'] !== (float) $old['last_meter']);
            if (($p['trigger'] ?? 'date') === 'date' && $recalc && empty($data['next_due_on'])) {
                $data['next_due_on'] = self::nextDate($p['last_done_on'] ?: now()->toDateString(), (int) $p['every'], $p['unit']);
                if (! $p['last_done_on']) {
                    $data['next_due_on'] = now()->addDays((int) ($p['lead_days'] ?? 7))->toDateString(); // jamais fait : à programmer bientôt
                }
            }
            if (($p['trigger'] ?? 'date') === 'meter' && $recalc && empty($data['next_meter'])) {
                $base = $p['last_meter'] ?? DB::table('mt_equipment')->where('id', $p['equipment_id'])->value('meter_value') ?? 0;
                $data['next_meter'] = (float) $base + (int) $p['every'];
            }
        }

        return $data;
    }

    public function saved(string $res, array $row, ?array $old, User $u): void
    {
        $c = app('tenant');
        if ($res === 'orders') {
            if ($row['equipment_id'] && $row['equipment_stopped'] && ! ($old['equipment_stopped'] ?? false) && in_array($row['status'], self::OPEN, true)) {
                DB::table('mt_equipment')->where('id', $row['equipment_id'])->where('status', '!=', 'retired')->update(['status' => 'down', 'updated_at' => now()]);
            }
            if (! $old && $row['status'] === 'requested') {
                $eq = $row['equipment_id'] ? DB::table('mt_equipment')->where('id', $row['equipment_id'])->value('title') : null;
                AppNotification::send($c->id, array_diff($this->managers($c->id), [$u->id]), 'maintenance', ($row['priority'] === 'urgent' ? 'Panne urgente : ' : 'Demande d\'intervention : ').Str::limit($row['title'], 80),
                    ($eq ? $eq.' — ' : '').'signalée par '.$u->name.($row['equipment_stopped'] ? ' (équipement à l\'arrêt).' : '.'), 'maintenance', (string) $row['id'], $u->id);
            }
            if ($row['assignee_id'] && (int) $row['assignee_id'] !== (int) ($old['assignee_id'] ?? 0) && (int) $row['assignee_id'] !== $u->id) {
                $this->notifyAssignee($c, $row, $u->id);
            }
        } elseif ($res === 'readings') {
            $eq = DB::table('mt_equipment')->where('id', $row['equipment_id'])->first();
            $last = DB::table('mt_readings')->where('equipment_id', $row['equipment_id'])->orderByDesc('read_on')->orderByDesc('id')->value('value');
            if ($eq && $last !== null) {
                DB::table('mt_equipment')->where('id', $eq->id)->update(['meter_value' => $last, 'updated_at' => now()]);
                $this->generate($c, (int) $eq->id);
            }
        } elseif ($res === 'equipment' && $old && $row['meter_value'] !== null && (float) $row['meter_value'] !== (float) $old['meter_value']) {
            $this->generate($c, (int) $row['id']);
        }
    }

    public function deleting(string $res, array $row, User $u): void
    {
        if ($res === 'equipment' && DB::table('mt_orders')->where('equipment_id', $row['id'])->exists()) {
            throw new \DomainException('Cet équipement a un historique d\'interventions : passez-le « Hors service » plutôt que de le supprimer.');
        }
        if ($res === 'equipment') {
            DB::table('mt_plans')->where('equipment_id', $row['id'])->delete();
            DB::table('mt_readings')->where('equipment_id', $row['id'])->delete();
        }
    }

    protected function notifyAssignee(Company $c, array $row, ?int $from = null): void
    {
        $eq = $row['equipment_id'] ? DB::table('mt_equipment')->where('id', $row['equipment_id'])->value('title') : null;
        AppNotification::send($c->id, [(int) $row['assignee_id']], 'maintenance', 'Intervention confiée : '.Str::limit($row['title'], 80),
            $row['number'].($eq ? ' — '.$eq : '').($row['planned_on'] ? ' — prévue le '.date('d/m/Y', strtotime($row['planned_on'])) : '').'.', 'maintenance', (string) $row['id'], $from);
    }

    protected function managers(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('is_active', true)->get()->filter(fn ($x) => $x->hasPermission('maintenance.manage'))->pluck('id')->all();
    }

    /** Approuver (planifier), démarrer, mettre en attente, terminer, annuler. */
    public function action(Company $c, User $u, int $id, string $action, array $in): array
    {
        $o = (array) (DB::table('mt_orders')->where('company_id', $c->id)->where('id', $id)->lockForUpdate()->first() ?: throw new \DomainException('Ordre de travail introuvable.'));
        $manage = $this->canManage($u);
        $assignee = (int) $o['assignee_id'] === $u->id && $u->hasPermission('maintenance.work');
        if (in_array($action, ['approve', 'cancel'], true) && ! $manage) {
            throw new \DomainException('Réservé au responsable maintenance.');
        }
        if (in_array($action, ['start', 'hold', 'finish'], true) && ! $manage && ! $assignee) {
            throw new \DomainException('Cette intervention n\'est pas à vous.');
        }
        $at = isset($in['at']) && Carbon::parse($in['at'])->isPast() ? Carbon::parse($in['at']) : now();
        $set = [];
        $from = fn (array $ok) => in_array($o['status'], $ok, true) ?: throw new \DomainException('Impossible : l\'intervention est « '.self::STATUSES[$o['status']][0].' ».');
        switch ($action) {
            case 'approve':
                $from(['requested', 'planned', 'on_hold']);
                $set = ['status' => 'planned', 'assignee_id' => $in['assignee_id'] ?? $o['assignee_id'], 'planned_on' => $in['planned_on'] ?? ($o['planned_on'] ?: now()->toDateString()),
                    'priority' => $in['priority'] ?? $o['priority']];
                break;
            case 'start':
                $from(['requested', 'planned', 'on_hold']);
                $set = ['status' => 'in_progress', 'started_at' => $o['started_at'] ?: $at, 'assignee_id' => $o['assignee_id'] ?: $u->id];
                if ($o['equipment_id'] && $o['kind'] !== 'corrective') {
                    DB::table('mt_equipment')->where('id', $o['equipment_id'])->where('status', 'running')->update(['status' => 'maintenance']);
                }
                break;
            case 'hold':
                $from(['in_progress', 'planned']);
                $set = ['status' => 'on_hold', 'report' => trim(($o['report'] ? $o['report']."\n" : '').($in['note'] ?? ''))];
                break;
            case 'finish':
                $from(['planned', 'in_progress', 'on_hold', 'requested']);
                $started = $o['started_at'] ? Carbon::parse($o['started_at']) : $at;
                $set = ['status' => 'done', 'finished_at' => $at, 'started_at' => $o['started_at'] ?: $at, 'assignee_id' => $o['assignee_id'] ?: $u->id];
                foreach (['report', 'cause', 'parts_used', 'labour_hours', 'labour_cost', 'parts_cost', 'external_cost', 'meter_reading', 'downtime_hours'] as $k) {
                    if (array_key_exists($k, $in) && $in[$k] !== null && $in[$k] !== '') {
                        $set[$k] = $in[$k];
                    }
                }
                $set['labour_hours'] ??= $o['labour_hours'] ?? round(max(0.25, $started->diffInMinutes($at) / 60), 2);
                if ($o['equipment_stopped'] && ! isset($set['downtime_hours']) && $o['downtime_hours'] === null) {
                    $set['downtime_hours'] = round(Carbon::parse($o['created_at'])->diffInMinutes($at) / 60, 2);
                }
                break;
            case 'cancel':
                $from(self::OPEN);
                $set = ['status' => 'cancelled'];
                break;
        }
        DB::table('mt_orders')->where('id', $id)->update($set + ['updated_at' => now()]);
        $row = (array) DB::table('mt_orders')->find($id);
        if (in_array($action, ['finish', 'cancel'], true)) {
            $this->closed($c, $row, $u);
        }
        if ($action === 'approve' && $row['assignee_id'] && (int) $row['assignee_id'] !== $u->id) {
            $this->notifyAssignee($c, $row, $u->id);
        }

        return $row;
    }

    /** Fin d'intervention : équipement remis en service, compteur, plan préventif avancé, demandeur prévenu. */
    protected function closed(Company $c, array $o, User $u): void
    {
        if ($o['equipment_id']) {
            $stillDown = DB::table('mt_orders')->where('equipment_id', $o['equipment_id'])->whereIn('status', self::OPEN)->where('equipment_stopped', true)->exists();
            if (! $stillDown) {
                DB::table('mt_equipment')->where('id', $o['equipment_id'])->whereIn('status', ['down', 'maintenance'])->update(['status' => 'running', 'updated_at' => now()]);
            }
            if ($o['status'] === 'done' && $o['meter_reading'] !== null) {
                DB::table('mt_readings')->insert(['company_id' => $c->id, 'created_by' => $u->id, 'equipment_id' => $o['equipment_id'], 'value' => $o['meter_reading'], 'read_on' => now()->toDateString(),
                    'notes' => 'Relevé à l\'intervention '.$o['number'], 'created_at' => now(), 'updated_at' => now()]);
                DB::table('mt_equipment')->where('id', $o['equipment_id'])->where(fn ($q) => $q->whereNull('meter_value')->orWhere('meter_value', '<', $o['meter_reading']))->update(['meter_value' => $o['meter_reading']]);
            }
        }
        if ($o['status'] === 'done' && $o['plan_id'] && ($p = DB::table('mt_plans')->where('id', $o['plan_id'])->first())) {
            $done = substr((string) $o['finished_at'], 0, 10);
            $meter = $o['meter_reading'] ?? ($o['equipment_id'] ? DB::table('mt_equipment')->where('id', $o['equipment_id'])->value('meter_value') : null);
            DB::table('mt_plans')->where('id', $p->id)->update([
                'last_done_on' => $done, 'next_due_on' => $p->trigger === 'date' ? self::nextDate($done, (int) $p->every, $p->unit) : null,
                'last_meter' => $meter, 'next_meter' => $p->trigger === 'meter' && $meter !== null ? (float) $meter + (int) $p->every : $p->next_meter, 'updated_at' => now(),
            ]);
        }
        if ($o['status'] === 'done' && $o['requester_id'] && (int) $o['requester_id'] !== $u->id) {
            AppNotification::send($c->id, [(int) $o['requester_id']], 'maintenance', 'Intervention terminée', $o['number'].' — '.Str::limit($o['title'], 90).($o['report'] ? ' : '.Str::limit($o['report'], 120) : '.'), 'maintenance', (string) $o['id'], $u->id);
        }
    }

    /** Crée les ordres de travail préventifs arrivés à échéance (date − délai, ou compteur atteint). */
    public function generate(Company $c, ?int $equipmentId = null): int
    {
        $n = 0;
        $plans = DB::table('mt_plans')->where('company_id', $c->id)->where('active', true)->when($equipmentId, fn ($q) => $q->where('equipment_id', $equipmentId))->get();
        foreach ($plans as $p) {
            $eq = DB::table('mt_equipment')->where('id', $p->equipment_id)->first();
            if (! $eq || $eq->status === 'retired') {
                continue;
            }
            if (DB::table('mt_orders')->where('plan_id', $p->id)->whereIn('status', self::OPEN)->exists()) {
                continue;
            }
            if ($p->trigger === 'date') {
                if (! $p->next_due_on || Carbon::parse($p->next_due_on)->subDays((int) $p->lead_days)->gt(now()->startOfDay())) {
                    continue;
                }
                $due = $p->next_due_on;
            } else {
                // compteur : à 95 % de l'intervalle (marge pour planifier)
                if ($p->next_meter === null || $eq->meter_value === null || (float) $eq->meter_value < (float) $p->next_meter - 0.05 * (int) $p->every) {
                    continue;
                }
                $due = now()->addDays(min(7, (int) $p->lead_days))->toDateString();
            }
            $id = DB::table('mt_orders')->insertGetId([
                'company_id' => $c->id, 'number' => 'OT-'.now()->format('Y').'-'.str_pad((string) (DB::table('mt_orders')->where('company_id', $c->id)->whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT),
                'title' => $p->title.' — '.$eq->title, 'equipment_id' => $eq->id, 'plan_id' => $p->id, 'kind' => 'preventive', 'priority' => 'normal',
                'status' => $p->assignee_id ? 'planned' : 'requested', 'assignee_id' => $p->assignee_id ?: $eq->owner_id, 'planned_on' => $due, 'checklist' => $p->checklist,
                'description' => $p->trigger === 'meter' ? 'Compteur : '.rtrim(rtrim(number_format((float) $eq->meter_value, 1, ',', ' '), '0'), ',').' / échéance '.rtrim(rtrim(number_format((float) $p->next_meter, 1, ',', ' '), '0'), ',').' '.$eq->meter_unit.'.' : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = (array) DB::table('mt_orders')->find($id);
            if ($row['assignee_id']) {
                $this->notifyAssignee($c, $row);
            } else {
                AppNotification::send($c->id, $this->managers($c->id), 'maintenance', 'Maintenance préventive à planifier', $row['title'].' — échéance le '.date('d/m/Y', strtotime($due)).'.', 'maintenance', (string) $id);
            }
            $n++;
        }

        return $n;
    }

    /** Tâche planifiée : préventif à créer, interventions en retard (rappel quotidien au technicien). */
    public function alerts(Company $c): int
    {
        $n = $this->generate($c);
        foreach (DB::table('mt_orders')->where('company_id', $c->id)->whereIn('status', ['planned', 'on_hold'])->whereNotNull('planned_on')->where('planned_on', '<', now()->toDateString())->get() as $o) {
            if ($o->reminded_at && Carbon::parse($o->reminded_at)->gt(now()->subHours(20))) {
                continue;
            }
            AppNotification::send($c->id, $o->assignee_id ? [$o->assignee_id] : $this->managers($c->id), 'maintenance', 'Intervention en retard', $o->number.' — '.Str::limit($o->title, 90).' (prévue le '.date('d/m/Y', strtotime($o->planned_on)).').', 'maintenance', (string) $o->id);
            DB::table('mt_orders')->where('id', $o->id)->update(['reminded_at' => now()]);
            $n++;
        }

        return $n;
    }

    public function dashboard(Company $c): array
    {
        $o = DB::table('mt_orders')->where('company_id', $c->id);
        $eq = DB::table('mt_equipment')->where('company_id', $c->id)->where('status', '!=', 'retired');
        $since = now()->subDays(90);
        $fixed = (clone $o)->where('kind', 'corrective')->where('status', 'done')->where('finished_at', '>=', $since)->get(['created_at', 'finished_at']);
        $mttr = $fixed->count() ? round($fixed->avg(fn ($x) => Carbon::parse($x->created_at)->diffInMinutes(Carbon::parse($x->finished_at)) / 60), 1) : null;
        $prevDone = (clone $o)->where('kind', 'preventive')->where('status', 'done')->where('finished_at', '>=', $since)->count();
        $prevLate = (clone $o)->where('kind', 'preventive')->whereIn('status', self::OPEN)->where('planned_on', '<', now()->toDateString())->count();
        $count = (clone $eq)->count();
        $down30 = (float) (clone $o)->where('status', 'done')->where('finished_at', '>=', now()->subDays(30))->sum('downtime_hours');

        return [
            'equipment' => $count,
            'down' => (clone $eq)->where('status', 'down')->count(),
            'open' => (clone $o)->whereIn('status', self::OPEN)->count(),
            'requests' => (clone $o)->where('status', 'requested')->count(),
            'late' => (clone $o)->whereIn('status', ['planned', 'on_hold', 'requested'])->where('planned_on', '<', now()->toDateString())->count(),
            'urgent' => (clone $o)->whereIn('status', self::OPEN)->where('priority', 'urgent')->count(),
            'mttr' => $mttr,
            'preventive_rate' => $prevDone + $prevLate ? round($prevDone / ($prevDone + $prevLate) * 100) : null,
            'availability' => $count ? round(max(0, 100 - $down30 / ($count * 24 * 30) * 100), 1) : null,
            'cost_month' => (float) (clone $o)->where('status', 'done')->where('finished_at', '>=', now()->startOfMonth())->sum(DB::raw('coalesce(labour_cost, 0) + coalesce(parts_cost, 0) + coalesce(external_cost, 0)')),
            'causes' => (clone $o)->where('kind', 'corrective')->where('status', 'done')->whereNotNull('cause')->where('finished_at', '>=', now()->subYear())
                ->selectRaw('cause, count(*) as n')->groupBy('cause')->orderByDesc('n')->limit(5)->get()->map(fn ($x) => ['cause' => self::CAUSES[$x->cause] ?? $x->cause, 'n' => (int) $x->n])->all(),
        ];
    }
}
