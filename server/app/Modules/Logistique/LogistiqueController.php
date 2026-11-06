<?php

namespace App\Modules\Logistique;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Modules\Logistique\Models\Delivery;
use App\Modules\Logistique\Models\DeliveryEvent;
use App\Modules\Logistique\Models\Zone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class LogistiqueController extends Controller
{
    /** Livreurs : utilisateurs ayant le droit de livrer (ou de gérer la logistique). */
    public function drivers()
    {
        $users = User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($u) => $u->hasPermission('logistique.deliver') || $u->hasPermission('logistique.manage'))
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'driver' => $u->hasPermission('logistique.deliver') && ! $u->is_super_admin])->values();

        return ['drivers' => $users];
    }

    public function events(string $id)
    {
        $d = Delivery::findOrFail($id);
        $users = User::where('company_id', app('tenant')->id)->pluck('name', 'id');

        return ['data' => DeliveryEvent::where('delivery_id', $d->id)->orderBy('id')->get()->map(fn ($e) => $e->toArray() + ['user' => $users[$e->user_id] ?? null]),
            'tracking_url' => $d->trackingUrl()];
    }

    /** Photo de preuve (prise par le livreur, en ligne). */
    public function photo(Request $r, string $id)
    {
        $d = Delivery::findOrFail($id);
        $user = $r->user();
        abort_unless($user->hasPermission('logistique.manage') || $d->driver_id === $user->id, 403);
        $r->validate(['photo' => 'required|image|max:8192']);
        $dir = public_path('uploads/deliveries/'.app('tenant')->id);
        File::ensureDirectoryExists($dir);
        $name = Str::random(32).'.'.$r->file('photo')->extension();
        $r->file('photo')->move($dir, $name);
        $d->photo = url('uploads/deliveries/'.app('tenant')->id.'/'.$name);
        $d->save();

        return ['data' => $d->fresh()];
    }

    /** Remise à la caisse des montants encaissés par un livreur. */
    public function remit(Request $r)
    {
        $data = $r->validate(['driver_id' => 'required|integer', 'ids' => 'nullable|array', 'ids.*' => 'uuid']);
        $q = Delivery::where('driver_id', $data['driver_id'])->where('status', 'delivered')->where('cod_collected', '>', 0)->whereNull('cod_remitted_at')
            ->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids));
        $rows = $q->get();
        foreach ($rows as $d) {
            $d->forceFill(['cod_remitted_at' => now()]);
            $d->save();
        }
        $total = round($rows->sum('cod_collected'), 2);
        ActivityLog::record('logistique.remit', null, ['driver_id' => $data['driver_id'], 'count' => $rows->count(), 'total' => $total]);

        return ['count' => $rows->count(), 'total' => $total];
    }

    /** Indicateurs sur une période. */
    public function stats(Request $r)
    {
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = Carbon::parse($r->query('from', now()->startOfMonth()->toDateString()))->toDateString();
        $to = Carbon::parse($r->query('to', now()->toDateString()))->toDateString();
        $rows = Delivery::whereBetween('scheduled_date', [$from, $to])->where('status', '!=', 'cancelled')->get();
        $users = User::where('company_id', app('tenant')->id)->pluck('name', 'id');
        $zones = Zone::pluck('name', 'id');
        $done = $rows->where('status', 'delivered');
        $closed = $rows->whereIn('status', ['delivered', 'failed', 'returned']);
        $onTime = $done->filter(fn ($d) => $d->scheduled_date && $d->delivered_at && $d->delivered_at->toDateString() <= $d->scheduled_date->toDateString())->count();

        return [
            'from' => $from, 'to' => $to,
            'total' => $rows->count(),
            'by_status' => collect(Delivery::STATUSES)->map(fn ($label, $k) => ['status' => $k, 'label' => $label, 'count' => $rows->where('status', $k)->count()])->values(),
            'success_rate' => $closed->count() ? round($done->count() * 100 / $closed->count(), 1) : null,
            'on_time_rate' => $done->count() ? round($onTime * 100 / $done->count(), 1) : null,
            'fees' => round($rows->sum('fee'), 2),
            'cod_collected' => round($done->sum('cod_collected'), 2),
            'cod_pending' => round(Delivery::where('status', 'delivered')->whereNull('cod_remitted_at')->sum('cod_collected'), 2),
            'drivers' => $rows->whereNotNull('driver_id')->groupBy('driver_id')->map(fn ($g, $id) => [
                'driver_id' => (int) $id, 'name' => $users[$id] ?? '—', 'total' => $g->count(),
                'delivered' => $g->where('status', 'delivered')->count(), 'failed' => $g->whereIn('status', ['failed', 'returned'])->count(),
                'cod_collected' => round($g->where('status', 'delivered')->sum('cod_collected'), 2),
                'cod_pending' => round($g->where('status', 'delivered')->whereNull('cod_remitted_at')->sum('cod_collected'), 2),
            ])->sortByDesc('delivered')->values(),
            'zones' => $rows->groupBy(fn ($d) => $d->zone_id ?? '')->map(fn ($g, $id) => ['zone' => $zones[$id] ?? 'Sans zone', 'count' => $g->count(), 'fees' => round($g->sum('fee'), 2)])->sortByDesc('count')->values(),
            'failures' => $rows->whereIn('status', ['failed', 'returned'])->groupBy(fn ($d) => $d->failed_reason ?: 'Non précisé')->map->count()->sortDesc(),
        ];
    }
}
