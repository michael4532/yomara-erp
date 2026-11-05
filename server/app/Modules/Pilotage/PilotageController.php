<?php

namespace App\Modules\Pilotage;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PilotageController extends Controller
{
    public function __construct(private PilotageService $s) {}

    protected function period(Request $r): array
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        return [$d['from'] ?? now()->startOfYear()->toDateString(), $d['to'] ?? now()->toDateString()];
    }

    public function overview(Request $r)
    {
        [$from, $to] = $this->period($r);
        abort_if(\Illuminate\Support\Carbon::parse($from)->diffInMonths(\Illuminate\Support\Carbon::parse($to)) > 36, 422, 'Période trop longue (3 ans maximum).');

        return ['data' => $this->s->overview(app('tenant'), $from, $to)];
    }

    public function forecast()
    {
        return ['data' => $this->s->forecast(app('tenant'))];
    }

    public function breakeven(Request $r)
    {
        $w = (int) ($r->validate(['window' => 'nullable|integer|in:1,3,6,12'])['window'] ?? 3);

        return ['data' => $this->s->breakeven(app('tenant'), $w)];
    }

    public function profitability(Request $r)
    {
        [$from, $to] = $this->period($r);
        $by = $r->validate(['by' => 'nullable|in:customer,product,category'])['by'] ?? 'customer';

        return ['data' => $this->s->profitability(app('tenant'), $by, $from, $to)];
    }
}
