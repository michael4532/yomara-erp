<?php

namespace App\Modules\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Modules\Sites\Models\SiteOrder;
use App\Modules\Sites\ShopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EcommerceController extends Controller
{
    public function __construct(protected EcommerceService $svc) {}

    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function one(SiteOrder $o): array
    {
        $set = $this->svc->settings(app('tenant'));
        $alloc = $this->svc->allocation(app('tenant'), SiteOrder::whereIn('status', ['new', 'confirmed', 'preparing'])->whereNull('invoice_id')->get());

        return ['data' => $this->svc->toApi($o->fresh(), null, $alloc, $set)];
    }

    public function overview(ShopService $shop)
    {
        $c = app('tenant');
        $s = \App\Modules\Sites\Models\Site::first();
        $set = $this->svc->settings($c);
        $counts = $s ? SiteOrder::where('site_id', $s->id)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status') : collect();
        $shopSet = $s ? $shop->settings($s) : ShopService::defaults();
        $drivers = $c->hasModule('logistique') ? \App\Models\User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('logistique.deliver'))
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values() : [];

        return ['data' => [
            'site' => $s ? ['id' => $s->id, 'name' => $s->name, 'status' => $s->status, 'shop_url' => rtrim(app(\App\Modules\Sites\SiteService::class)->url($s), '/').'/boutique'] : null,
            'shop_enabled' => (bool) ($shopSet['enabled'] ?? false), 'zones' => $shopSet['zones'] ?? [], 'pickup' => (bool) ($shopSet['pickup'] ?? false),
            'payments' => collect(ShopService::PAYMENT_MODES)->filter(fn ($l, $k) => $shopSet['payment_'.$k] ?? false)->all(),
            'settings' => collect($set)->except('feed_token')->all(), 'feeds' => $this->svc->feedUrls($c), 'whatsapp_link' => $this->svc->whatsappLink($c),
            'counts' => $counts, 'statuses' => ShopService::STATUSES, 'channels' => EcommerceService::CHANNELS, 'payment_modes' => ShopService::PAYMENT_MODES,
            'methods' => ['mobile_money' => 'Mobile Money', 'cash' => 'Espèces', 'card' => 'Carte', 'bank_transfer' => 'Virement', 'cheque' => 'Chèque'],
            'logistique' => $c->hasModule('logistique'), 'drivers' => $drivers,
            'stats' => $s ? $this->svc->stats($s, now()->startOfMonth()->toDateString(), now()->toDateString()) : null,
        ]];
    }

    public function orders(Request $r)
    {
        $s = \App\Modules\Sites\Models\Site::first();
        if (! $s) {
            return ['data' => []];
        }
        $q = SiteOrder::where('site_id', $s->id);
        match ($r->query('status', 'open')) {
            'open' => $q->whereIn('status', EcommerceService::OPEN),
            'all' => null,
            default => $q->where('status', $r->query('status')),
        };
        if ($r->filled('channel')) {
            $q->where('channel', $r->query('channel'));
        }
        if ($r->filled('q')) {
            $t = '%'.$r->query('q').'%';
            $q->where(fn ($w) => $w->where('number', 'like', $t)->orWhere('name', 'like', $t)->orWhere('phone', 'like', $t));
        }
        $orders = $q->orderByDesc('id')->limit(500)->get();
        $set = $this->svc->settings(app('tenant'));
        $alloc = $this->svc->allocation(app('tenant'), SiteOrder::where('site_id', $s->id)->whereIn('status', ['new', 'confirmed', 'preparing'])->whereNull('invoice_id')->get());

        return ['data' => $orders->map(fn ($o) => $this->svc->toApi($o, $s, $alloc, $set))->values()];
    }

    public function show(int $id)
    {
        $o = SiteOrder::findOrFail($id);
        $out = $this->one($o);
        $out['data']['history'] = ActivityLog::where('subject_type', 'SiteOrder')->where('subject_id', (string) $o->id)->orderBy('id')->get(['action', 'properties', 'user_id', 'created_at'])
            ->map(fn ($l) => $l->toArray() + ['user' => DB::table('users')->where('id', $l->user_id)->value('name')])->values();

        return $out;
    }

    protected function act(int $id, string $action, callable $fn)
    {
        $o = SiteOrder::findOrFail($id);

        return $this->guard(function () use ($o, $action, $fn) {
            $o = $fn($o);
            rescue(fn () => ActivityLog::record('ecommerce.'.$action, $o, ['number' => $o->number, 'status' => $o->status]), null, false);

            return $this->one($o);
        });
    }

    public function create(Request $r)
    {
        $d = $r->validate([
            'channel' => 'required|in:'.implode(',', array_keys(EcommerceService::CHANNELS)), 'name' => 'required|string|max:160', 'phone' => 'required|string|max:40',
            'email' => 'nullable|email|max:190', 'address' => 'nullable|string|max:300', 'delivery' => 'required|string|max:120', 'payment' => 'required|in:online,cod,momo',
            'items' => 'required|array|min:1|max:60', 'items.*.id' => 'required|uuid', 'items.*.quantity' => 'required|integer|min:1|max:999',
            'coupon' => 'nullable|string|max:40', 'note' => 'nullable|string|max:1000', 'confirm' => 'nullable|boolean',
        ]);

        return $this->guard(function () use ($d) {
            $o = $this->svc->createManual($d);
            rescue(fn () => ActivityLog::record('ecommerce.create', $o, ['number' => $o->number, 'channel' => $o->channel]), null, false);

            return response()->json($this->one($o), 201);
        });
    }

    public function confirm(int $id)
    {
        return $this->act($id, 'confirm', fn ($o) => $this->svc->confirm($o));
    }

    public function prepare(int $id)
    {
        return $this->act($id, 'prepare', fn ($o) => $this->svc->prepare($o));
    }

    public function ship(Request $r, int $id)
    {
        $d = $r->validate(['delivery' => 'nullable|boolean', 'driver_id' => 'nullable|integer', 'date' => 'nullable|date', 'time_slot' => 'nullable|string|max:30', 'packages' => 'nullable|integer|min:1|max:500']);

        return $this->act($id, 'ship', fn ($o) => $this->svc->ship($o, array_filter($d, fn ($v) => $v !== null)));
    }

    public function pay(Request $r, int $id)
    {
        $d = $r->validate(['method' => 'nullable|string|max:20', 'reference' => 'nullable|string|max:80', 'amount' => 'nullable|numeric|min:1', 'date' => 'nullable|date', 'treasury_account_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('tr_accounts')]]);

        return $this->act($id, 'pay', fn ($o) => $this->svc->pay($o, array_filter($d, fn ($v) => $v !== null)));
    }

    public function deliver(Request $r, int $id)
    {
        $d = $r->validate(['collected' => 'nullable|boolean', 'method' => 'nullable|string|max:20', 'reference' => 'nullable|string|max:80', 'signed_by' => 'nullable|string|max:120']);

        return $this->act($id, 'deliver', fn ($o) => $this->svc->deliver($o, $d));
    }

    public function cancel(Request $r, int $id)
    {
        $d = $r->validate(['reason' => 'nullable|string|max:300']);

        return $this->act($id, 'cancel', fn ($o) => $this->svc->cancel($o, $d['reason'] ?? null));
    }

    public function note(Request $r, int $id)
    {
        $d = $r->validate(['note' => 'nullable|string|max:1000']);

        return $this->act($id, 'note', function ($o) use ($d) {
            $o->forceFill(['staff_note' => $d['note'] ?: null])->save();

            return $o;
        });
    }

    public function picking(Request $r)
    {
        if (is_string($r->query('ids'))) {
            $r->merge(['ids' => array_values(array_filter(array_map('intval', explode(',', $r->query('ids')))))]);
        }
        $d = $r->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer', 'mark' => 'nullable|boolean']);
        $orders = SiteOrder::whereIn('id', $d['ids'])->where('status', '!=', 'cancelled')->orderBy('id')->get();
        abort_if($orders->isEmpty(), 404);
        if (! empty($d['mark']) && auth()->user()->hasPermission('ecommerce.manage')) {
            foreach ($orders as $o) {
                rescue(fn () => $this->svc->prepare($o), null, false);
            }
        }

        return response($this->svc->pickingPdf(app('tenant'), $orders->map->fresh()), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="preparation-'.now()->format('Ymd-Hi').'.pdf"',
        ]);
    }

    public function stats(Request $r)
    {
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $s = \App\Modules\Sites\Models\Site::first();
        if (! $s) {
            return ['data' => null];
        }

        return ['data' => $this->svc->stats($s, $r->query('from', now()->subDays(29)->toDateString()), $r->query('to', now()->toDateString()))];
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['reserve_stock' => 'sometimes|boolean', 'auto_delivery' => 'sometimes|boolean', 'notify_customer' => 'sometimes|boolean', 'alert_hours' => 'sometimes|integer|min:1|max:240',
            'whatsapp_number' => 'sometimes|nullable|string|max:30', 'brand' => 'sometimes|nullable|string|max:120']);
        $c = app('tenant');
        $set = $this->svc->saveSettings($c, $d);

        return ['data' => collect($set)->except('feed_token')->all() + ['feeds' => $this->svc->feedUrls($c), 'whatsapp_link' => $this->svc->whatsappLink($c)]];
    }

    public function resetFeed()
    {
        $c = app('tenant');
        $this->svc->resetFeed($c);

        return ['data' => ['feeds' => $this->svc->feedUrls($c)]];
    }
}
