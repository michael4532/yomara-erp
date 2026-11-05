<?php

namespace App\Modules\Feuilles;

use App\Http\Controllers\Controller;
use App\Modules\Feuilles\Models\Week;
use Illuminate\Http\Request;

class FeuillesController extends Controller
{
    public function __construct(private FeuillesService $svc) {}

    /** Ma semaine (ou celle d'une personne, pour un responsable). */
    public function week(Request $r)
    {
        $v = $r->validate(['week' => 'nullable|date', 'user_id' => 'nullable|integer']);
        $uid = (int) ($v['user_id'] ?? $r->user()->id);
        abort_if($uid !== $r->user()->id && ! $r->user()->hasPermission('feuilles_temps.approve'), 403);
        $monday = FeuillesService::monday($v['week'] ?? now());
        $w = Week::where('user_id', $uid)->where('week_start', $monday)->first() ?? (new Week)->forceFill(['user_id' => $uid, 'week_start' => $monday, 'status' => 'draft']);

        return ['data' => $this->svc->detail($w)];
    }

    public function show(string $id)
    {
        return ['data' => $this->svc->detail(Week::findOrFail($id))];
    }

    /** Feuilles à valider avec leur détail. */
    public function pending()
    {
        return ['data' => Week::where('status', 'submitted')->orderBy('week_start')->get()->map(fn ($w) => $this->svc->detail($w))->values()];
    }

    public function settings()
    {
        return ['data' => $this->svc->settings(app('tenant'))];
    }

    public function saveSettings(Request $r)
    {
        return ['data' => $this->svc->saveSettings(app('tenant'), $r->validate(['require_approval' => 'sometimes|boolean', 'remind_friday' => 'sometimes|boolean']))];
    }
}
