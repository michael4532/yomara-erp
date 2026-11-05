<?php

namespace App\Modules\Commercial;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Règles du service commercial : objectifs et réalisé, visites et comptes rendus, tournées reliées au parc automobile. */
class CommercialHooks
{
    public const KINDS = ['visit' => 'Visite', 'demo' => 'Démonstration', 'prospecting' => 'Prospection (porte-à-porte)', 'delivery' => 'Livraison / installation', 'collection' => 'Recouvrement', 'call' => 'Appel / visio'];

    public const VISIT_STATUSES = ['planned' => ['Prévue', '#1F6FEB'], 'done' => ['Faite', '#16A34A'], 'missed' => ['Client absent', '#D97706'], 'cancelled' => ['Annulée', '#94A3B8']];

    public const OUTCOMES = ['order' => ['Commande', '#16A34A'], 'quote' => ['Devis demandé', '#1F6FEB'], 'interested' => ['Intéressé', '#0891B2'], 'follow_up' => ['À relancer', '#D97706'],
        'not_interested' => ['Pas intéressé', '#94A3B8'], 'payment' => ['Paiement obtenu', '#16A34A'], 'complaint' => ['Réclamation', '#DC2626']];

    public const TOUR_STATUSES = ['planned' => ['Prévue', '#1F6FEB'], 'in_progress' => ['En cours', '#D97706'], 'done' => ['Terminée', '#16A34A'], 'cancelled' => ['Annulée', '#94A3B8']];

    public const PERIODS = ['month' => 'Mois', 'quarter' => 'Trimestre', 'year' => 'Année'];

    public static function isManager(User $u): bool
    {
        return $u->hasPermission('commercial.manage');
    }

    public static function periodEnd(string $kind, string $start): string
    {
        $s = Carbon::parse($start);

        $end = match ($kind) {
            'quarter' => $s->copy()->addMonths(3)->subDay(),
            'year' => $s->copy()->addYear()->subDay(),
            default => $s->copy()->addMonth()->subDay(),
        };

        return $end->toDateString();
    }

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        $m = array_merge($old ?? [], $data);
        if (in_array($res, ['visits', 'tours'], true) && ! self::isManager($u)) {
            if ($old && (int) $old['user_id'] !== $u->id) {
                throw new \DomainException('Cette fiche appartient à un autre commercial.');
            }
            $data['user_id'] = $u->id;
        }
        if ($res === 'visits') {
            if (empty($m['partner_id']) && empty(trim((string) ($m['prospect'] ?? '')))) {
                throw new \DomainException('Indiquez le client ou le nom du prospect.');
            }
            if (($data['status'] ?? null) === 'done' && ($old['status'] ?? null) !== 'done') {
                $data['done_at'] = now()->format('Y-m-d H:i:s');
            }
            if (! empty($data['outcome']) && ($m['status'] ?? 'planned') === 'planned') {
                $data['status'] = 'done';
                $data['done_at'] = now()->format('Y-m-d H:i:s');
            }
        } elseif ($res === 'targets') {
            $start = Carbon::parse($m['period_start']);
            $kind = $m['period_kind'] ?? 'month';
            $start = match ($kind) {
                'quarter' => $start->copy()->firstOfQuarter(),
                'year' => $start->copy()->startOfYear(),
                default => $start->copy()->startOfMonth(),
            };
            $data['period_start'] = $start->toDateString();
            $data['period_end'] = self::periodEnd($kind, $data['period_start']);
            $name = User::find($m['user_id'])?->name ?? '';
            $label = match ($kind) {
                'quarter' => 'T'.$start->quarter.' '.$start->year,
                'year' => (string) $start->year,
                default => ucfirst($start->locale('fr')->translatedFormat('F Y')),
            };
            $data['title'] = trim($name.' — '.$label);
            $dup = DB::table('cm_targets')->where('company_id', app('tenant')->id)->where('user_id', $m['user_id'])->where('period_kind', $kind)
                ->where('period_start', $data['period_start'])->when($old, fn ($q) => $q->where('id', '!=', $old['id']))->exists();
            if ($dup) {
                throw new \DomainException('Un objectif existe déjà pour '.$name.' sur cette période.');
            }
        }

        return $data;
    }

    public function saved(string $res, array $row, ?array $old, User $u): void
    {
        $c = app('tenant');
        if ($res === 'visits' && $row['status'] === 'done' && $row['next_date'] && ! $row['follow_up_id'] && $c->hasModule('crm') && Schema::hasTable('crm_activities')) {
            $id = (string) Str::uuid();
            DB::table('crm_activities')->insert(['id' => $id, 'company_id' => $c->id, 'partner_id' => $row['partner_id'] ?: null, 'type' => 'call',
                'subject' => ($row['next_step'] ?: 'Relance après visite').' — '.($row['prospect'] ?: DB::table('partners')->where('id', $row['partner_id'])->value('name')),
                'due_at' => Carbon::parse($row['next_date'])->setTime(9, 0), 'done' => false, 'owner_id' => $row['user_id'],
                'notes' => trim("Visite du ".date('d/m/Y', strtotime($row['planned_at'] ?? 'now'))." : ".$row['title']."\n".($row['report'] ?? '')), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cm_visits')->where('id', $row['id'])->update(['follow_up_id' => $id]);
        }
        if ($res === 'visits' && $row['tour_id'] && $row['status'] !== 'planned') {
            DB::table('cm_tours')->where('id', $row['tour_id'])->where('status', 'planned')->update(['status' => 'in_progress', 'updated_at' => now()]);
        }
    }

    public function present(string $res, array $row): array
    {
        if ($res === 'visits') {
            $row['client'] = $row['partner_id_label'] ?? null ?: $row['prospect'];
        } elseif ($res === 'tours') {
            $v = DB::table('cm_visits')->where('tour_id', $row['id']);
            $row['visits'] = (clone $v)->whereIn('status', ['done', 'missed'])->count().' / '.(clone $v)->where('status', '!=', 'cancelled')->count();
            $row['vehicle'] = null;
            if ($row['trip_id'] && Schema::hasTable('fl_trips') && ($t = DB::table('fl_trips')->where('id', $row['trip_id'])->first())) {
                $veh = $t->vehicle_id ? DB::table('fl_vehicles')->where('id', $t->vehicle_id)->value('title') : null;
                $row['vehicle'] = ($veh ?: 'Véhicule demandé').' · '.(\App\Modules\Flotte\FlotteHooks::TRIP_STATUSES[$t->status][0] ?? $t->status);
            }
        } elseif ($res === 'targets') {
            $p = $this->performance($row['company_id'], (int) $row['user_id'], $row['period_start'], $row['period_end'] ?? self::periodEnd($row['period_kind'], $row['period_start']));
            $row['revenue_done'] = $p['revenue'];
            $row['progress'] = $row['revenue_target'] > 0 ? round($p['revenue'] / $row['revenue_target'] * 100).' %' : null;
            $row['activity'] = $p['visits'].($row['visits_target'] ? '/'.$row['visits_target'] : '').' visites · '.$p['customers'].($row['customers_target'] ? '/'.$row['customers_target'] : '').' clients · '
                .$p['quotes'].($row['quotes_target'] ? '/'.$row['quotes_target'] : '').' devis';
            $row['performance'] = $p;
        }

        return $row;
    }

    /** Réalisé d'un commercial sur une période : CA HT facturé (avoirs déduits), visites faites, nouveaux clients, devis. */
    public function performance(int $companyId, int $userId, string $from, string $to): array
    {
        $docs = DB::table('trade_documents')->where('company_id', $companyId)->where('direction', 'sale')->where('user_id', $userId)
            ->whereNull('deleted_at')->whereBetween('date', [$from, $to]);
        $inv = (clone $docs)->where('type', 'invoice')->where('status', 'validated');
        $revenue = (float) (clone $inv)->sum('subtotal') - (float) (clone $docs)->where('type', 'credit_note')->where('status', 'validated')->sum('subtotal');
        $partners = (clone $inv)->whereNotNull('partner_id')->distinct()->pluck('partner_id');
        $customers = $partners->isEmpty() ? 0 : $partners->filter(fn ($p) => ! DB::table('trade_documents')->where('company_id', $companyId)->where('direction', 'sale')->where('type', 'invoice')
            ->where('status', 'validated')->where('partner_id', $p)->whereNull('deleted_at')->where('date', '<', $from)->exists())->count();

        return [
            'revenue' => round($revenue, 2),
            'invoices' => (clone $inv)->count(),
            'quotes' => (clone $docs)->where('type', 'quote')->where('status', '!=', 'cancelled')->count(),
            'customers' => $customers,
            'visits' => DB::table('cm_visits')->where('company_id', $companyId)->where('user_id', $userId)->whereIn('status', ['done', 'missed'])
                ->whereBetween('planned_at', [$from, $to.' 23:59:59'])->count(),
        ];
    }

    public function dashboard(Company $c, User $viewer, ?string $month = null): array
    {
        $start = $month ? Carbon::parse($month.'-01') : now()->startOfMonth();
        $from = $start->toDateString();
        $to = $start->copy()->endOfMonth()->toDateString();
        $all = $viewer->hasPermission('commercial.manage') || $viewer->hasPermission('commercial.view');
        $users = User::where('company_id', $c->id)->where('is_active', true)->get()
            ->filter(fn ($u) => $u->hasPermission('commercial.field') && ! $u->hasPermission('commercial.manage'))
            ->merge(User::whereIn('id', DB::table('cm_targets')->where('company_id', $c->id)->where('period_start', '<=', $to)->where('period_end', '>=', $from)->pluck('user_id'))->get())
            ->unique('id')->filter(fn ($u) => $all || $u->id === $viewer->id);
        if (! $all && $users->isEmpty()) {
            $users = collect([$viewer]);
        }
        $pipeline = $c->hasModule('crm') && Schema::hasTable('crm_leads')
            ? DB::table('crm_leads')->where('company_id', $c->id)->where('status', 'open')->whereNull('deleted_at')->groupBy('owner_id')->select('owner_id', DB::raw('SUM(expected_revenue) as v'), DB::raw('COUNT(*) as n'))->get()->keyBy('owner_id')
            : collect();
        $rows = $users->map(function ($u) use ($c, $from, $to, $pipeline) {
            $p = $this->performance($c->id, $u->id, $from, $to);
            $t = DB::table('cm_targets')->where('company_id', $c->id)->where('user_id', $u->id)->where('period_kind', 'month')->where('period_start', $from)->first();

            return ['user_id' => $u->id, 'name' => $u->name] + $p + [
                'revenue_target' => $t ? (float) $t->revenue_target : null, 'visits_target' => $t?->visits_target, 'customers_target' => $t?->customers_target, 'quotes_target' => $t?->quotes_target,
                'progress' => $t && $t->revenue_target > 0 ? round($p['revenue'] / $t->revenue_target * 100) : null,
                'pipeline' => (float) ($pipeline[$u->id]->v ?? 0), 'opportunities' => (int) ($pipeline[$u->id]->n ?? 0),
                'planned' => DB::table('cm_visits')->where('company_id', $c->id)->where('user_id', $u->id)->where('status', 'planned')->where('planned_at', '>=', now())->count(),
            ];
        })->sortByDesc('revenue')->values();
        $visits = DB::table('cm_visits')->where('company_id', $c->id)->when(! $all, fn ($q) => $q->where('user_id', $viewer->id));

        return [
            'month' => $start->format('Y-m'),
            'label' => ucfirst($start->locale('fr')->translatedFormat('F Y')),
            'team' => $rows,
            'revenue' => round($rows->sum('revenue'), 2),
            'target' => round($rows->sum('revenue_target'), 2),
            'visits' => $rows->sum('visits'),
            'customers' => $rows->sum('customers'),
            'today' => (clone $visits)->whereBetween('planned_at', [now()->startOfDay(), now()->endOfDay()])->orderBy('planned_at')->get(['id', 'title', 'partner_id', 'prospect', 'planned_at', 'status', 'user_id', 'address'])
                ->map(fn ($v) => (array) $v + ['client' => $v->prospect ?: DB::table('partners')->where('id', $v->partner_id)->value('name'), 'seller' => DB::table('users')->where('id', $v->user_id)->value('name')])->values(),
            'reports_due' => (clone $visits)->where('status', 'planned')->where('planned_at', '<', now()->subHours(2))->count(),
            'outcomes' => (clone $visits)->where('status', 'done')->whereBetween('planned_at', [$from, $to.' 23:59:59'])->whereNotNull('outcome')->groupBy('outcome')->select('outcome', DB::raw('COUNT(*) as n'))->pluck('n', 'outcome'),
        ];
    }

    /** Demande au parc automobile un véhicule pour la tournée. */
    public function requestVehicle(Company $c, User $u, int $tourId, array $in): array
    {
        if (! $c->hasModule('flotte') || ! Schema::hasTable('fl_trips')) {
            throw new \DomainException('Le module Parc automobile n\'est pas activé.');
        }
        $tour = DB::table('cm_tours')->where('company_id', $c->id)->where('id', $tourId)->first() ?: throw new \DomainException('Tournée introuvable.');
        if ((int) $tour->user_id !== $u->id && ! self::isManager($u)) {
            throw new \DomainException('Seul le commercial de la tournée demande le véhicule.');
        }
        if ($tour->trip_id && DB::table('fl_trips')->where('id', $tour->trip_id)->whereNotIn('status', ['refused', 'cancelled'])->exists()) {
            throw new \DomainException('Un véhicule est déjà demandé pour cette tournée.');
        }
        $day = Carbon::parse($tour->date);
        $start = $day->copy()->setTimeFromTimeString($in['start'] ?? '08:00');
        $end = $day->copy()->setTimeFromTimeString($in['end'] ?? '17:00');
        $first = DB::table('cm_visits')->where('tour_id', $tour->id)->whereNotNull('partner_id')->value('partner_id');
        $data = ['title' => 'Tournée commerciale : '.$tour->title, 'purpose' => 'customer', 'destination' => $tour->zone, 'partner_id' => $first, 'passengers' => $in['passengers'] ?? null,
            'requester_id' => $tour->user_id, 'driver_id' => $tour->user_id, 'planned_start' => $start->format('Y-m-d H:i:s'), 'planned_end' => $end->format('Y-m-d H:i:s'),
            'vehicle_id' => $in['vehicle_id'] ?? null, 'status' => 'requested',
            'notes' => DB::table('cm_visits')->where('tour_id', $tour->id)->where('status', '!=', 'cancelled')->orderBy('planned_at')->get(['title', 'prospect', 'partner_id'])
                ->map(fn ($v) => '• '.($v->prospect ?: DB::table('partners')->where('id', $v->partner_id)->value('name')).' — '.$v->title)->implode("\n") ?: null];
        $h = app(\App\Modules\Flotte\FlotteHooks::class);
        $data = $h->saving('trips', $data, null, $u);
        $data['requester_id'] = $tour->user_id;
        $id = DB::table('fl_trips')->insertGetId($data + ['company_id' => $c->id, 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        $row = (array) DB::table('fl_trips')->where('id', $id)->first();
        $h->saved('trips', $row, null, $u);
        DB::table('cm_tours')->where('id', $tour->id)->update(['trip_id' => $id, 'updated_at' => now()]);

        return $row;
    }
}
