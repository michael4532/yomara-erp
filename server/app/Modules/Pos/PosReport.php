<?php

namespace App\Modules\Pos;

use App\Modules\Pos\Models\PosSession;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use Illuminate\Support\Facades\DB;

/** Rapport de caisse (rapport Z) d'une session. */
class PosReport
{
    public function forSession(PosSession $session): array
    {
        $tickets = SalesDocument::where('pos_session_id', $session->id)->where('type', 'receipt');
        $valid = (clone $tickets)->where('status', 'validated');
        $ids = (clone $valid)->pluck('id');

        $byMethod = SalesPayment::whereIn('document_id', $ids)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')->get()
            ->mapWithKeys(fn ($r) => [$r->method => ['total' => round((float) $r->total, 2), 'count' => (int) $r->count]]);

        $top = DB::table('trade_document_lines')
            ->whereIn('document_id', $ids)
            ->selectRaw('description, SUM(quantity) as quantity, SUM(total) as total')
            ->groupBy('description')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['description' => $r->description, 'quantity' => round((float) $r->quantity, 3), 'total' => round((float) $r->total, 2)]);

        $cash = $byMethod['cash']['total'] ?? 0;
        $totalSales = round((float) (clone $valid)->sum('total'), 2);

        return [
            'session_id' => $session->id,
            'tickets_count' => (clone $valid)->count(),
            'cancelled_count' => (clone $tickets)->where('status', 'cancelled')->count(),
            'total_sales' => $totalSales,
            'tax_total' => round((float) (clone $valid)->sum('tax_total'), 2),
            'unpaid' => round($totalSales - (float) (clone $valid)->sum('amount_paid'), 2),
            'by_method' => $byMethod,
            'top_products' => $top,
            'opening_cash' => $session->opening_cash,
            'expected_cash' => round($session->opening_cash + $cash, 2),
            'counted_cash' => $session->counted_cash,
            'difference' => $session->counted_cash !== null ? round($session->counted_cash - ($session->opening_cash + $cash), 2) : null,
        ];
    }
}
