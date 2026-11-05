<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Store\CompanyPaymentController;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\ModuleOrder;
use App\Models\PaymentGateway;
use App\Models\PlatformSetting;
use App\Payments\GatewayRegistry;
use App\Payments\InvoicePayments;
use App\Store\ModuleStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Super admin : commandes de modules, moyens de paiement de la plateforme, réglages de la boutique. */
class StoreAdminController extends Controller
{
    public function orders(Request $r)
    {
        $q = ModuleOrder::with(['company:id,name', 'module:key,name', 'gateway:id,label,driver'])->latest('id');
        if ($s = $r->query('status')) {
            $q->where('status', $s);
        }

        return [
            'data' => $q->limit(300)->get()->map(fn ($o) => $o->toArray() + [
                'status_label' => ModuleOrder::STATUSES[$o->status] ?? $o->status,
                'billing_label' => ModuleOrder::BILLINGS[$o->billing] ?? $o->billing,
                'module_name' => $o->label(),
            ]),
            'to_validate' => ModuleOrder::where('status', 'awaiting_validation')->count(),
            'revenue_month' => round((float) ModuleOrder::where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->sum('amount'), 2),
        ];
    }

    public function approve(Request $request, int $id, ModuleStore $store)
    {
        $order = ModuleOrder::findOrFail($id);
        if (in_array($order->status, ['paid', 'cancelled'], true)) {
            return response()->json(['message' => 'Commande déjà traitée.'], 422);
        }
        $store->activate($order, $request->user()->id);

        return ['data' => $order->fresh()];
    }

    public function reject(Request $request, int $id)
    {
        $order = ModuleOrder::findOrFail($id);
        if ($order->status === 'paid') {
            return response()->json(['message' => 'Commande déjà payée.'], 422);
        }
        $order->update(['status' => 'failed', 'notes' => trim(($order->notes ? $order->notes."\n" : '').'Refus : '.$request->input('reason', 'paiement non reçu'))]);
        ActivityLog::record('store.order.rejected', $order);
        rescue(fn () => \App\Platform\PlatformNotifier::rejected($order->fresh(), (string) $request->input('reason', 'paiement non reçu')), null, false);

        return ['data' => $order];
    }

    // ---------- Réglages ----------

    public function settings()
    {
        return [
            'contact' => ModuleStore::contact(),
            'bank_accounts' => (array) PlatformSetting::get('store.bank_accounts', []),
            'company_drivers' => InvoicePayments::allowedDrivers(),
            'drivers' => GatewayRegistry::catalog(),
            'gateways' => PaymentGateway::platform()->orderBy('sort')->orderBy('id')->get()->map->toAdmin()->values(),
            'visio' => ['included_minutes' => \App\Modules\Visio\VisioService::includedMinutes(), 'packs' => \App\Modules\Visio\VisioService::packs()],
            'downloads' => \App\Http\Controllers\LandingController::linkSettings(),
            'downloads_detected' => (object) array_filter(\App\Http\Controllers\LandingController::detectedFiles()),
            'official_links' => (object) \App\Http\Controllers\LandingController::officialStoreLinks(),
            'apps_version' => \App\Http\Controllers\LandingController::appsVersion(),
            'download_counts' => (object) \App\Http\Controllers\LandingController::downloadCounts(),
            'landing_enabled' => config('erp.mode') === 'saas',
        ];
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'visio' => 'sometimes|array',
            'visio.included_minutes' => 'nullable|integer|min:0|max:1000000',
            'visio.packs' => 'nullable|array|max:12',
            'visio.packs.*.code' => 'required|string|max:20|regex:/^[a-z0-9_-]+$/',
            'visio.packs.*.minutes' => 'required|integer|min:1|max:10000000',
            'visio.packs.*.price' => 'required|numeric|min:0',
            'visio.packs.*.label' => 'nullable|string|max:80',
            'contact' => 'sometimes|array',
            'contact.name' => 'nullable|string|max:120',
            'contact.phone' => 'nullable|string|max:60',
            'contact.whatsapp' => 'nullable|string|max:60',
            'contact.email' => 'nullable|email|max:120',
            'contact.website' => 'nullable|string|max:200',
            'contact.address' => 'nullable|string|max:255',
            'contact.hours' => 'nullable|string|max:120',
            'contact.message' => 'nullable|string|max:500',
            'bank_accounts' => 'sometimes|array|max:10',
            'bank_accounts.*.bank' => 'required|string|max:120',
            'bank_accounts.*.holder' => 'nullable|string|max:120',
            'bank_accounts.*.account' => 'nullable|string|max:80',
            'bank_accounts.*.iban' => 'nullable|string|max:60',
            'bank_accounts.*.swift' => 'nullable|string|max:20',
            'downloads' => 'sometimes|array',
            'downloads.*' => 'nullable|url:http,https|max:500',
            'apps_version' => 'sometimes|nullable|string|max:30',
            'company_drivers' => 'sometimes|array',
            'company_drivers.*' => 'in:'.implode(',', array_keys(GatewayRegistry::DRIVERS)),
        ]);
        if (isset($data['contact'])) {
            PlatformSetting::put('store.contact', array_filter($data['contact'], fn ($v) => $v !== null));
        }
        if (isset($data['bank_accounts'])) {
            PlatformSetting::put('store.bank_accounts', array_values($data['bank_accounts']));
        }
        if (isset($data['downloads'])) {
            $keys = \App\Http\Controllers\LandingController::LINK_KEYS;
            // fusion : une application plus ancienne qui n'envoie pas les nouvelles clés ne les efface pas
            $merged = array_merge((array) PlatformSetting::get('landing.downloads', []), array_map(fn ($v) => $v ? trim($v) : null, $data['downloads']));
            PlatformSetting::put('landing.downloads', array_intersect_key($merged, array_flip($keys)));
        }
        if ($request->has('apps_version')) {
            PlatformSetting::put('landing.apps_version', trim((string) ($data['apps_version'] ?? '')));
        }
        if (isset($data['company_drivers'])) {
            PlatformSetting::put('payments.company_drivers', array_values($data['company_drivers']));
        }
        if (isset($data['visio'])) {
            if (array_key_exists('included_minutes', $data['visio']) && $data['visio']['included_minutes'] !== null) {
                PlatformSetting::put('visio.included_minutes', (int) $data['visio']['included_minutes']);
            }
            if (isset($data['visio']['packs'])) {
                PlatformSetting::put('visio.packs', array_values($data['visio']['packs']));
            }
        }
        ActivityLog::record('admin.store.settings');

        return $this->settings();
    }

    public function storeGateway(Request $request)
    {
        $data = $request->validate(CompanyPaymentController::gatewayRules(array_keys(GatewayRegistry::DRIVERS), true));
        $gw = new PaymentGateway;
        CompanyPaymentController::fill($gw, $data);
        $gw->company_id = null;
        $gw->save();

        return response()->json($gw->toAdmin(), 201);
    }

    public function updateGateway(Request $request, int $id)
    {
        $gw = PaymentGateway::platform()->findOrFail($id);
        CompanyPaymentController::fill($gw, $request->validate(CompanyPaymentController::gatewayRules(array_keys(GatewayRegistry::DRIVERS), false)));
        $gw->save();

        return $gw->toAdmin();
    }

    public function testGateway(int $id)
    {
        return CompanyPaymentController::checkGateway(PaymentGateway::platform()->findOrFail($id));
    }

    public function destroyGateway(int $id)
    {
        PaymentGateway::platform()->findOrFail($id)->delete();

        return ['ok' => true];
    }

    // ---------- Captures d'écran de la fiche boutique ----------

    public function uploadScreenshot(Request $request, string $key)
    {
        $module = Module::where('key', $key)->firstOrFail();
        $request->validate(['image' => 'required|image|mimes:png,jpg,jpeg,webp|max:4096']);
        $dir = public_path('uploads/store');
        File::ensureDirectoryExists($dir);
        $name = $key.'-'.Str::random(8).'.'.$request->file('image')->extension();
        $request->file('image')->move($dir, $name);
        $module->update(['screenshots' => array_values(array_merge($module->screenshots ?? [], ['uploads/store/'.$name]))]);

        return ['data' => $module->fresh(), 'url' => url('uploads/store/'.$name)];
    }

    public function deleteScreenshot(Request $request, string $key)
    {
        $module = Module::where('key', $key)->firstOrFail();
        $path = (string) $request->input('path');
        $list = array_values(array_filter($module->screenshots ?? [], fn ($s) => $s !== $path && ModuleStore::url($s) !== $path));
        $module->update(['screenshots' => $list]);
        if (str_starts_with($path, 'uploads/store/') && File::exists(public_path($path))) {
            File::delete(public_path($path));
        }

        return ['data' => $module->fresh()];
    }
}
