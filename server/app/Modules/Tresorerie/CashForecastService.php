<?php

namespace App\Modules\Tresorerie;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Tresorerie\Models\Forecast;
use App\Modules\Tresorerie\Models\TreasuryAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prévision de trésorerie sur N semaines (13 par défaut), en trois scénarios.
 *
 * Sources : factures clients (date d'encaissement ajustée au retard moyen de chaque client), factures fournisseurs,
 * commandes clients et bons de commande pas encore facturés, abonnements, paie (nets et charges), notes de frais
 * approuvées, opportunités du CRM (scénario optimiste), prévisions saisies.
 *  - optimiste : chaque client paie à l'échéance, opportunités pondérées par leur probabilité ;
 *  - réaliste : retard moyen constaté de chaque client ;
 *  - pessimiste : retard + N jours, créances anciennes douteuses écartées, commandes clients réduites.
 */
class CashForecastService
{
    public const SCENARIOS = ['optimistic' => 'Optimiste', 'realistic' => 'Réaliste', 'pessimistic' => 'Pessimiste'];

    public const CATEGORIES = [
        'clients' => 'Factures clients', 'commandes' => 'Commandes clients à facturer', 'abonnements' => 'Abonnements', 'pipeline' => 'Opportunités (CRM)',
        'fournisseurs' => 'Factures fournisseurs', 'achats' => 'Bons de commande à recevoir', 'salaires' => 'Salaires nets', 'charges' => 'Charges sociales et ITS',
        'notes_frais' => 'Notes de frais', 'tva' => 'TVA à reverser', 'previsions' => 'Prévisions saisies',
    ];

    public const DEFAULTS = [
        'threshold' => 0, 'weeks' => 13, 'pessimistic_extra_days' => 30, 'doubtful_days' => 90, 'orders_pessimistic' => 70,
        'invoice_lag_days' => 7, 'supplier_terms_days' => 30, 'include_pipeline' => true, 'vat_enabled' => true, 'vat_day' => 15,
    ];

    /** Dernier calcul (lignes et contexte), pour les relances prioritaires. */
    public array $last = [];

    public function settings(Company $c): array
    {
        $s = array_replace(self::DEFAULTS, array_intersect_key((array) data_get($c->settings, 'tresorerie_forecast', []), self::DEFAULTS));
        $s['threshold'] = (float) $s['threshold'];
        $s['include_pipeline'] = (bool) $s['include_pipeline'];
        $s['vat_enabled'] = (bool) $s['vat_enabled'];
        foreach (['vat_day', 'weeks', 'pessimistic_extra_days', 'doubtful_days', 'orders_pessimistic', 'invoice_lag_days', 'supplier_terms_days'] as $k) {
            $s[$k] = (int) $s[$k];
        }

        return $s;
    }

    public function saveSettings(Company $c, array $v): array
    {
        $all = (array) ($c->settings ?? []);
        $all['tresorerie_forecast'] = array_intersect_key(array_replace($this->settings($c), $v), self::DEFAULTS);
        unset($all['_forecast_alert']);
        $c->forceFill(['settings' => $all])->save();

        return $this->settings($c);
    }

    /** Retard moyen de paiement par client (jours après l'échéance), sur les factures soldées des 12 derniers mois. */
    public function customerDelays(): array
    {
        $cid = app('tenant')->id;
        $docs = DB::table('trade_documents')->where('company_id', $cid)->where('direction', 'sale')->where('type', 'invoice')->where('status', 'validated')
            ->where('payment_status', 'paid')->whereNull('deleted_at')->where('date', '>=', now()->subYear()->toDateString())->get(['id', 'partner_id', 'date', 'due_date']);
        if ($docs->isEmpty()) {
            return [];
        }
        $last = DB::table('payments')->where('company_id', $cid)->where('direction', 'sale')->whereIn('document_id', $docs->pluck('id'))->whereNull('deleted_at')
            ->groupBy('document_id')->selectRaw('document_id, max(date) as d')->pluck('d', 'document_id');
        $by = [];
        foreach ($docs as $d) {
            if (! $d->partner_id || ! isset($last[$d->id])) {
                continue;
            }
            $by[$d->partner_id][] = max(-30, min(180, Carbon::parse($d->due_date ?? $d->date)->diffInDays(Carbon::parse($last[$d->id]), false)));
        }

        return array_map(fn ($v) => (int) round(max(0, array_sum($v) / count($v))), $by);
    }

    public function compute(?int $weeks = null): array
    {
        $c = app('tenant');
        $set = $this->settings($c);
        $weeks = max(4, min(26, $weeks ?: $set['weeks']));
        $start = now()->startOfWeek();
        $end = $start->copy()->addWeeks($weeks);
        $today = now()->startOfDay();
        $terms = (int) $c->setting('payment_terms_days', 30);
        $delays = $this->customerDelays();
        $later = fn (Carbon $d) => $d->lt($today) ? $today->copy() : $d->copy(); // jamais avant aujourd'hui (retards → semaine en cours)
        $items = [];
        // $dates : date par scénario (string) ; $w : coefficient par scénario
        $add = function (string $cat, string $label, float $amount, array $dates, array $w = [1, 1, 1], ?string $ref = null, array $meta = []) use (&$items) {
            if (abs($amount) < 0.5) {
                return;
            }
            $items[] = compact('cat', 'label', 'amount', 'dates', 'w', 'ref') + $meta;
        };
        $inflow = function (Carbon $due, ?string $partner) use ($delays, $set, $later) {
            $delay = $delays[$partner] ?? 0;

            return [
                $later($due)->toDateString(),
                $later($due->copy()->addDays($delay))->toDateString(),
                $later($due->copy()->addDays($delay + $set['pessimistic_extra_days']))->toDateString(),
            ];
        };
        $docs = fn () => DB::table('trade_documents')->where('company_id', $c->id)->whereNull('deleted_at');
        // Échéances restant dues d'un document (échéancier des conditions de paiement, sinon échéance unique)
        $unpaid = function ($d) {
            $sch = $d->schedule ? (array) json_decode($d->schedule, true) : [];
            if (! $sch) {
                $sch = [['date' => $d->due_date ?? $d->date, 'amount' => (float) $d->total]];
            }
            $paid = (float) $d->amount_paid;
            $out = [];
            foreach ($sch as $i) {
                $left = round((float) $i['amount'] - $paid, 2);
                $paid = max(0, $paid - (float) $i['amount']);
                if ($left > 0.009) {
                    $out[] = ['date' => Carbon::parse($i['date']), 'amount' => min($left, (float) $i['amount'])];
                }
            }

            return $out;
        };
        // Échéances futures d'un montant selon les conditions de paiement (document, sinon tiers, sinon société)
        $termCache = [];
        $byTerm = function (?string $termId, ?string $partner, string $direction, Carbon $from, float $amount, int $fallbackDays) use (&$termCache) {
            $key = ($termId ?? '').'|'.($partner ?? '').'|'.$direction;
            $term = $termCache[$key] ??= ($termId ? \App\Modules\Core\Models\PaymentTerm::withTrashed()->find($termId) : null)
                ?? \App\Modules\Core\Models\TradeDocument::defaultTermFor($partner, $direction) ?? false;
            if (! $term) {
                return [['date' => $from->copy()->addDays($fallbackDays), 'amount' => $amount]];
            }

            return array_map(fn ($i) => ['date' => Carbon::parse($i['date']), 'amount' => $i['amount']], $term->schedule($from, $amount));
        };
        $part = fn (array $list, int $k) => count($list) > 1 ? ' — échéance '.($k + 1).'/'.count($list) : '';

        // 1. Factures clients restant dues
        if ($c->hasModule('ventes')) {
            foreach ($docs()->where('direction', 'sale')->where('type', 'invoice')->where('status', 'validated')->whereIn('payment_status', ['unpaid', 'partial'])->get() as $d) {
                foreach ($unpaid($d) as $k => $i) {
                    $doubtful = $i['date']->diffInDays($today, false) > $set['doubtful_days'];
                    $add('clients', 'Facture '.$d->number.' — '.$d->partner_name.($doubtful ? ' (créance ancienne)' : ''), $i['amount'], $inflow($i['date'], $d->partner_id), [1, 1, $doubtful ? 0 : 1], $d->id,
                        ['partner' => $d->partner_id, 'due' => $i['date']->toDateString(), 'overdue' => $i['date']->lt($today)]);
                }
            }
            // 2. Commandes clients validées pas encore facturées
            foreach ($docs()->where('direction', 'sale')->where('type', 'order')->where('status', 'validated')->where('date', '>=', now()->subMonths(6)->toDateString())->get() as $o) {
                $invoiced = (float) $docs()->where('direction', 'sale')->where('type', 'invoice')->where('source_id', $o->id)->where('status', '!=', 'cancelled')->sum('total');
                $left = (float) $o->total - $invoiced;
                if ($left > 0) {
                    $inv = $later(Carbon::parse($o->date)->addDays($set['invoice_lag_days']));
                    $list = $byTerm($o->payment_term_id, $o->partner_id, 'sale', $inv, $left, $terms);
                    foreach ($list as $k => $i) {
                        $add('commandes', 'Commande '.$o->number.' — '.$o->partner_name.$part($list, $k), $i['amount'], $inflow($i['date'], $o->partner_id), [1, 1, $set['orders_pessimistic'] / 100], $o->id);
                    }
                }
            }
        }
        // 3. Abonnements : prochaines factures de la période
        if ($c->hasModule('abonnements') && class_exists(\App\Modules\Abonnements\Models\Subscription::class)) {
            $svc = app(\App\Modules\Abonnements\SubscriptionService::class);
            foreach (\App\Modules\Abonnements\Models\Subscription::where('status', 'active')->get() as $s) {
                $amount = collect($s->lines ?? [])->sum(fn ($l) => (float) $l['quantity'] * (float) $l['unit_price'] * (1 - (float) ($l['discount_percent'] ?? 0) / 100) * (1 + (float) ($l['tax_rate'] ?? 0) / 100));
                $date = $s->next_date->copy();
                $n = (int) $s->generated_count;
                $guard = 0;
                while ($date->lt($end) && $guard++ < 60 && (! $s->end_date || $date->lte($s->end_date)) && (! $s->max_invoices || $n < $s->max_invoices)) {
                    foreach ($byTerm(null, $s->partner_id, 'sale', $date->copy(), $amount, $terms) as $i) {
                        $add('abonnements', 'Abonnement '.$s->name.' ('.$date->format('d/m').')', $i['amount'], $inflow($i['date'], $s->partner_id), [1, 1, 1], $s->id);
                    }
                    $date = $svc->advance($s, $date);
                    $n++;
                }
            }
        }
        // 4. Opportunités du CRM : seulement en optimiste, pondérées par la probabilité
        if ($set['include_pipeline'] && $c->hasModule('crm')) {
            $open = DB::table('crm_stages')->where('company_id', $c->id)->pluck('probability', 'id');
            foreach (DB::table('crm_leads')->where('company_id', $c->id)->where('status', 'open')->whereNull('deleted_at')->where('expected_revenue', '>', 0)
                ->whereNotNull('expected_close_date')->where('expected_close_date', '<', $end->toDateString())->get() as $l) {
                $prob = (int) ($l->probability ?? $open[$l->stage_id] ?? 0);
                $inv = $later(Carbon::parse($l->expected_close_date))->addDays($set['invoice_lag_days']);
                foreach ($byTerm(null, $l->partner_id ?? null, 'sale', $inv, (float) $l->expected_revenue * $prob / 100, $terms) as $i) {
                    $add('pipeline', 'Opportunité « '.$l->title.' » ('.$prob.' %)', $i['amount'], array_fill(0, 3, $later($i['date'])->toDateString()), [1, 0, 0], $l->id);
                }
            }
        }
        // 5. Factures fournisseurs restant dues (payées à l'échéance dans tous les scénarios)
        if ($c->hasModule('achats')) {
            foreach ($docs()->where('direction', 'purchase')->where('type', 'supplier_invoice')->where('status', 'validated')->whereIn('payment_status', ['unpaid', 'partial'])->get() as $d) {
                $list = $unpaid($d);
                foreach ($list as $k => $i) {
                    $add('fournisseurs', 'Fournisseur '.$d->number.' — '.$d->partner_name.$part($list, $k), -$i['amount'], array_fill(0, 3, $later($i['date'])->toDateString()), [1, 1, 1], $d->id);
                }
            }
            // 6. Bons de commande validés pas encore facturés : livraison prévue + délai fournisseur
            $acks = Schema::hasTable('sp_order_acks') ? DB::table('sp_order_acks')->where('company_id', $c->id)->where('status', 'accepted')->pluck('delivery_date', 'order_id') : collect();
            foreach ($docs()->where('direction', 'purchase')->where('type', 'purchase_order')->where('status', 'validated')->where('date', '>=', now()->subMonths(6)->toDateString())->get() as $o) {
                $invoiced = (float) $docs()->where('direction', 'purchase')->where('type', 'supplier_invoice')->where('source_id', $o->id)->where('status', '!=', 'cancelled')->sum('total');
                $left = (float) $o->total - $invoiced;
                if ($left > 0) {
                    // Livraison : confirmée par le fournisseur, sinon date prévue du bon, sinon +14 jours
                    $delivery = isset($acks[$o->id]) ? Carbon::parse($acks[$o->id]) : ($o->due_date ? Carbon::parse($o->due_date) : Carbon::parse($o->date)->addDays(14));
                    $list = $byTerm($o->payment_term_id, $o->partner_id, 'purchase', $later($delivery), $left, $set['supplier_terms_days']);
                    foreach ($list as $k => $i) {
                        $add('achats', 'Commande '.$o->number.' — '.$o->partner_name.$part($list, $k), -$i['amount'], array_fill(0, 3, $later($i['date'])->toDateString()), [1, 1, 1], $o->id);
                    }
                }
            }
        }
        // 7. Paie : nets le jour de paie habituel, charges sociales et ITS le 15 du mois suivant
        if ($c->hasModule('paie') && Schema::hasTable('paie_runs')) {
            $runs = DB::table('paie_runs')->where('company_id', $c->id)->whereIn('status', ['validated', 'paid'])->orderByDesc('period')->get()->keyBy('period');
            $last = $runs->first();
            if ($last) {
                $sum = fn ($run) => DB::table('paie_payslips')->where('run_id', $run->id)->selectRaw('coalesce(sum(net),0) as net, coalesce(sum(cost),0) as cost')->first();
                $ref = $sum($last);
                $payDay = $last->payment_date ? (int) Carbon::parse($last->payment_date)->day : 31;
                for ($m = $start->copy()->subMonth()->startOfMonth(); $m->lt($end); $m->addMonth()) {
                    $period = $m->format('Y-m');
                    $run = $runs[$period] ?? null;
                    $vals = $run ? $sum($run) : $ref;
                    $label = $m->locale('fr')->isoFormat('MMMM YYYY');
                    if (! $run || $run->status !== 'paid') {
                        $day = $run && $run->payment_date ? Carbon::parse($run->payment_date) : $m->copy()->day(min($payDay, $m->daysInMonth));
                        if ($day->gte($start) || $run) {
                            $add('salaires', 'Salaires '.$label.($run ? '' : ' (estimés)'), -(float) $vals->net, array_fill(0, 3, $later($day)->toDateString()));
                        }
                    }
                    $chargesDay = $m->copy()->addMonthNoOverflow()->day(15);
                    if ($chargesDay->gte($today) && (float) $vals->cost > (float) $vals->net) {
                        $add('charges', 'Charges sociales et ITS '.$label.($run ? '' : ' (estimées)'), -((float) $vals->cost - (float) $vals->net), array_fill(0, 3, $chargesDay->toDateString()));
                    }
                }
            }
        }
        // 8. Notes de frais approuvées, à rembourser
        if ($c->hasModule('notes_frais') && Schema::hasTable('nf_expenses')) {
            $nf = (float) DB::table('nf_expenses')->where('company_id', $c->id)->where('status', 'approved')->whereNull('deleted_at')->sum('amount');
            $add('notes_frais', 'Notes de frais approuvées à rembourser', -$nf, array_fill(0, 3, $today->copy()->next(Carbon::FRIDAY)->toDateString()));
        }
        // 9. TVA : collectée (factures, tickets, moins avoirs) − déductible (factures fournisseurs), reversée le N du mois suivant
        if ($set['vat_enabled']) {
            foreach ($this->vatPlan($start, $end, $today, $set['vat_day']) as $v) {
                $add('tva', $v['label'], -$v['amount'], array_fill(0, 3, $v['date']));
            }
        }
        // 10. Prévisions saisies (récurrentes ou ponctuelles), éventuellement limitées à certains scénarios
        foreach (Forecast::all() as $f) {
            $only = $f->scenarios ? array_values(array_intersect(array_keys(self::SCENARIOS), (array) $f->scenarios)) : array_keys(self::SCENARIOS);
            $w = array_map(fn ($k) => in_array($k, $only, true) ? 1 : 0, array_keys(self::SCENARIOS));
            $date = $f->date->copy();
            $until = $f->until ?? $end;
            $guard = 0;
            while ($date->lte($until) && $date->lt($end) && $guard++ < 200) {
                if ($date->gte($start) || $f->repeat === 'none') {
                    $add('previsions', $f->label, (float) $f->amount, array_fill(0, 3, $date->toDateString()), $w, $f->id);
                }
                if ($f->repeat === 'none') {
                    break;
                }
                $f->repeat === 'weekly' ? $date->addWeek() : $date->addMonthNoOverflow();
            }
        }

        // ------------------------------------------------------------ cumul par semaine et par scénario
        $balance = round(TreasuryAccount::where('active', true)->get()->sum(fn ($a) => app(TreasuryService::class)->balance($a)), 2);
        $result = [];
        foreach (array_keys(self::SCENARIOS) as $i => $sc) {
            $buckets = [];
            for ($k = 0; $k < $weeks; $k++) {
                $buckets[$k] = ['week' => $start->copy()->addWeeks($k)->toDateString(), 'in' => 0.0, 'out' => 0.0, 'categories' => [], 'details' => []];
            }
            foreach ($items as $it) {
                $amount = $it['amount'] * $it['w'][$i];
                if (abs($amount) < 0.5) {
                    continue;
                }
                $k = max(0, (int) floor($start->diffInDays(Carbon::parse($it['dates'][$i]), false) / 7));
                if ($k >= $weeks) {
                    continue;
                }
                $buckets[$k][$amount >= 0 ? 'in' : 'out'] += $amount;
                $buckets[$k]['categories'][$it['cat']] = ($buckets[$k]['categories'][$it['cat']] ?? 0) + $amount;
                $buckets[$k]['details'][] = ['label' => $it['label'], 'category' => $it['cat'], 'amount' => round($amount, 2), 'date' => $it['dates'][$i], 'ref' => $it['ref']];
            }
            $running = $balance;
            $firstBelow = null;
            foreach ($buckets as &$b) {
                $running += $b['in'] + $b['out'];
                $b['in'] = round($b['in'], 2);
                $b['out'] = round($b['out'], 2);
                $b['balance'] = round($running, 2);
                $b['categories'] = (object) array_map(fn ($v) => round($v, 2), $b['categories']);
                usort($b['details'], fn ($x, $y) => [$x['date'], $x['amount']] <=> [$y['date'], $y['amount']]);
                $b['details'] = array_slice($b['details'], 0, 60);
                if ($firstBelow === null && $running < $set['threshold']) {
                    $firstBelow = $b['week'];
                }
            }
            unset($b);
            $result[$sc] = ['weeks' => array_values($buckets), 'lowest' => round(collect($buckets)->min('balance'), 2), 'end' => end($buckets)['balance'], 'first_below' => $firstBelow,
                'in' => round(collect($buckets)->sum('in'), 2), 'out' => round(collect($buckets)->sum('out'), 2)];
        }
        $r = $result['realistic'];
        $this->last = ['items' => $items, 'start' => $start, 'weeks' => $weeks, 'balance' => $balance, 'threshold' => $set['threshold'], 'delays' => $delays];

        return [
            'start_balance' => $balance, 'threshold' => $set['threshold'], 'weeks_count' => $weeks,
            // Compatibilité : scénario réaliste à plat
            'weeks' => $r['weeks'], 'lowest' => $r['lowest'], 'first_below' => $r['first_below'],
            'scenarios' => collect($result)->map(fn ($x, $k) => ['label' => self::SCENARIOS[$k], 'balances' => array_column($x['weeks'], 'balance'), 'lowest' => $x['lowest'],
                'end' => $x['end'], 'first_below' => $x['first_below'], 'in' => $x['in'], 'out' => $x['out']])->all(),
            'scenario_weeks' => ['optimistic' => $result['optimistic']['weeks'], 'pessimistic' => $result['pessimistic']['weeks']],
            'categories' => self::CATEGORIES,
            'customer_delays' => ['clients' => count($delays), 'average' => $delays ? (int) round(array_sum($delays) / count($delays)) : 0],
        ];
    }

    /** Point bas et première semaine sous le seuil d'un scénario (1 = réaliste, 2 = pessimiste), pour une liste de lignes. */
    protected function realisticLow(array $items, int $sc = 1): array
    {
        ['start' => $start, 'weeks' => $weeks, 'balance' => $balance, 'threshold' => $th] = $this->last;
        $sum = array_fill(0, $weeks, 0.0);
        foreach ($items as $it) {
            $a = $it['amount'] * $it['w'][$sc];
            $k = max(0, (int) floor($start->diffInDays(Carbon::parse($it['dates'][$sc]), false) / 7));
            if ($k < $weeks && abs($a) >= 0.5) {
                $sum[$k] += $a;
            }
        }
        $run = $balance;
        $low = PHP_FLOAT_MAX;
        $below = null;
        foreach ($sum as $k => $v) {
            $run += $v;
            $low = min($low, $run);
            if ($below === null && $run < $th) {
                $below = $k;
            }
        }

        return [round($low, 2), $below];
    }

    /**
     * Relances prioritaires : pour chaque client en retard, ce que gagnerait le point bas de trésorerie (scénario réaliste)
     * s'il réglait ses échéances échues cette semaine plutôt qu'à sa date habituelle.
     */
    public function priorities(): array
    {
        $f = $this->compute();
        $items = $this->last['items'];
        [$low, $below] = $this->realisticLow($items);
        [$lowP, $belowP] = $this->realisticLow($items, 2);
        $today = now()->toDateString();
        $groups = [];
        foreach ($items as $idx => $it) {
            if ($it['cat'] === 'clients' && ($it['overdue'] ?? false) && ! empty($it['partner'])) {
                $groups[$it['partner']][] = $idx;
            }
        }
        $partners = \App\Modules\Core\Models\Partner::withTrashed()->whereIn('id', array_keys($groups))->get()->keyBy('id');
        $levels = DB::table('trade_documents')->where('company_id', app('tenant')->id)->where('direction', 'sale')->where('type', 'invoice')->where('payment_status', '!=', 'paid')
            ->whereIn('partner_id', array_keys($groups))->whereNull('deleted_at')->groupBy('partner_id')->selectRaw('partner_id, max(dunning_level) l, max(last_dunning_at) d')->get()->keyBy('partner_id');
        $rows = [];
        foreach ($groups as $pid => $idxs) {
            $alt = $items;
            $amount = 0.0;
            $expected = null;
            $oldest = null;
            $docs = [];
            foreach ($idxs as $i) {
                $alt[$i]['dates'] = array_fill(0, 3, $today);
                $alt[$i]['w'] = [1, 1, 1];
                $amount += $items[$i]['amount'];
                $expected = min($expected ?? '9999', $items[$i]['dates'][1]);
                $oldest = min($oldest ?? '9999', $items[$i]['due']);
                $docs[$items[$i]['ref']] = true;
            }
            [$l2, $b2] = $this->realisticLow($alt);
            [$l2p, $b2p] = $this->realisticLow($alt, 2);
            $p = $partners[$pid] ?? null;
            $rows[] = [
                'partner_id' => $pid, 'name' => $p?->name ?? '—', 'email' => $p?->email, 'phone' => $p?->phone, 'paused' => (bool) $p?->dunning_paused,
                'overdue' => round($amount, 2), 'invoices' => count($docs), 'oldest_due' => $oldest, 'days_late' => (int) Carbon::parse($oldest)->diffInDays(now()->startOfDay()),
                'expected_date' => $expected, 'usual_delay' => $this->last['delays'][$pid] ?? null,
                'low_gain' => round($l2 - $low, 2), 'fixes_threshold' => $below !== null && ($b2 === null || $b2 > $below),
                // Scénario prudent : le client paierait encore plus tard, ou pas du tout pour une créance ancienne
                'low_gain_pessimistic' => round($l2p - $lowP, 2), 'fixes_threshold_pessimistic' => $belowP !== null && ($b2p === null || $b2p > $belowP),
                'level' => (int) ($levels[$pid]->l ?? 0), 'last_reminder' => $levels[$pid]->d ?? null,
            ];
        }
        $key = fn ($r) => [$r['fixes_threshold'], $r['fixes_threshold_pessimistic'], $r['low_gain'], $r['low_gain_pessimistic'], $r['overdue']];
        usort($rows, fn ($a, $b) => $key($b) <=> $key($a));

        return ['rows' => array_slice($rows, 0, 100), 'lowest' => $low, 'lowest_pessimistic' => $lowP, 'first_below_pessimistic' => $belowP !== null ? $this->last['start']->copy()->addWeeks($belowP)->toDateString() : null, 'threshold' => $this->last['threshold'], 'first_below' => $f['first_below'], 'currency' => app('tenant')->currency];
    }

    /** TVA nette d'une période, à partir des documents validés. */
    public function vatNet(string $from, string $to): float
    {
        $q = fn (string $dir, array $types) => (float) DB::table('trade_documents')->where('company_id', app('tenant')->id)->whereNull('deleted_at')->where('status', 'validated')
            ->where('direction', $dir)->whereIn('type', $types)->whereBetween('date', [$from, $to])->sum('tax_total');

        return round($q('sale', ['invoice', 'receipt']) - $q('sale', ['credit_note']) - $q('purchase', ['supplier_invoice']), 2);
    }

    /**
     * Versements de TVA de la période : mois écoulé (montant réel), mois en cours et suivants estimés
     * sur la moyenne des trois derniers mois complets ; un crédit de TVA est reporté sur les mois suivants.
     *
     * @return array<int, array{label: string, date: string, amount: float}>
     */
    public function vatPlan(Carbon $start, Carbon $end, Carbon $today, int $day): array
    {
        $month = $today->copy()->startOfMonth();
        $hist = [];
        for ($k = 1; $k <= 3; $k++) {
            $m = $month->copy()->subMonths($k);
            $hist[] = $this->vatNet($m->toDateString(), $m->copy()->endOfMonth()->toDateString());
        }
        $avg = round(array_sum($hist) / 3, 2);
        $out = [];
        $credit = 0.0;
        for ($m = $month->copy()->subMonth(); ; $m->addMonth()) {
            $pay = $m->copy()->addMonthNoOverflow()->day(min(28, max(1, $day)));
            if ($pay->gte($end)) {
                break;
            }
            $real = $m->lt($month);
            // Mois écoulé : réel ; mois en cours : le réel à date s'il dépasse déjà la moyenne ; ensuite : moyenne
            $net = $real ? $hist[0] : ($m->eq($month) ? max($avg, $this->vatNet($month->toDateString(), $today->toDateString())) : $avg);
            $due = max(0, $net - $credit);
            $credit = max(0, $credit - $net); // crédit de TVA reporté
            if ($pay->gte($today) && $due >= 1) {
                $out[] = ['label' => 'TVA '.$m->locale('fr')->isoFormat('MMMM YYYY').($real ? '' : ' (estimée)'), 'date' => $pay->toDateString(), 'amount' => $due];
            }
        }

        return $out;
    }

    /** Alerte quand le solde réaliste passe sous le seuil dans la période ; une fois par semaine de franchissement. */
    public function checkAlert(Company $c): ?string
    {
        $f = $this->compute();
        $below = $f['first_below'];
        $state = data_get($c->settings, '_forecast_alert');
        if (! $below) {
            if ($state) {
                $s = (array) $c->settings;
                unset($s['_forecast_alert']);
                $c->forceFill(['settings' => $s])->saveQuietly();
            }

            return null;
        }
        if ($state === $below) {
            return null;
        }
        $s = (array) $c->settings;
        $s['_forecast_alert'] = $below;
        $c->forceFill(['settings' => $s])->saveQuietly();
        app()->instance('tenant', $c->fresh());
        $to = User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('tresorerie.manage'))->pluck('id')->all();
        $fmt = fn ($v) => number_format($v, 0, ',', ' ');
        AppNotification::send($c->id, $to, 'tresorerie', 'Trésorerie prévue sous le seuil la semaine du '.Carbon::parse($below)->format('d/m'),
            'Point bas prévu (réaliste) : '.$fmt($f['lowest']).' '.$c->currency.' · seuil '.$fmt($f['threshold']).'. Ouvrez la prévision pour voir les encaissements à relancer.', 'tresorerie', null);

        return $below;
    }
}
