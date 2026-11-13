<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ModuleOrder;
use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use App\Models\PlatformSetting;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Store\ModuleStore;
use Illuminate\Http\Request;

/** Boutique des modules, côté société cliente. */
class StoreController extends Controller
{
    public function __construct(protected ModuleStore $store, protected PaymentService $payments) {}

    protected function fail(string $message, int $code = 422)
    {
        return response()->json(['message' => $message], $code);
    }

    /**
     * Société pour laquelle on achète : la sienne, ou la société « licence » d'une de ses installations
     * (paramètre license / license_id, plateforme SaaS).
     */
    protected function target(Request $request): array
    {
        $id = (int) ($request->input('license_id') ?: $request->query('license'));
        if (! $id || config('erp.mode') !== 'saas') {
            return [app('tenant'), null];
        }
        $l = \App\Models\License::where('owner_company_id', app('tenant')->id)->whereNotIn('status', ['revoked'])->findOrFail($id);
        abort_unless($l->company, 404);

        return [$l->company, $l];
    }

    /** Sociétés dont l'utilisateur peut voir les commandes : la sienne et celles de ses licences. */
    protected function ownedCompanyIds(): array
    {
        $ids = [app('tenant')->id];
        if (config('erp.mode') === 'saas') {
            $ids = array_merge($ids, \App\Models\License::where('owner_company_id', app('tenant')->id)->pluck('company_id')->all());
        }

        return $ids;
    }

    public function index(Request $request)
    {
        [$company, $license] = $this->target($request);

        $catalog = $this->store->catalog($company);
        $licenses = config('erp.mode') === 'saas'
            ? \App\Models\License::where('owner_company_id', app('tenant')->id)->where('status', '!=', 'revoked')->orderBy('company_name')->get()
                ->map(fn ($l) => ['id' => $l->id, 'company_name' => $l->company_name, 'masked_key' => $l->maskedKey(), 'status' => $l->status, 'target' => $l->target])->values()
            : [];
        $licenseNames = config('erp.mode') === 'saas' ? \App\Models\License::where('owner_company_id', app('tenant')->id)->pluck('company_name', 'company_id') : collect();

        return [
            'license' => $license ? ['id' => $license->id, 'company_name' => $license->company_name, 'masked_key' => $license->maskedKey(), 'status' => $license->status] : null,
            'licenses' => $licenses,
            'modules' => $catalog,
            'packs' => $this->store->packs($company, $catalog),
            'plans' => $license ? [] : $this->store->plans($company),
            'referral' => $license ? null : ['available' => \App\Store\Referrals::available($company), 'welcome_discount' => \App\Store\Referrals::refereeDiscount($company, 100.0)],
            'subscription' => $license ? null : [
                'plan' => $company->plan?->only(['code', 'name']), 'status' => $company->status,
                'trial_ends_at' => $company->trial_ends_at?->toIso8601String(), 'subscription_ends_at' => $company->subscription_ends_at?->toIso8601String(),
                'is_free' => (bool) $company->plan?->is_free,
            ],
            'minute_packs' => ! $license && $company->hasModule('visio') ? \App\Modules\Visio\VisioService::packs() : [],
            'visio_quota' => ! $license && $company->hasModule('visio') ? app(\App\Modules\Visio\VisioService::class)->quota($company) : null,
            'contact' => ModuleStore::contact(),
            'payment_methods' => PaymentGateway::store()->where('enabled', true)->orderBy('sort')->orderBy('id')->get()->map->toPublic()->values(),
            'bank_accounts' => (array) PlatformSetting::get('store.bank_accounts', []),
            'orders' => ModuleOrder::whereIn('company_id', $this->ownedCompanyIds())->latest('id')->limit(50)->get()
                ->map(fn ($o) => $this->orderArray($o) + ['license_name' => $licenseNames[$o->company_id] ?? null]),
            'can_buy' => $request->user()->hasPermission('core.settings.manage'),
            'mode' => config('erp.mode'),
            // Installation autonome : les modules s'achètent sur le compte Yomara en ligne, pas dans cette copie
            'self_host' => $this->selfHosted() ? \Illuminate\Support\Arr::only(\App\Licensing\LicenseClient::info(), ['linked', 'status', 'company_name', 'server', 'buy_url', 'next_expiry']) : null,
        ];
    }

    protected function orderArray(ModuleOrder $o): array
    {
        $o->loadMissing('module:key,name', 'gateway');

        return $o->toArray() + [
            'module_name' => $o->label(),
            'is_pack' => $o->isPack(),
            'billing_label' => ModuleOrder::BILLINGS[$o->billing] ?? $o->billing,
            'status_label' => ModuleOrder::STATUSES[$o->status] ?? $o->status,
            'gateway_label' => $o->gateway?->label,
            'gateway_online' => $o->gateway?->isOnline(),
            'instructions' => $o->gateway && ! $o->gateway->isOnline() ? $o->gateway->instructions : null,
        ];
    }

    /** Copie installée chez le client (hors plateforme) dont les modules viennent du compte Yomara. */
    protected function selfHosted(): bool
    {
        return config('erp.mode') !== 'saas' && \App\Licensing\LicenseClient::enforced();
    }

    protected function selfHostRefusal()
    {
        return $this->fail('Sur votre installation, les modules s\'achètent sur votre compte Yomara en ligne ('.\App\Licensing\LicenseClient::server().') : ils arrivent ensuite ici automatiquement.');
    }

    public function order(Request $request)
    {
        if ($this->selfHosted()) {
            return $this->selfHostRefusal();
        }
        $data = $request->validate([
            'module' => 'required|string|max:50',
            'billing' => 'required|in:one_time,monthly,yearly',
            'gateway_id' => 'nullable|integer',
            'license_id' => 'nullable|integer',
            'promo_code' => 'nullable|string|max:40',
            'use_credit' => 'nullable|boolean',
        ]);
        [$company, $license] = $this->target($request);
        if ($license && (str_starts_with($data['module'], 'minutes:') || str_starts_with($data['module'], 'plan:'))) {
            return $this->fail('Pour une licence, achetez les modules un par un ou en pack.');
        }
        try {
            $r = $this->store->order($company, $request->user(), $data['module'], $data['billing'], (int) ($data['gateway_id'] ?? 0), $data['promo_code'] ?? null, (bool) ($data['use_credit'] ?? true));
        } catch (\DomainException|PaymentException $e) {
            return $this->fail($e->getMessage());
        }

        return response()->json([
            'activated' => (bool) ($r['activated'] ?? false),
            'order' => $r['order'] ? $this->orderArray($r['order']) : null,
            'payment_url' => $r['payment_url'],
            'instructions' => $r['instructions'],
            'transaction' => $r['transaction'] ?? null,
        ], 201);
    }

    protected function mine(int $id): ModuleOrder
    {
        return ModuleOrder::whereIn('company_id', $this->ownedCompanyIds())->findOrFail($id);
    }

    /** Vérifie un paiement en ligne (après retour du site de paiement). */
    public function verify(int $id)
    {
        $order = $this->mine($id);
        foreach (PaymentTransaction::where(['purpose' => 'module_order', 'purpose_id' => (string) $order->id, 'status' => 'pending'])->get() as $tx) {
            $this->payments->check($tx);
        }

        return ['order' => $this->orderArray($order->fresh())];
    }

    /** Paiement hors ligne effectué : le client indique sa référence, le super admin valide. */
    public function declare(Request $request, int $id)
    {
        $order = $this->mine($id);
        $data = $request->validate(['reference' => 'required|string|max:120', 'notes' => 'nullable|string|max:1000']);
        if (! in_array($order->status, ['pending', 'awaiting_validation'], true)) {
            return $this->fail('Cette commande ne peut plus être modifiée.');
        }
        // Paiement en ligne : l'activation suit la confirmation du prestataire, pas une référence déclarée
        if ($order->gateway?->isOnline()) {
            return $this->fail('Cette commande se règle en ligne : elle s\'active d\'elle-même dès que le prestataire confirme le paiement.');
        }
        $order->update(['status' => 'awaiting_validation', 'payer_reference' => $data['reference'], 'notes' => $data['notes'] ?? null]);
        ActivityLog::record('store.order.declared', $order, ['reference' => $data['reference']]);
        rescue(fn () => \App\Platform\PlatformNotifier::paymentDeclared($order->fresh()), null, false);

        return ['order' => $this->orderArray($order)];
    }

    /** Reçu PDF d'une commande payée de la société. */
    public function receipt(int $id)
    {
        $o = ModuleOrder::whereIn('company_id', $this->ownedCompanyIds())->findOrFail($id);
        abort_unless($o->status === 'paid', 422, 'Cette commande n\'est pas payée.');

        return response(\App\Platform\OrderReceipt::pdf($o), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.\App\Platform\OrderReceipt::filename($o).'"']);
    }

    public function cancel(int $id)
    {
        $order = $this->mine($id);
        if ($order->status === 'paid') {
            return $this->fail('Une commande payée ne peut pas être annulée.');
        }
        $order->update(['status' => 'cancelled']);

        return ['order' => $this->orderArray($order)];
    }

    /** Aperçu de la remise d'un code promo avant de payer. */
    public function checkPromo(Request $request)
    {
        $d = $request->validate(['code' => 'required|string|max:40', 'module' => 'required|string|max:60', 'billing' => 'required|in:one_time,monthly,yearly', 'license_id' => 'nullable|integer']);
        [$company] = $this->target($request);
        $key = $d['module'];
        try {
            if (str_starts_with($key, 'plan:')) {
                $plan = \App\Models\Plan::where('code', substr($key, 5))->where('is_active', true)->firstOrFail();
                $offer = $plan->offer(ModuleStore::paidPlanMonths($company));
                $amount = $d['billing'] === 'yearly' ? $offer['yearly'] : $offer['monthly_now'];
                $kind = $company->plan_id === $plan->id && $company->subscription_ends_at ? 'renew' : 'new';
                $r = app(\App\Store\PromoService::class)->check($d['code'], $company, $request->user(), 'plan', $plan->code, $kind, (float) $amount);
            } else {
                $row = str_starts_with($key, 'pack:') ? collect($this->store->packs($company))->firstWhere('key', $key) : collect($this->store->catalog($company))->firstWhere('key', $key);
                abort_unless($row, 404);
                $amount = (float) (((array) $row['offers'])[$d['billing']] ?? 0);
                $kind = ! str_starts_with($key, 'pack:') && ($row['active'] ?? false) && ($row['source'] ?? '') === 'purchase' ? 'renew' : 'new';
                $r = app(\App\Store\PromoService::class)->check($d['code'], $company, $request->user(), 'module', $key, $kind, $amount);
            }
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage());
        }

        return ['code' => $r['promo']->code, 'label' => $r['promo']->label(), 'amount' => $amount, 'discount' => $r['discount'], 'total' => max(0, $amount - $r['discount'])];
    }

    public function trial(Request $request, string $key)
    {
        if ($this->selfHosted()) {
            return $this->selfHostRefusal();
        }
        [$company] = $this->target($request);
        try {
            $cm = $this->store->startTrial($company, $key);
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage());
        }

        return ['ok' => true, 'expires_at' => $cm->expires_at->toIso8601String()];
    }
}
