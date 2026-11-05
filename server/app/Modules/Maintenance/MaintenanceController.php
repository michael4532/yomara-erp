<?php

namespace App\Modules\Maintenance;

use App\Http\Controllers\Controller;
use App\Support\Records\RecordController;
use App\Support\Records\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaintenanceController extends Controller
{
    public function __construct(private MaintenanceHooks $h) {}

    public function dashboard()
    {
        return ['data' => $this->h->dashboard(app('tenant'))];
    }

    /** Crée tout de suite les ordres de travail préventifs arrivés à échéance. */
    public function generate()
    {
        return ['data' => ['created' => DB::transaction(fn () => $this->h->generate(app('tenant')))]];
    }

    public function action(Request $r, int $id, string $action)
    {
        $c = app('tenant');
        $in = $r->validate([
            'at' => 'nullable|date', 'note' => 'nullable|string|max:1000', 'report' => 'nullable|string|max:10000', 'parts_used' => 'nullable|string|max:500',
            'cause' => 'nullable|in:'.implode(',', array_keys(MaintenanceHooks::CAUSES)), 'priority' => 'nullable|in:'.implode(',', array_keys(MaintenanceHooks::PRIORITIES)),
            'assignee_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('company_id', $c->id)], 'planned_on' => 'nullable|date',
            'labour_hours' => 'nullable|numeric|min:0', 'labour_cost' => 'nullable|numeric|min:0', 'parts_cost' => 'nullable|numeric|min:0', 'external_cost' => 'nullable|numeric|min:0',
            'meter_reading' => 'nullable|numeric|min:0', 'downtime_hours' => 'nullable|numeric|min:0',
        ]);
        try {
            $row = DB::transaction(fn () => $this->h->action($c, $r->user(), $id, $action, $in));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => app(RecordController::class)->present(Records::resource(__DIR__.'/records.php', 'orders'), [$row])[0]];
    }
}
