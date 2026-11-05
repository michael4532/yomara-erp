<?php

namespace App\Modules\Ventes;

use App\Models\Company;
use App\Modules\Ventes\Models\SalesDocument;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $month = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
        $validated = fn () => SalesDocument::where('status', 'validated');

        $invoiced = $validated()->whereIn('type', ['invoice', 'receipt'])->whereBetween('date', $month)->sum(\DB::raw('total * exchange_rate'));
        $credits = $validated()->where('type', 'credit_note')->whereBetween('date', $month)->sum(\DB::raw('total * exchange_rate'));
        $unpaid = $validated()->where('type', 'invoice')->where('payment_status', '!=', 'paid')
            ->selectRaw('COALESCE(SUM((total - amount_paid) * exchange_rate), 0) as v')->value('v');
        $overdue = $validated()->where('type', 'invoice')->where('payment_status', '!=', 'paid')
            ->whereDate('due_date', '<', now())->count();

        // CA des 6 derniers mois (graphique)
        $series = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $series[] = [
                'label' => $m->translatedFormat('M'),
                'value' => (float) $validated()->whereIn('type', ['invoice', 'receipt'])
                    ->whereBetween('date', [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()])->sum(\DB::raw('total * exchange_rate')),
            ];
        }

        return [
            ['key' => 'revenue_month', 'label' => 'CA du mois (TTC)', 'value' => round($invoiced - $credits, 2), 'format' => 'money', 'icon' => 'trending_up', 'series' => $series],
            ['key' => 'unpaid', 'label' => 'Reste à encaisser', 'value' => round((float) $unpaid, 2), 'format' => 'money', 'icon' => 'hourglass_bottom'],
            ['key' => 'overdue', 'label' => 'Factures en retard', 'value' => $overdue, 'format' => 'number', 'icon' => 'warning', 'alert' => $overdue > 0],
            ['key' => 'open_quotes', 'label' => 'Devis en cours', 'value' => $validated()->where('type', 'quote')->count(), 'format' => 'number', 'icon' => 'request_quote'],
        ];
    }
}
