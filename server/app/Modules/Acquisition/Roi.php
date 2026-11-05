<?php

namespace App\Modules\Acquisition;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Résultats des campagnes : dépense, prospects (CRM), ventes gagnées et commandes en ligne attribuées,
 * coût par prospect, coût par vente et retour (chiffre d'affaires / dépense).
 */
class Roi
{
    protected static ?bool $orders = null;

    protected static function ordersAttributed(): bool
    {
        return self::$orders ??= Schema::hasTable('ws_orders') && Schema::hasColumn('ws_orders', 'utm_campaign');
    }

    /** Opportunités du CRM rattachées à la campagne (par identifiant, ou par son code utm_campaign). */
    protected function leadsQuery(int $cid, array $c, ?string $from = null, ?string $to = null)
    {
        return DB::table('crm_leads')->where('company_id', $cid)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('ad_campaign_id', $c['id'])->when($c['utm_campaign'] ?? null, fn ($w) => $w->orWhere('utm_campaign', $c['utm_campaign'])))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59'));
    }

    public function forCampaign(int $cid, array $c, ?string $from = null, ?string $to = null): array
    {
        $spent = (float) DB::table('ac_spend')->where('company_id', $cid)->where('campaign_id', $c['id'])->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to))->sum('amount');
        $leads = $this->leadsQuery($cid, $c, $from, $to);
        $nLeads = (clone $leads)->count();
        $wonQ = (clone $leads)->where('status', 'won');
        $won = (clone $wonQ)->count();
        $revenue = (float) (clone $wonQ)->sum('expected_revenue');
        if (self::ordersAttributed() && ! empty($c['utm_campaign'])) {
            $orders = DB::table('ws_orders')->where('company_id', $cid)->where('utm_campaign', $c['utm_campaign'])->whereNotIn('status', ['cancelled'])
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59'));
            $won += (clone $orders)->count();
            $revenue += (float) (clone $orders)->sum('total');
        }

        return [
            'spent' => round($spent, 2), 'leads' => $nLeads, 'won' => $won, 'revenue' => round($revenue, 2),
            'cpl' => $nLeads ? round($spent / $nLeads, 2) : null, 'cps' => $won ? round($spent / $won, 2) : null,
            'roas' => $spent > 0 ? str_replace('.', ',', (string) round($revenue / $spent, 1)).' ×' : ($revenue > 0 ? 'sans dépense' : '—'),
            'roas_value' => $spent > 0 ? round($revenue / $spent, 2) : null,
        ];
    }

    /** Tableau de bord de la période : totaux, par plateforme, par campagne, évolution jour par jour. */
    public function report(Company $c, string $from, string $to): array
    {
        $cid = $c->id;
        $campaigns = DB::table('ac_campaigns')->where('company_id', $cid)->get()->map(fn ($x) => (array) $x);
        $rows = [];
        foreach ($campaigns as $camp) {
            $r = $this->forCampaign($cid, $camp, $from, $to);
            if ($r['spent'] > 0 || $r['leads'] > 0 || $r['won'] > 0) {
                $rows[] = ['id' => $camp['id'], 'title' => $camp['title'], 'platform' => $camp['platform'], 'status' => $camp['status']] + $r;
            }
        }
        usort($rows, fn ($a, $b) => $b['spent'] <=> $a['spent'] ?: $b['leads'] <=> $a['leads']);
        $by = [];
        foreach ($rows as $r) {
            $p = $r['platform'];
            $by[$p] ??= ['platform' => $p, 'label' => AcquisitionHooks::PLATFORMS[$p] ?? $p, 'spent' => 0, 'leads' => 0, 'won' => 0, 'revenue' => 0];
            foreach (['spent', 'leads', 'won', 'revenue'] as $k) {
                $by[$p][$k] += $r[$k];
            }
        }
        $by = array_values(array_map(fn ($b) => $b + ['cpl' => $b['leads'] ? round($b['spent'] / $b['leads']) : null, 'roas_value' => $b['spent'] > 0 ? round($b['revenue'] / $b['spent'], 2) : null], $by));
        $spent = array_sum(array_column($rows, 'spent'));
        $revenue = array_sum(array_column($rows, 'revenue'));
        $leadsAll = DB::table('ac_leads')->where('company_id', $cid)->whereBetween('received_at', [$from, $to.' 23:59:59']);
        $daily = [];
        $spendDays = DB::table('ac_spend')->where('company_id', $cid)->whereBetween('date', [$from, $to])->selectRaw('date, sum(amount) as s')->groupBy('date')->pluck('s', 'date');
        $leadDays = (clone $leadsAll)->selectRaw('DATE(received_at) as d, count(*) as n')->groupBy('d')->pluck('n', 'd');
        for ($d = \Illuminate\Support\Carbon::parse($from); $d->lte(\Illuminate\Support\Carbon::parse($to)); $d->addDay()) {
            $k = $d->toDateString();
            $daily[] = ['date' => $k, 'spent' => round((float) ($spendDays[$k] ?? 0), 2), 'leads' => (int) ($leadDays[$k] ?? 0)];
        }
        $wonLeads = array_sum(array_column($rows, 'won'));
        $totalLeads = (clone $leadsAll)->count();

        return [
            'from' => $from, 'to' => $to,
            'spent' => round($spent, 2), 'leads' => $totalLeads, 'crm_leads' => array_sum(array_column($rows, 'leads')), 'won' => $wonLeads, 'revenue' => round($revenue, 2),
            'cpl' => $totalLeads ? round($spent / $totalLeads) : null, 'roas' => $spent > 0 ? round($revenue / $spent, 2) : null,
            'duplicates' => (clone $leadsAll)->where('status', 'duplicate')->count(),
            'by_platform' => $by, 'campaigns' => array_slice($rows, 0, 50), 'daily' => $daily,
            'by_source' => (clone $leadsAll)->selectRaw('platform, count(*) as n')->groupBy('platform')->pluck('n', 'platform'),
        ];
    }
}
