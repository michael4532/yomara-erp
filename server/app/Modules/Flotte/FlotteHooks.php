<?php

namespace App\Modules\Flotte;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Règles du parc automobile : réservation sans conflit, sortie et retour au compteur, carburant, échéances des véhicules. */
class FlotteHooks
{
    public const KINDS = ['car' => 'Voiture', 'pickup' => 'Pick-up', 'van' => 'Fourgonnette', 'truck' => 'Camion', 'bus' => 'Minibus', 'moto' => 'Moto', 'tricycle' => 'Tricycle'];

    public const FUELS = ['diesel' => 'Gasoil', 'petrol' => 'Essence', 'hybrid' => 'Hybride', 'electric' => 'Électrique'];

    public const VEHICLE_STATUSES = ['available' => ['Disponible', '#16A34A'], 'out' => ['En sortie', '#D97706'], 'maintenance' => ['Au garage', '#9333EA'], 'retired' => ['Hors service', '#94A3B8']];

    public const PURPOSES = ['customer' => 'Visite client / prospection', 'delivery' => 'Livraison', 'intervention' => 'Intervention technique', 'admin' => 'Course administrative',
        'mission' => 'Mission', 'staff' => 'Transport du personnel', 'other' => 'Autre'];

    public const TRIP_STATUSES = ['requested' => ['Demandée', '#1F6FEB'], 'approved' => ['Approuvée', '#0891B2'], 'refused' => ['Refusée', '#DC2626'],
        'out' => ['En sortie', '#D97706'], 'returned' => ['Rentrée', '#16A34A'], 'cancelled' => ['Annulée', '#94A3B8']];

    public const PAYMENTS = ['cash' => 'Espèces', 'card' => 'Carte carburant', 'voucher' => 'Bon / ticket', 'momo' => 'Mobile Money', 'account' => 'Compte station'];

    public const COST_KINDS = ['service' => 'Vidange / révision', 'tyres' => 'Pneus', 'repair' => 'Réparation', 'insurance' => 'Assurance', 'inspection' => 'Visite technique',
        'tax' => 'Vignette / patente', 'wash' => 'Lavage', 'parking' => 'Parking / péage', 'fine' => 'Amende', 'other' => 'Autre'];

    public const FUEL_LEVELS = ['empty' => 'Réserve', 'quarter' => '1/4', 'half' => '1/2', 'three' => '3/4', 'full' => 'Plein'];

    public static function isStaff(User $u): bool
    {
        return $u->hasPermission('flotte.manage') || $u->hasPermission('flotte.approve');
    }

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        $c = app('tenant');
        if ($res === 'trips') {
            if ($old && ! self::isStaff($u) && (int) $old['requester_id'] !== $u->id) {
                throw new \DomainException('Seul le demandeur ou le responsable du parc modifie cette demande.');
            }
            if ($old && ! in_array($old['status'], ['requested', 'approved'], true) && ! $u->hasPermission('flotte.manage')) {
                throw new \DomainException('Sortie déjà en cours ou terminée : elle ne se modifie plus.');
            }
            if (! self::isStaff($u)) {
                $data['requester_id'] = $old['requester_id'] ?? $u->id;
                if ($old && $old['status'] === 'approved' && (isset($data['planned_start']) || isset($data['planned_end']) || isset($data['vehicle_id']))) {
                    $data['status'] = 'requested'; // nouvelle date ou véhicule : à réapprouver
                }
            }
            $m = array_merge($old ?? [], $data);
            if (! empty($m['planned_start']) && ! empty($m['planned_end']) && Carbon::parse($m['planned_end'])->lte(Carbon::parse($m['planned_start']))) {
                throw new \DomainException('Le retour prévu doit être après le départ.');
            }
            if (! empty($m['vehicle_id'])) {
                $this->assertFree($c->id, (int) $m['vehicle_id'], $m['planned_start'], $m['planned_end'], $old['id'] ?? null);
            }
            if (! $old) {
                $data['number'] = Sequence::next($c->id, 'flotte.trip', 'SV');
            }
        }

        return $data;
    }

    /** Refuse une réservation qui chevauche une sortie approuvée ou en cours du même véhicule. */
    public function assertFree(int $companyId, int $vehicleId, $start, $end, ?int $except = null): void
    {
        $v = DB::table('fl_vehicles')->where('company_id', $companyId)->where('id', $vehicleId)->first();
        if ($v && $v->status === 'retired') {
            throw new \DomainException('« '.$v->title.' » est hors service.');
        }
        $clash = DB::table('fl_trips')->where('company_id', $companyId)->where('vehicle_id', $vehicleId)->whereIn('status', ['approved', 'out'])
            ->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where('planned_start', '<', Carbon::parse($end)->format('Y-m-d H:i:s'))->where('planned_end', '>', Carbon::parse($start)->format('Y-m-d H:i:s'))->first();
        if ($clash) {
            $who = DB::table('users')->where('id', $clash->requester_id)->value('name');
            throw new \DomainException('« '.($v->title ?? 'Véhicule').' » est déjà réservé du '.date('d/m H:i', strtotime($clash->planned_start)).' au '.date('d/m H:i', strtotime($clash->planned_end)).($who ? ' ('.$who.')' : '').'.');
        }
    }

    public function saved(string $res, array $row, ?array $old, User $u): void
    {
        if ($res === 'trips' && ! $old) {
            AppNotification::send($row['company_id'], $this->approvers($row['company_id']), 'flotte', 'Demande de véhicule',
                $u->name.' : '.$row['title'].' — '.date('d/m H:i', strtotime($row['planned_start'])).' → '.date('d/m H:i', strtotime($row['planned_end'])).'.', 'flotte', (string) $row['id'], $u->id);
        } elseif ($res === 'fuel' || $res === 'costs') {
            if (! empty($row['odometer'])) {
                DB::table('fl_vehicles')->where('id', $row['vehicle_id'])->where('odometer', '<', $row['odometer'])->update(['odometer' => $row['odometer'], 'updated_at' => now()]);
            }
            if ($res === 'costs') {
                $set = match ($row['kind']) {
                    'insurance' => $row['next_date'] ? ['insurance_until' => $row['next_date']] : [],
                    'inspection' => $row['next_date'] ? ['inspection_until' => $row['next_date']] : [],
                    'service' => array_filter(['next_service_km' => $row['next_km'], 'next_service_date' => $row['next_date']]),
                    default => [],
                };
                if ($set) {
                    DB::table('fl_vehicles')->where('id', $row['vehicle_id'])->update($set + ['alerted_on' => null, 'updated_at' => now()]);
                }
            }
        }
    }

    public function deleting(string $res, array $row, User $u): void
    {
        if ($res === 'vehicles' && DB::table('fl_trips')->where('vehicle_id', $row['id'])->exists()) {
            throw new \DomainException('Ce véhicule a des sorties enregistrées : passez-le « Hors service » plutôt que de le supprimer.');
        }
    }

    public function present(string $res, array $row): array
    {
        if ($res === 'vehicles') {
            $a = [];
            foreach (['insurance_until' => 'Assurance', 'inspection_until' => 'Visite technique', 'next_service_date' => 'Entretien'] as $k => $label) {
                if (! empty($row[$k]) && Carbon::parse($row[$k])->lte(now()->addDays(15))) {
                    $a[] = $label.(Carbon::parse($row[$k])->isPast() ? ' expirée' : ' le '.date('d/m', strtotime($row[$k])));
                }
            }
            if (! empty($row['next_service_km']) && $row['odometer'] >= $row['next_service_km'] - 500) {
                $a[] = 'Vidange à '.number_format($row['next_service_km'], 0, ',', ' ').' km';
            }
            $row['alerts'] = $a ? implode(' · ', $a) : null;
        } elseif ($res === 'trips') {
            $row['distance'] = $row['back_km'] !== null && $row['out_km'] !== null ? max(0, $row['back_km'] - $row['out_km']) : null;
            $row['fuel_spent'] = (float) DB::table('fl_fuel')->where('trip_id', $row['id'])->sum('amount') ?: null;
        } elseif ($res === 'fuel') {
            $row['consumption'] = null;
            if ($row['full_tank'] && $row['odometer'] && $row['liters']) {
                $prev = DB::table('fl_fuel')->where('vehicle_id', $row['vehicle_id'])->where('full_tank', true)->whereNotNull('odometer')
                    ->where('odometer', '<', $row['odometer'])->orderByDesc('odometer')->first();
                if ($prev && $row['odometer'] > $prev->odometer) {
                    $row['consumption'] = number_format($row['liters'] / ($row['odometer'] - $prev->odometer) * 100, 1, ',', ' ');
                }
            }
        }

        return $row;
    }

    protected function approvers(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('is_active', true)->get()->filter(fn ($x) => self::isStaff($x))->pluck('id')->all();
    }

    // ------------------------------------------------------------------ circuit d'une sortie

    public function action(Company $c, User $u, int $id, string $action, array $in): array
    {
        $t = DB::table('fl_trips')->where('company_id', $c->id)->where('id', $id)->first() ?: throw new \DomainException('Sortie introuvable.');
        // Saisie faite hors ligne puis rejouée : on garde l'heure réelle (7 jours au plus, jamais dans le futur)
        $when = now();
        if (! empty($in['at'])) {
            $at = Carbon::parse($in['at'])->setTimezone(config('app.timezone'));
            $when = $at->between(now()->subDays(7), now()->addMinutes(5)) ? $at->min(now()) : now();
        }
        $own = (int) $t->requester_id === $u->id || (int) $t->driver_id === $u->id;
        $set = [];
        $vehicleId = (int) ($in['vehicle_id'] ?? $t->vehicle_id);
        switch ($action) {
            case 'approve':
            case 'refuse':
                $this->assert(self::isStaff($u), 'Seul le responsable du parc décide des demandes.');
                $this->assert(in_array($t->status, ['requested', 'approved'], true), 'Demande déjà traitée.');
                if ($action === 'approve') {
                    $this->assert($vehicleId > 0, 'Choisissez le véhicule attribué.');
                    $this->assertFree($c->id, $vehicleId, $t->planned_start, $t->planned_end, $t->id);
                }
                $set = ['status' => $action === 'approve' ? 'approved' : 'refused', 'decided_by' => $u->id, 'decision_note' => $in['note'] ?? null, 'vehicle_id' => $vehicleId ?: null]
                    + (isset($in['driver_id']) ? ['driver_id' => $in['driver_id']] : []);
                $msg = $action === 'approve' ? 'Véhicule attribué : '.DB::table('fl_vehicles')->where('id', $vehicleId)->value('title').'.' : 'Demande refusée'.(! empty($in['note']) ? ' : '.$in['note'] : '.');
                AppNotification::send($c->id, [$t->requester_id, $t->driver_id], 'flotte', $action === 'approve' ? 'Sortie approuvée' : 'Sortie refusée', $t->title.' — '.$msg, 'flotte', (string) $t->id, $u->id);
                break;
            case 'checkout':
                $this->assert($own || self::isStaff($u), 'Seuls le conducteur, le demandeur ou le parc enregistrent la sortie.');
                $this->assert($t->status === 'approved', 'La sortie doit d\'abord être approuvée.');
                $v = DB::table('fl_vehicles')->where('id', $t->vehicle_id)->first();
                $this->assert((bool) $v, 'Aucun véhicule attribué.');
                $this->assert($v->status !== 'out', '« '.$v->title.' » n\'est pas encore rentré de sa sortie précédente.');
                $km = (int) ($in['km'] ?? $v->odometer);
                $this->assert($km >= $v->odometer, 'Le compteur ne peut pas être inférieur au dernier relevé ('.number_format($v->odometer, 0, ',', ' ').' km).');
                $set = ['status' => 'out', 'out_at' => $when, 'out_km' => $km, 'driver_id' => $t->driver_id ?: $u->id];
                DB::table('fl_vehicles')->where('id', $v->id)->update(['status' => 'out', 'odometer' => $km, 'updated_at' => now()]);
                break;
            case 'return':
                $this->assert($own || self::isStaff($u), 'Seuls le conducteur, le demandeur ou le parc enregistrent le retour.');
                $this->assert($t->status === 'out', 'Le véhicule n\'est pas sorti.');
                $km = (int) ($in['km'] ?? 0);
                $this->assert($km >= (int) $t->out_km, 'Le compteur au retour doit être supérieur à celui du départ ('.number_format($t->out_km, 0, ',', ' ').' km).');
                $set = ['status' => 'returned', 'back_at' => $when, 'back_km' => $km, 'fuel_level' => $in['fuel_level'] ?? null, 'report' => $in['report'] ?? null];
                DB::table('fl_vehicles')->where('id', $t->vehicle_id)->update(['status' => ($in['issue'] ?? false) ? 'maintenance' : 'available', 'odometer' => $km, 'updated_at' => now()]);
                if (! empty($in['fuel_amount'])) {
                    DB::table('fl_fuel')->insert(['company_id' => $c->id, 'created_by' => $u->id, 'vehicle_id' => $t->vehicle_id, 'trip_id' => $t->id, 'driver_id' => $t->driver_id ?: $u->id,
                        'date' => $when->toDateString(), 'amount' => (float) $in['fuel_amount'], 'liters' => $in['fuel_liters'] ?? null, 'odometer' => $km,
                        'payment' => $in['fuel_payment'] ?? 'cash', 'full_tank' => false, 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($in['issue'] ?? false) {
                    AppNotification::send($c->id, $this->approvers($c->id), 'flotte', 'Problème signalé sur un véhicule',
                        DB::table('fl_vehicles')->where('id', $t->vehicle_id)->value('title').' : '.($in['report'] ?? 'voir le compte rendu').'.', 'flotte', (string) $t->id, $u->id);
                }
                break;
            case 'cancel':
                $this->assert($own || self::isStaff($u), 'Seul le demandeur annule sa demande.');
                $this->assert(in_array($t->status, ['requested', 'approved'], true), 'Cette sortie ne peut plus être annulée.');
                $set = ['status' => 'cancelled'];
                break;
            default:
                throw new \DomainException('Action inconnue.');
        }
        DB::table('fl_trips')->where('id', $t->id)->update($set + ['updated_at' => now()]);

        return (array) DB::table('fl_trips')->where('id', $t->id)->first();
    }

    protected function assert(bool $ok, string $msg): void
    {
        if (! $ok) {
            throw new \DomainException($msg);
        }
    }

    // ------------------------------------------------------------------ alertes et tableau de bord

    public function alerts(Company $c): int
    {
        $n = 0;
        $staff = $this->approvers($c->id);
        foreach (DB::table('fl_vehicles')->where('company_id', $c->id)->where('status', '!=', 'retired')->get() as $v) {
            $row = $this->present('vehicles', (array) $v);
            if ($row['alerts'] && (! $v->alerted_on || Carbon::parse($v->alerted_on)->lte(now()->subDays(7)))) {
                AppNotification::send($c->id, $staff, 'flotte', 'Échéance véhicule : '.$v->title, $row['alerts'].'.', 'flotte', 'v'.$v->id);
                DB::table('fl_vehicles')->where('id', $v->id)->update(['alerted_on' => now()->toDateString()]);
                $n++;
            }
        }
        foreach (DB::table('fl_trips')->where('company_id', $c->id)->where('status', 'out')->where('planned_end', '<', now()->subHour())->whereNull('reminded_at')->get() as $t) {
            AppNotification::send($c->id, array_merge($staff, [$t->driver_id, $t->requester_id]), 'flotte', 'Véhicule pas encore rentré',
                $t->title.' : retour prévu le '.date('d/m à H:i', strtotime($t->planned_end)).'. Enregistrez le retour ou prévenez le parc.', 'flotte', (string) $t->id);
            DB::table('fl_trips')->where('id', $t->id)->update(['reminded_at' => now()]);
            $n++;
        }

        return $n;
    }

    public function dashboard(Company $c): array
    {
        $month = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
        $vehicles = DB::table('fl_vehicles')->where('company_id', $c->id)->where('status', '!=', 'retired')->get();
        $out = DB::table('fl_trips')->where('company_id', $c->id)->where('status', 'out')->orderBy('out_at')->get();
        $users = DB::table('users')->whereIn('id', $out->pluck('driver_id')->merge($out->pluck('requester_id'))->filter()->unique())->pluck('name', 'id');
        $titles = $vehicles->pluck('title', 'id');
        $fuel = DB::table('fl_fuel')->where('company_id', $c->id)->whereBetween('date', $month);

        return [
            'vehicles' => $vehicles->count(),
            'available' => $vehicles->where('status', 'available')->count(),
            'out' => $out->count(),
            'garage' => $vehicles->where('status', 'maintenance')->count(),
            'requests' => DB::table('fl_trips')->where('company_id', $c->id)->where('status', 'requested')->count(),
            'fuel_month' => (float) (clone $fuel)->sum('amount'),
            'liters_month' => (float) (clone $fuel)->sum('liters'),
            'costs_month' => (float) DB::table('fl_costs')->where('company_id', $c->id)->whereBetween('date', $month)->sum('amount'),
            'km_month' => (int) DB::table('fl_trips')->where('company_id', $c->id)->where('status', 'returned')->whereBetween('back_at', [$month[0], $month[1].' 23:59:59'])->sum(DB::raw('back_km - out_km')),
            'trips_month' => DB::table('fl_trips')->where('company_id', $c->id)->whereIn('status', ['out', 'returned'])->whereBetween('out_at', [$month[0], $month[1].' 23:59:59'])->count(),
            'out_now' => $out->map(fn ($t) => ['id' => $t->id, 'vehicle' => $titles[$t->vehicle_id] ?? '—', 'driver' => $users[$t->driver_id] ?? ($users[$t->requester_id] ?? '—'),
                'title' => $t->title, 'destination' => $t->destination, 'out_at' => $t->out_at, 'planned_end' => $t->planned_end, 'late' => Carbon::parse($t->planned_end)->isPast()])->values(),
            'watch' => $vehicles->map(fn ($v) => $this->present('vehicles', (array) $v))->filter(fn ($v) => $v['alerts'])->map(fn ($v) => ['id' => $v['id'], 'title' => $v['title'], 'plate' => $v['plate'], 'alerts' => $v['alerts']])->values(),
            'by_vehicle' => DB::table('fl_fuel')->where('company_id', $c->id)->whereBetween('date', $month)->groupBy('vehicle_id')->select('vehicle_id', DB::raw('SUM(amount) as amount'))->get()
                ->map(fn ($x) => ['vehicle' => $titles[$x->vehicle_id] ?? '—', 'amount' => (float) $x->amount])->sortByDesc('amount')->values(),
        ];
    }
}
