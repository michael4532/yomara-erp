<?php

namespace App\Modules\Lots;

use App\Http\Controllers\Controller;
use App\Modules\Lots\Models\Lot;
use Illuminate\Http\Request;

class LotsController extends Controller
{
    public function __construct(private LotService $svc) {}

    public function levels(Request $r)
    {
        $f = $r->validate(['product_id' => 'nullable|uuid', 'warehouse_id' => 'nullable|uuid', 'status' => 'nullable|in:all,ok,expiring,expired', 'q' => 'nullable|string|max:80']);

        return ['data' => $this->svc->levels($f), 'settings' => $this->settings()['data']];
    }

    public function trace(string $id)
    {
        return ['data' => $this->svc->trace(Lot::findOrFail($id))];
    }

    /** Recherche d'un numéro de lot ou de série (où est-il ? à qui a-t-il été vendu ?). */
    public function search(Request $r)
    {
        $q = trim((string) $r->validate(['q' => 'required|string|min:2|max:80'])['q']);
        $lots = Lot::with('product:id,name,tracking')->where('number', 'like', '%'.$q.'%')->orWhere('supplier_lot', 'like', '%'.$q.'%')->limit(30)->get();

        return ['data' => $lots->map(fn ($l) => $l->toSyncArray() + ['product_name' => $l->product?->name, 'tracking' => $l->product?->tracking, 'stock' => $this->svc->available($l->id)])];
    }

    public function scrap(Request $r)
    {
        $v = $r->validate(['lot_ids' => 'nullable|array', 'lot_ids.*' => 'uuid', 'warehouse_id' => 'nullable|uuid']);

        return ['data' => ['moves' => $this->svc->scrap($v['lot_ids'] ?? [], $v['warehouse_id'] ?? null)]];
    }

    public function settings()
    {
        $c = app('tenant');

        return ['data' => ['block_expired' => (bool) ($c->setting('lots_block_expired') ?? true), 'alert_days' => (int) ($c->setting('lots_alert_days') ?? 30)]];
    }

    public function updateSettings(Request $r)
    {
        $v = $r->validate(['block_expired' => 'boolean', 'alert_days' => 'integer|min:0|max:3650']);
        $c = app('tenant');
        $s = $c->settings ?? [];
        if (array_key_exists('block_expired', $v)) {
            $s['lots_block_expired'] = $v['block_expired'];
        }
        if (array_key_exists('alert_days', $v)) {
            $s['lots_alert_days'] = $v['alert_days'];
        }
        $c->forceFill(['settings' => $s])->save();

        return $this->settings();
    }
}
