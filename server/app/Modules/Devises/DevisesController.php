<?php

namespace App\Modules\Devises;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DevisesController extends Controller
{
    public function __construct(private FxService $fx) {}

    public function index()
    {
        $c = app('tenant');

        return ['data' => ['base' => strtoupper($c->currency), 'currencies' => $this->fx->currencies($c), 'available' => FxService::COMMON]];
    }

    public function update(Request $r)
    {
        $v = $r->validate(['currencies' => 'required|array', 'currencies.*' => ['string', 'regex:/^[A-Z]{3}$/']]);
        $c = app('tenant');
        $s = $c->settings ?? [];
        $s['devises']['currencies'] = array_values(array_unique(array_diff($v['currencies'], [strtoupper($c->currency)])));
        $c->forceFill(['settings' => $s])->save();

        return $this->index();
    }

    /** Cours applicable à une date (contrôle de saisie). */
    public function rate(Request $r)
    {
        $v = $r->validate(['currency' => 'required|string|size:3', 'date' => 'nullable|date']);

        return ['data' => ['rate' => $this->fx->rate(app('tenant'), $v['currency'], $v['date'] ?? null)]];
    }
}
