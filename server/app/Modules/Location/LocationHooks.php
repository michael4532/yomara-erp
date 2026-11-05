<?php

namespace App\Modules\Location;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ventes\Models\SalesDocument;
use App\Services\EntityWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Location de matériel : tarifs selon la durée, disponibilités, sortie et retour, retard, dégâts, caution, facture. */
class LocationHooks
{
    public const STATUSES = ['quote' => ['Devis', '#64748B'], 'reserved' => ['Réservé', '#1F6FEB'], 'out' => ['En cours', '#D97706'], 'returned' => ['Rendu', '#9333EA'],
        'invoiced' => ['Facturé', '#16A34A'], 'cancelled' => ['Annulé', '#94A3B8']];

    /** Contrats qui bloquent le matériel. */
    public const HOLDING = ['reserved', 'out'];

    public const DEPOSIT_STATUSES = ['none' => ['Pas de caution', '#94A3B8'], 'received' => ['Encaissée', '#1F6FEB'], 'refunded' => ['Restituée', '#16A34A'],
        'partial' => ['Restituée en partie', '#D97706'], 'kept' => ['Conservée', '#DC2626']];

    public const DEPOSIT_METHODS = ['cash' => 'Espèces', 'mobile_money' => 'Mobile Money', 'cheque' => 'Chèque', 'transfer' => 'Virement', 'card' => 'Carte'];

    public const PRICING = ['day' => 'Par jour', 'week' => 'Par semaine', 'month' => 'Par mois', 'flat' => 'Forfait'];

    public const ITEM_STATUSES = ['available' => ['En service', '#16A34A'], 'maintenance' => ['En réparation', '#D97706'], 'retired' => ['Retiré', '#94A3B8']];

    /** Nombre de jours facturés : toute journée entamée compte (une heure de tolérance). */
    public static function days(string $start, string $end): int
    {
        $h = (Carbon::parse($start)->diffInMinutes(Carbon::parse($end), false) - 60) / 60;

        return max(1, (int) ceil($h / 24));
    }

    public static function periods(string $pricing, int $days): int
    {
        return match ($pricing) {
            'week' => (int) ceil($days / 7),
            'month' => (int) ceil($days / 30),
            'flat' => 1,
            default => $days,
        };
    }

    public static function rateFor(object|array $item, string $pricing): float
    {
        $i = (array) $item;

        return (float) match ($pricing) {
            'week' => $i['rate_week'] ?: $i['rate_day'] * 7,
            'month' => $i['rate_month'] ?: $i['rate_day'] * 30,
            default => $i['rate_day'],
        };
    }

    /** Quantité déjà prise sur la période (réservations et sorties ; une sortie non rendue bloque jusqu'au retour). */
    public static function used(int $companyId, int $itemId, string $start, string $end, ?int $exceptRental = null): int
    {
        return (int) DB::table('lo_lines')->join('lo_rentals', 'lo_rentals.id', '=', 'lo_lines.rental_id')
            ->where('lo_rentals.company_id', $companyId)->where('lo_lines.item_id', $itemId)->whereIn('lo_rentals.status', self::HOLDING)
            ->when($exceptRental, fn ($q) => $q->where('lo_rentals.id', '!=', $exceptRental))
            ->where('lo_rentals.start_at', '<', $end)
            ->where(fn ($q) => $q->where('lo_rentals.end_at', '>', $start)->orWhere('lo_rentals.status', 'out'))
            ->sum('lo_lines.quantity');
    }

    /** Vérifie que tout le matériel du contrat est libre sur la période. */
    public function checkAvailability(Company $c, array $rental, ?array $extraLine = null): void
    {
        $lines = DB::table('lo_lines')->where('rental_id', $rental['id'] ?? 0)->when($extraLine['id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->get()->map(fn ($l) => (array) $l)->all();
        if ($extraLine) {
            $lines[] = $extraLine;
        }
        $need = [];
        foreach ($lines as $l) {
            $need[$l['item_id']] = ($need[$l['item_id']] ?? 0) + (int) $l['quantity'];
        }
        foreach ($need as $itemId => $qty) {
            $item = DB::table('lo_items')->where('company_id', $c->id)->where('id', $itemId)->first() ?: throw new \DomainException('Matériel introuvable.');
            if ($item->status !== 'available') {
                throw new \DomainException('« '.$item->title.' » est '.mb_strtolower(self::ITEM_STATUSES[$item->status][0]).' : il ne peut pas être loué.');
            }
            $free = (int) $item->quantity - self::used($c->id, (int) $itemId, $rental['start_at'], $rental['end_at'], $rental['id'] ?? null);
            if ($qty > $free) {
                throw new \DomainException('« '.$item->title.' » : '.max(0, $free).' disponible(s) seulement du '.date('d/m H:i', strtotime($rental['start_at'])).' au '.date('d/m H:i', strtotime($rental['end_at'])).' (demandé : '.$qty.').');
            }
        }
    }

    public function present(string $res, array $row): array
    {
        if ($res === 'rentals') {
            $d = self::days($row['start_at'], $row['end_at']);
            $row['days'] = $d;
            $row['period'] = $d.' j';
            $row['alert'] = null;
            $now = now();
            if ($row['status'] === 'out') {
                $end = Carbon::parse($row['end_at']);
                $row['alert'] = $end->isPast() ? 'Retour en retard de '.max(1, (int) ceil($end->diffInHours($now) / 24)).' j' : ($end->isToday() ? 'Retour aujourd\'hui à '.$end->format('H:i') : ($end->isTomorrow() ? 'Retour demain' : null));
            } elseif ($row['status'] === 'reserved') {
                $st = Carbon::parse($row['start_at']);
                $row['alert'] = $st->isPast() ? 'Départ prévu dépassé' : ($st->isToday() ? 'Départ aujourd\'hui à '.$st->format('H:i') : ($st->isTomorrow() ? 'Départ demain : préparer le matériel' : null));
            } elseif ($row['status'] === 'returned' && $row['deposit_status'] === 'received') {
                $row['alert'] = 'Caution à restituer';
            }
            $row['customer'] = $row['partner_id'] ? (DB::table('partners')->where('id', $row['partner_id'])->value('name') ?? $row['customer_name']) : $row['customer_name'];
            $row['invoice'] = $row['invoice_id'] ? (DB::table('trade_documents')->where('id', $row['invoice_id'])->value('number') ?: 'Brouillon') : null;
        } elseif ($res === 'items') {
            $now = now()->toDateTimeString();
            $used = self::used($row['company_id'], $row['id'], $now, now()->addMinute()->toDateTimeString());
            $row['available_now'] = $row['status'] === 'available' ? max(0, (int) $row['quantity'] - $used) : 0;
            $row['availability'] = $row['status'] !== 'available' ? self::ITEM_STATUSES[$row['status']][0] : ((int) $row['quantity'] > 1 ? $row['available_now'].' / '.$row['quantity'] : ($row['available_now'] ? 'Disponible' : 'Loué'));
        }

        return $row;
    }

    protected function rental(int $id): array
    {
        return (array) (DB::table('lo_rentals')->where('company_id', app('tenant')->id)->where('id', $id)->first() ?: throw new \DomainException('Contrat introuvable.'));
    }

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        $c = app('tenant');
        if ($res === 'rentals') {
            if ($old && in_array($old['status'], ['invoiced', 'cancelled'], true)) {
                throw new \DomainException('Ce contrat est '.mb_strtolower(self::STATUSES[$old['status']][0]).' : il ne peut plus être modifié.');
            }
            $start = $data['start_at'] ?? $old['start_at'];
            $end = $data['end_at'] ?? $old['end_at'];
            if (strtotime($end) <= strtotime($start)) {
                throw new \DomainException('Le retour prévu doit être après le départ.');
            }
            if (! $old) {
                $data['status'] = 'quote';
                $data['number'] = 'LOC-'.now()->format('Y').'-'.str_pad((string) (DB::table('lo_rentals')->where('company_id', $c->id)->whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT);
            } elseif (in_array($old['status'], self::HOLDING, true) && ($start !== $old['start_at'] || $end !== $old['end_at'])) {
                $this->checkAvailability($c, ['id' => $old['id'], 'start_at' => $start, 'end_at' => $end]);
            }
            if (empty($data['partner_id'] ?? $old['partner_id'] ?? null) && empty($data['customer_name'] ?? $old['customer_name'] ?? null)) {
                throw new \DomainException('Indiquez le client.');
            }
        } elseif ($res === 'lines') {
            $r = $this->rental((int) ($data['rental_id'] ?? $old['rental_id']));
            $structural = array_intersect_key($data, array_flip(['item_id', 'quantity', 'pricing', 'unit_price', 'rental_id']));
            $changed = ! $old || collect($structural)->contains(fn ($v, $k) => (string) $v !== (string) ($old[$k] ?? ''));
            if ($changed && ! in_array($r['status'], ['quote', 'reserved'], true)) {
                throw new \DomainException('Le matériel ne se modifie plus une fois le contrat '.mb_strtolower(self::STATUSES[$r['status']][0]).' (seuls l\'état au retour et les dégâts restent modifiables).');
            }
            $line = array_merge($old ?? ['quantity' => 1, 'pricing' => 'day', 'unit_price' => null], $data);
            if ((int) $line['quantity'] < 1) {
                throw new \DomainException('Quantité invalide.');
            }
            $item = DB::table('lo_items')->where('company_id', $c->id)->where('id', $line['item_id'])->first() ?: throw new \DomainException('Matériel introuvable.');
            if ($changed && in_array($r['status'], self::HOLDING, true)) {
                $this->checkAvailability($c, $r, ['id' => $old['id'] ?? null, 'item_id' => (int) $line['item_id'], 'quantity' => (int) $line['quantity']]);
            }
            if (! array_key_exists('unit_price', $data) || $data['unit_price'] === null || $data['unit_price'] === '' || (isset($data['pricing']) && $old && $data['pricing'] !== $old['pricing'] && ! isset($data['unit_price']))) {
                $data['unit_price'] = self::rateFor($item, $line['pricing']);
                $line['unit_price'] = $data['unit_price'];
            }
            $data['amount'] = round((int) $line['quantity'] * (float) $line['unit_price'] * self::periods($line['pricing'], self::days($r['start_at'], $r['end_at'])), 2);
        } elseif ($res === 'items' && isset($data['quantity']) && (int) $data['quantity'] < 1) {
            throw new \DomainException('Quantité invalide.');
        }

        return $data;
    }

    public function saved(string $res, array $row, ?array $old, User $u): void
    {
        if ($res === 'lines') {
            $this->recompute((int) $row['rental_id']);
        } elseif ($res === 'rentals' && $old && ($row['start_at'] !== $old['start_at'] || $row['end_at'] !== $old['end_at'] || $row['discount_percent'] != $old['discount_percent'] || $row['delivery_fee'] != $old['delivery_fee'])) {
            $this->recompute((int) $row['id'], true);
        } elseif ($res === 'rentals') {
            $this->recompute((int) $row['id']);
        }
    }

    public function deleting(string $res, array $row, User $u): void
    {
        if ($res === 'rentals') {
            if (in_array($row['status'], ['out', 'returned', 'invoiced'], true)) {
                throw new \DomainException('Un contrat sorti, rendu ou facturé ne peut pas être supprimé : annulez-le ou facturez-le.');
            }
            DB::table('lo_lines')->where('rental_id', $row['id'])->delete();
        } elseif ($res === 'lines') {
            $r = $this->rental((int) $row['rental_id']);
            if (! in_array($r['status'], ['quote', 'reserved'], true)) {
                throw new \DomainException('Le matériel ne se retire plus une fois le contrat sorti.');
            }
            DB::afterCommit(fn () => $this->recompute((int) $row['rental_id']));
        } elseif ($res === 'items' && DB::table('lo_lines')->where('item_id', $row['id'])->exists()) {
            throw new \DomainException('Ce matériel figure dans des contrats : passez-le à « Retiré » plutôt que de le supprimer.');
        }
    }

    /** Recalcule les lignes (si la durée a changé) et le total du contrat. */
    public function recompute(int $rentalId, bool $reprice = false): void
    {
        $r = (array) DB::table('lo_rentals')->find($rentalId);
        if (! $r) {
            return;
        }
        $days = self::days($r['start_at'], $r['end_at']);
        if ($reprice) {
            foreach (DB::table('lo_lines')->where('rental_id', $rentalId)->get() as $l) {
                DB::table('lo_lines')->where('id', $l->id)->update(['amount' => round($l->quantity * (float) $l->unit_price * self::periods($l->pricing, $days), 2)]);
            }
        }
        $lines = (float) DB::table('lo_lines')->where('rental_id', $rentalId)->sum('amount');
        $total = $lines * (1 - (float) $r['discount_percent'] / 100) + (float) $r['delivery_fee'] + (float) $r['late_fee'] + (float) $r['damage_fee'];
        DB::table('lo_rentals')->where('id', $rentalId)->update(['total' => round($total, 2)]);
    }

    protected function defaultDeposit(array $r): float
    {
        return (float) DB::table('lo_lines')->join('lo_items', 'lo_items.id', '=', 'lo_lines.item_id')->where('lo_lines.rental_id', $r['id'])->sum(DB::raw('lo_lines.quantity * coalesce(lo_items.deposit, 0)'));
    }

    /** Réserver, sortir, rendre, facturer, annuler. */
    public function action(Company $c, User $u, int $id, string $action, array $in): array
    {
        $r = (array) (DB::table('lo_rentals')->where('company_id', $c->id)->where('id', $id)->lockForUpdate()->first() ?: throw new \DomainException('Contrat introuvable.'));
        $at = isset($in['at']) ? Carbon::parse($in['at']) : now();
        if ($at->isFuture()) {
            $at = now();
        }
        $set = [];
        $hasLines = DB::table('lo_lines')->where('rental_id', $id)->exists();
        switch ($action) {
            case 'reserve':
            case 'checkout':
                if (! in_array($r['status'], $action === 'reserve' ? ['quote'] : ['quote', 'reserved'], true)) {
                    throw new \DomainException('Ce contrat est déjà '.mb_strtolower(self::STATUSES[$r['status']][0]).'.');
                }
                if (! $hasLines) {
                    throw new \DomainException('Ajoutez d\'abord le matériel loué.');
                }
                $this->checkAvailability($c, $r);
                $set['status'] = $action === 'reserve' ? 'reserved' : 'out';
                if ($r['deposit_amount'] === null) {
                    $set['deposit_amount'] = $this->defaultDeposit($r) ?: null;
                }
                if (! empty($in['deposit_received'])) {
                    $set['deposit_status'] = 'received';
                    $set['deposit_method'] = $in['deposit_method'] ?? $r['deposit_method'];
                }
                if ($action === 'checkout') {
                    $set['out_at'] = $at;
                    foreach ((array) ($in['conditions'] ?? []) as $lineId => $cond) {
                        DB::table('lo_lines')->where('rental_id', $id)->where('id', $lineId)->update(['condition_out' => Str::limit((string) $cond, 200, '')]);
                    }
                }
                break;
            case 'return':
                if ($r['status'] !== 'out') {
                    throw new \DomainException('Seul un contrat en cours peut être rendu.');
                }
                $set = ['status' => 'returned', 'returned_at' => $at];
                // retard : chaque journée entamée au-delà d'une heure de tolérance, au tarif journalier
                $late = $at->gt(Carbon::parse($r['end_at'])->addHour()) ? self::days($r['end_at'], $at->toDateTimeString()) : 0;
                $set['late_fee'] = array_key_exists('late_fee', $in) && $in['late_fee'] !== null ? (float) $in['late_fee']
                    : ($late ? (float) DB::table('lo_lines')->join('lo_items', 'lo_items.id', '=', 'lo_lines.item_id')->where('lo_lines.rental_id', $id)->sum(DB::raw('lo_lines.quantity * lo_items.rate_day')) * $late : null);
                foreach ((array) ($in['lines'] ?? []) as $lineId => $l) {
                    DB::table('lo_lines')->where('rental_id', $id)->where('id', $lineId)->update(array_filter([
                        'condition_in' => isset($l['condition_in']) ? Str::limit((string) $l['condition_in'], 200, '') : null,
                        'damage_fee' => isset($l['damage_fee']) ? (float) $l['damage_fee'] : null,
                    ], fn ($v) => $v !== null));
                }
                $damages = (float) DB::table('lo_lines')->where('rental_id', $id)->sum('damage_fee');
                $set['damage_fee'] = array_key_exists('damage_fee', $in) && $in['damage_fee'] !== null ? (float) $in['damage_fee'] : ($damages ?: null);
                if ($r['deposit_status'] === 'received') {
                    $kept = (float) ($in['deposit_kept'] ?? 0);
                    $set['deposit_kept'] = $kept ?: null;
                    $set['deposit_status'] = $kept <= 0 ? 'refunded' : ($kept >= (float) $r['deposit_amount'] ? 'kept' : 'partial');
                }
                break;
            case 'invoice':
                if (! in_array($r['status'], ['reserved', 'out', 'returned'], true) || $r['invoice_id']) {
                    throw new \DomainException($r['invoice_id'] ? 'Ce contrat est déjà facturé.' : 'Réservez le contrat avant de le facturer.');
                }
                $set['invoice_id'] = $this->invoice($c, $r);
                if ($r['status'] === 'returned') {
                    $set['status'] = 'invoiced';
                }
                break;
            case 'cancel':
                if (! in_array($r['status'], ['quote', 'reserved'], true)) {
                    throw new \DomainException('Seul un devis ou une réservation peut être annulé.');
                }
                $set['status'] = 'cancelled';
                break;
        }
        DB::table('lo_rentals')->where('id', $id)->update($set + ['updated_at' => now()]);
        $this->recompute($id);
        // facturé après le retour : le contrat est clos
        $row = (array) DB::table('lo_rentals')->find($id);
        if ($action === 'return' && $row['invoice_id']) {
            DB::table('lo_rentals')->where('id', $id)->update(['status' => 'invoiced']);
            $row['status'] = 'invoiced';
        }

        return $row;
    }

    /** Facture brouillon : matériel × durée, transport, retard, dégâts ; la caution n'est pas facturée. */
    protected function invoice(Company $c, array $r): string
    {
        if (! $c->hasModule('ventes')) {
            throw new \DomainException('Le module Ventes & Facturation n\'est pas activé.');
        }
        if (! $r['partner_id']) {
            throw new \DomainException('Pour facturer, choisissez le client dans vos contacts (champ « Client »).');
        }
        $days = self::days($r['start_at'], $r['end_at']);
        $tax = (float) $r['tax_rate'];
        $disc = (float) $r['discount_percent'];
        $from = date('d/m/Y', strtotime($r['start_at'])).' au '.date('d/m/Y', strtotime($r['end_at']));
        $lines = [];
        foreach (DB::table('lo_lines')->join('lo_items', 'lo_items.id', '=', 'lo_lines.item_id')->where('lo_lines.rental_id', $r['id'])->get(['lo_lines.*', 'lo_items.title']) as $l) {
            $p = self::periods($l->pricing, $days);
            $unit = ['day' => 'jour(s)', 'week' => 'semaine(s)', 'month' => 'mois', 'flat' => 'forfait'][$l->pricing] ?? '';
            $lines[] = ['id' => (string) Str::uuid(), 'description' => Str::limit('Location '.$l->title.' — '.($l->pricing === 'flat' ? 'forfait' : $p.' '.$unit).' du '.$from, 250, ''),
                'quantity' => (int) $l->quantity, 'unit_price' => round((float) $l->unit_price * $p, 2), 'tax_rate' => $tax, 'discount_percent' => $disc];
        }
        foreach ([['delivery_fee', 'Transport : livraison et reprise'], ['late_fee', 'Retard de restitution'], ['damage_fee', 'Dégâts et pertes constatés au retour']] as [$k, $label]) {
            if ((float) $r[$k] > 0) {
                $lines[] = ['id' => (string) Str::uuid(), 'description' => $label, 'quantity' => 1, 'unit_price' => (float) $r[$k], 'tax_rate' => $tax, 'discount_percent' => 0];
            }
        }
        $id = (string) Str::uuid();
        SalesDocument::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(SalesDocument::class, $id, [
                'type' => 'invoice', 'partner_id' => $r['partner_id'], 'date' => now()->toDateString(), 'reference' => $r['number'], 'status' => 'draft', 'lines' => $lines,
                'notes' => 'Contrat de location '.$r['number'].' — du '.$from.($r['deposit_amount'] ? "\nCaution : ".number_format((float) $r['deposit_amount'], 0, ',', ' ').' (non facturée).' : ''),
            ]);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Facture refusée : '.($res['message'] ?? ''));
        }

        return $id;
    }

    protected function managers(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('is_active', true)->get()->filter(fn ($x) => $x->hasPermission('location.manage'))->pluck('id')->all();
    }

    /** Rappels : départs du lendemain à préparer, retours en retard (une fois par jour). */
    public function alerts(Company $c): int
    {
        $n = 0;
        $to = fn ($owner) => $owner ? [$owner] : $this->managers($c->id);
        $fresh = fn ($r) => ! $r->reminded_at || Carbon::parse($r->reminded_at)->lt(now()->subHours(20));
        foreach (DB::table('lo_rentals')->where('company_id', $c->id)->where('status', 'reserved')->whereBetween('start_at', [now(), now()->addDay()])->get() as $r) {
            if (! $fresh($r)) {
                continue;
            }
            AppNotification::send($c->id, $to($r->owner_id), 'location', 'Départ en location à préparer', $r->number.' — '.$this->customer($r).' : départ le '.date('d/m à H:i', strtotime($r->start_at)).'.', 'location', (string) $r->id);
            DB::table('lo_rentals')->where('id', $r->id)->update(['reminded_at' => now()]);
            $n++;
        }
        foreach (DB::table('lo_rentals')->where('company_id', $c->id)->where('status', 'out')->where('end_at', '<', now()->subHour())->get() as $r) {
            if (! $fresh($r)) {
                continue;
            }
            AppNotification::send($c->id, $to($r->owner_id), 'location', 'Matériel loué pas rendu', $r->number.' — '.$this->customer($r).($r->phone ? ' ('.$r->phone.')' : '').' : retour prévu le '.date('d/m à H:i', strtotime($r->end_at)).'.', 'location', (string) $r->id);
            DB::table('lo_rentals')->where('id', $r->id)->update(['reminded_at' => now()]);
            $n++;
        }

        return $n;
    }

    protected function customer(object $r): string
    {
        return $r->partner_id ? (string) (DB::table('partners')->where('id', $r->partner_id)->value('name') ?? $r->customer_name) : (string) $r->customer_name;
    }

    public function dashboard(Company $c): array
    {
        $items = DB::table('lo_items')->where('company_id', $c->id)->where('status', 'available');
        $units = (int) (clone $items)->sum('quantity');
        $out = (int) DB::table('lo_lines')->join('lo_rentals', 'lo_rentals.id', '=', 'lo_lines.rental_id')->where('lo_rentals.company_id', $c->id)->where('lo_rentals.status', 'out')->sum('lo_lines.quantity');
        $r = DB::table('lo_rentals')->where('company_id', $c->id);
        $month = now()->startOfMonth();

        return [
            'items' => (clone $items)->count(),
            'units' => $units,
            'units_out' => $out,
            'utilization' => $units ? round($out / $units * 100) : 0,
            'out' => (clone $r)->where('status', 'out')->count(),
            'late' => (clone $r)->where('status', 'out')->where('end_at', '<', now()->subHour())->count(),
            'departures' => (clone $r)->where('status', 'reserved')->whereBetween('start_at', [now()->startOfDay(), now()->addDay()->endOfDay()])->count(),
            'returns_today' => (clone $r)->where('status', 'out')->whereBetween('end_at', [now()->startOfDay(), now()->endOfDay()])->count(),
            'revenue_month' => (float) (clone $r)->whereIn('status', ['returned', 'invoiced'])->where('returned_at', '>=', $month)->sum('total'),
            'deposits_held' => (float) (clone $r)->where('deposit_status', 'received')->sum('deposit_amount'),
            'quotes' => (clone $r)->where('status', 'quote')->count(),
        ];
    }
}
