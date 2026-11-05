<?php

namespace App\Modules\Planning;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PlanningController extends Controller
{
    public function __construct(private PlanningService $svc) {}

    private function range(Request $r): array
    {
        $v = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = Carbon::parse($v['from'] ?? now()->startOfWeek())->toDateString();
        $to = Carbon::parse($v['to'] ?? Carbon::parse($from)->addDays(27))->toDateString();
        abort_if($to < $from || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92, 422, 'Période de 3 mois au plus.');

        return [$from, $to];
    }

    public function load(Request $r)
    {
        [$from, $to] = $this->range($r);

        return ['data' => $this->svc->load(app('tenant'), $from, $to)];
    }

    public function available(Request $r)
    {
        [$from, $to] = $this->range($r);

        return ['data' => $this->svc->available(app('tenant'), $from, $to, (float) $r->input('hours', 4))];
    }

    public function settings()
    {
        return ['data' => $this->svc->settings(app('tenant'))];
    }

    public function saveSettings(Request $r)
    {
        $v = $r->validate(['week_hours' => 'sometimes|numeric|min:1|max:80', 'work_days' => 'sometimes|array|min:1|max:7', 'work_days.*' => 'integer|between:1,7',
            'overload_percent' => 'sometimes|integer|min:50|max:200']);

        return ['data' => $this->svc->saveSettings(app('tenant'), $v)];
    }
}
