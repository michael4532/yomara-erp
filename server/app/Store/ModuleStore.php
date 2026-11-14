<?php

namespace App\Store;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Models\Plan;
use App\Models\ModuleOrder;
use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use App\Models\PlatformSetting;
use App\Models\PromoCode;
use App\Models\User;
use App\Payments\PaymentContext;
use App\Payments\PaymentService;
use App\Support\ModuleRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Boutique des modules : catalogue par société, commandes, essais, activation. */
class ModuleStore
{
    public function __construct(protected ModuleRegistry $registry, protected PaymentService $payments) {}

    public static function contact(): array
    {
        return array_replace([
            'name' => config('erp.name'), 'phone' => null, 'whatsapp' => null, 'email' => null, 'website' => null,
            'address' => null, 'hours' => null, 'message' => 'Besoin d\'aide pour choisir ? Contactez-nous.',
        ], (array) PlatformSetting::get('store.contact', []));
    }

    public function catalog(Company $company): array
    {
        $enabled = $this->registry->enabledFor($company);
        $overrides = $company->moduleOverrides()->get()->keyBy('module_key');
        $plan = Plan::modulesFor($company->plan);
        $modules = Module::where('is_active', true)->where('is_core', false)->where('in_store', true)->orderBy('sort')->get();
        $names = Module::pluck('name', 'key');

        return $modules->map(function (Module $m) use ($enabled, $overrides, $plan, $names) {
            $o = $overrides->get($m->key);
            $active = in_array($m->key, $enabled, true);
            $source = match (true) {
                ! $active => null,
                $o && $o->isEffective() => $o->source ?? 'admin',
                in_array($m->key, $plan, true) => 'plan',
                $m->billing_mode === 'free' => 'free',
                default => 'included',
            };
            $missing = array_values(array_filter($m->depends ?? [], fn ($d) => ! in_array($d, $enabled, true) && $d !== 'core'));

            return [
                'key' => $m->key, 'name' => $m->name, 'category' => $m->category, 'icon' => $m->icon,
                'description' => $m->description, 'tagline' => $m->tagline, 'long_description' => $m->long_description,
                'features' => $m->features ?? [], 'screenshots' => array_map(fn ($s) => self::url($s), $m->screenshots ?? []),
                'demo_url' => $m->demo_url, 'available' => $m->is_available, 'billing_mode' => $m->billing_mode,
                'offers' => (object) $m->offers($o?->price_override), 'currency' => $m->currency,
                'tier' => $tier = \App\Support\PricingGrid::tierOf($m->key, (float) $m->price_monthly), 'tier_label' => \App\Support\PricingGrid::TIERS[$tier]['label'] ?? null,
                'trial_days' => $m->trial_days, 'can_trial' => $m->is_available && $m->trial_days > 0 && ! $active && ! ($o?->trial_used),
                'active' => $active, 'source' => $source, 'billing' => $o?->billing, 'expires_at' => $o?->expires_at?->toIso8601String(),
                'depends' => $m->depends ?? [], 'missing_dependencies' => array_map(fn ($d) => ['key' => $d, 'name' => $names[$d] ?? $d], $missing),
            ];
        })->values()->all();
    }

    /**
     * Packs par département pour cette société : modules restant à acheter (ceux de la formule sont offerts),
     * prix mensuel remisé (arrondi à la centaine), économie, pack déjà actif.
     */
    public function packs(Company $company, ?array $catalog = null): array
    {
        $catalog = collect($catalog ?? $this->catalog($company))->keyBy('key');
        $out = [];
        foreach (\App\Support\PricingGrid::PACKS as $code => $p) {
            $mods = collect($p['modules'])->map(fn ($k) => $catalog[$k] ?? null)->filter();
            if ($mods->isEmpty()) {
                continue;
            }
            // Déjà fournis sans achat (formule, offert par l'administrateur, gratuit) : ni facturés ni prolongés
            $toBuy = $mods->filter(fn ($m) => ! ($m['active'] && in_array($m['source'], ['plan', 'free', 'included', 'admin'], true)) && $m['billing_mode'] !== 'free' && $m['available']
                // Seuls les modules vendus au mois entrent dans un pack (un module en achat unique ne peut pas être obtenu via le pack)
                && (float) ($m['offers']->monthly ?? 0) > 0);
            $full = (float) $toBuy->sum(fn ($m) => (float) ($m['offers']->monthly ?? 0));
            $monthly = $full > 0 ? round($full * (100 - $p['discount']) / 100 / 100) * 100 : 0.0;
            $out[] = [
                'key' => 'pack:'.$code, 'code' => $code, 'name' => $p['name'], 'icon' => $p['icon'], 'description' => $p['description'], 'discount' => $p['discount'],
                'modules' => $mods->map(fn ($m) => ['key' => $m['key'], 'name' => $m['name'], 'active' => $m['active'], 'source' => $m['source']])->values()->all(),
                'to_buy' => $toBuy->pluck('key')->values()->all(),
                'full_monthly' => $full, 'offers' => (object) ($monthly > 0 ? ['monthly' => $monthly, 'yearly' => $monthly * 10] : []),
                'saving_yearly' => $full * 12 - $monthly * 10,
                'active' => $mods->every(fn ($m) => $m['active']),
                'currency' => $mods->first()['currency'] ?? 'XOF',
            ];
        }

        return $out;
    }

    public static function url(string $path): string
    {
        return preg_match('#^https?://#', $path) ? $path : url($path);
    }

    /** @return array{order: ModuleOrder, payment_url: ?string, instructions: ?string} */
    /** Utiliser le crédit de parrainage sur la commande en cours. */
    protected bool $useCredit = true;

    /** Période demandée par la commande en cours (passage du mois à l'année autorisé). */
    protected string $pendingBilling = 'monthly';

    public function order(Company $company, User $user, string $key, string $billing, int $gatewayId, ?string $promo = null, bool $useCredit = true): array
    {
        // Une commande à la fois par société (verrou) : un code promo ou le crédit de parrainage ne servent pas deux fois
        // quand deux commandes arrivent en même temps.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($company, $user, $key, $billing, $gatewayId, $promo, $useCredit) {
            Company::withoutGlobalScopes()->whereKey($company->id)->lockForUpdate()->first();
            if ($promo !== null && trim($promo) !== '') {
                PromoCode::where('code', PromoService::normalize($promo))->lockForUpdate()->first();
            }

            return $this->placeOrder($company->fresh() ?? $company, $user, $key, $billing, $gatewayId, $promo, $useCredit);
        });
    }

    /** Une commande déjà ouverte ou un produit déjà actif ne se commande pas une deuxième fois. */
    protected function guardDuplicate(Company $company, string $key): void
    {
        if (str_starts_with($key, 'minutes:')) {
            return; // forfaits de minutes : cumulables
        }
        $open = ModuleOrder::where('company_id', $company->id)->where('module_key', $key)->whereIn('status', ['pending', 'awaiting_validation'])->latest('id')->get();
        foreach ($open as $o) {
            $gw = $o->gateway_id ? PaymentGateway::find($o->gateway_id) : null;
            // Paiement en ligne abandonné (30 minutes) ou refusé : remplacé par la nouvelle commande
            $lastTx = $gw?->isOnline() ? PaymentTransaction::where('purpose', 'module_order')->where('purpose_id', (string) $o->id)->latest('created_at')->value('status') : null;
            if ($o->status === 'pending' && $gw?->isOnline() && ($o->created_at?->lt(now()->subMinutes(30)) || in_array($lastTx, ['failed', 'cancelled', 'expired'], true))) {
                $o->update(['status' => 'cancelled']);
                continue;
            }
            throw new \DomainException('Vous avez déjà une commande en cours pour ce produit ('.$o->number.', '.mb_strtolower(ModuleOrder::STATUSES[$o->status] ?? $o->status).'). '
                .($gw?->isOnline() ? 'Terminez son paiement ou attendez 30 minutes avant de recommencer.' : 'Elle sera activée dès que notre équipe aura vérifié votre paiement. Vous pouvez l\'annuler depuis « Mes commandes ».'));
        }
        if (str_starts_with($key, 'plan:')) {
            $plan = Plan::where('code', substr($key, 5))->first();
            $lastBilling = ModuleOrder::where('company_id', $company->id)->where('module_key', $key)->where('status', 'paid')->latest('id')->value('billing');
            if ($plan && $company->plan_id === $plan->id && $company->status === 'active' && $company->subscription_ends_at?->gt(now()->addDays(7))
                && ! ($lastBilling === 'monthly' && $this->pendingBilling === 'yearly')) {
                throw new \DomainException('Votre formule '.$plan->name.' est déjà active jusqu\'au '.$company->subscription_ends_at->format('d/m/Y').'. Vous pourrez la renouveler dans les 7 jours qui précèdent cette date.');
            }

            return;
        }
        if (str_starts_with($key, 'pack:')) {
            return; // le pack ne facture que les modules restant à acheter
        }
        $cm = $company->moduleOverrides()->where('module_key', $key)->first();
        if ($cm && $cm->isEffective() && $cm->source !== 'trial' && (! $cm->expires_at || $cm->expires_at->gt(now()->addDays(7)))
            && ! ($cm->billing === 'monthly' && $this->pendingBilling === 'yearly')) { // passage du mois à l'année : permis
            throw new \DomainException($cm->expires_at
                ? 'Ce module est déjà actif jusqu\'au '.$cm->expires_at->format('d/m/Y').'. Vous pourrez le renouveler dans les 7 jours qui précèdent cette date.'
                : 'Ce module est déjà actif pour votre entreprise.');
        }
        if (! $cm && $company->status !== 'trial' && in_array($key, Plan::modulesFor($company->plan), true)) {
            throw new \DomainException('Ce module est déjà compris dans votre formule.');
        }
    }

    protected function placeOrder(Company $company, User $user, string $key, string $billing, int $gatewayId, ?string $promo = null, bool $useCredit = true): array
    {
        $this->useCredit = $useCredit;
        $this->pendingBilling = $billing;
        $this->guardDuplicate($company, $key);
        if (str_starts_with($key, 'plan:')) {
            return $this->orderPlan($company, $user, substr($key, 5), $billing, $gatewayId, $promo);
        }
        if (str_starts_with($key, 'pack:')) {
            return $this->orderPack($company, $user, $key, $billing, $gatewayId, $promo);
        }
        if (str_starts_with($key, 'minutes:')) {
            return $this->orderMinutes($company, $user, $key, $gatewayId);
        }
        $m = Module::where('key', $key)->where('is_active', true)->where('in_store', true)->first();
        if (! $m || $m->is_core) {
            throw new \DomainException('Module introuvable dans la boutique.');
        }
        if (! $m->is_available) {
            throw new \DomainException('Ce module sera bientôt disponible : contactez-nous pour être prévenu.');
        }
        $o = $company->moduleOverrides()->where('module_key', $key)->first();
        $offers = $m->offers($o?->price_override);
        if (! isset($offers[$billing])) {
            throw new \DomainException('Cette formule n\'est pas proposée pour ce module.');
        }
        if ($billing === 'one_time' && $o && $o->isEffective() && $o->billing === 'one_time') {
            throw new \DomainException('Vous possédez déjà ce module.');
        }
        $enabled = $this->registry->enabledFor($company);
        $missing = array_filter($m->depends ?? [], fn ($d) => ! in_array($d, $enabled, true));
        if ($missing) {
            $names = Module::whereIn('key', $missing)->pluck('name')->implode(', ');
            throw new \DomainException("Ce module nécessite d'abord : $names.");
        }
        $gw = PaymentGateway::store()->where('enabled', true)->find((int) $gatewayId);

        $kind = $o && $o->source === 'purchase' && $o->isEffective() ? 'renew' : 'new';
        [$amount, $extra] = $this->withPromo($promo, $company, $user, 'module', $key, $kind, (float) $offers[$billing]);
        if (! $gw && $amount > 0) {
            throw new \DomainException('Moyen de paiement indisponible.');
        }
        $order = ModuleOrder::create([
            'company_id' => $company->id, 'user_id' => $user->id, 'module_key' => $key, 'billing' => $billing,
            'amount' => $amount, 'currency' => $m->currency ?: 'XOF', 'status' => 'pending', 'gateway_id' => $gw?->id,
            'number' => 'TMP-'.uniqid(),
        ] + $extra);
        $order->update(['number' => sprintf('MOD-%s-%05d', now()->format('Y'), $order->id)]);
        ActivityLog::record('store.order.created', $order, ['module' => $key, 'billing' => $billing, 'amount' => $order->amount]);

        return $this->pay($company, $user, $order, $gw, $m->name);
    }

    /** Commande d'un pack : tous ses modules restant à acheter, au prix remisé, payés en une fois. */
    protected function orderPack(Company $company, User $user, string $key, string $billing, int $gatewayId, ?string $promo = null): array
    {
        $pack = collect($this->packs($company))->firstWhere('key', $key);
        if (! $pack) {
            throw new \DomainException('Pack introuvable.');
        }
        $offers = (array) $pack['offers'];
        if (! $pack['to_buy'] || ! isset($offers[$billing])) {
            throw new \DomainException($pack['to_buy'] ? 'Ce pack se prend en abonnement mensuel ou annuel.' : 'Tous les modules de ce pack sont déjà compris dans votre formule.');
        }
        $gw = PaymentGateway::store()->where('enabled', true)->find((int) $gatewayId);
        [$amount, $extra] = $this->withPromo($promo, $company, $user, 'module', $key, 'new', (float) $offers[$billing]);
        if (! $gw && $amount > 0) {
            throw new \DomainException('Moyen de paiement indisponible.');
        }
        $order = ModuleOrder::create([
            'company_id' => $company->id, 'user_id' => $user->id, 'module_key' => $key, 'billing' => $billing, 'items' => $pack['to_buy'],
            'amount' => $amount, 'currency' => $pack['currency'] ?: 'XOF', 'status' => 'pending', 'gateway_id' => $gw?->id, 'number' => 'TMP-'.uniqid(),
        ] + $extra);
        $order->update(['number' => sprintf('MOD-%s-%05d', now()->format('Y'), $order->id)]);
        ActivityLog::record('store.order.created', $order, ['module' => $key, 'modules' => $pack['to_buy'], 'billing' => $billing, 'amount' => $order->amount]);

        return $this->pay($company, $user, $order, $gw, $pack['name']);
    }

    /** Forfait de minutes Yomeet : valable un mois à partir du paiement. */
    protected function orderMinutes(Company $company, User $user, string $key, int $gatewayId): array
    {
        if (! $company->hasModule('visio')) {
            throw new \DomainException('Activez d\'abord le module Yomeet.');
        }
        $pack = collect(\App\Modules\Visio\VisioService::packs())->firstWhere('code', substr($key, 8));
        if (! $pack || (float) ($pack['price'] ?? 0) <= 0) {
            throw new \DomainException('Forfait de minutes introuvable.');
        }
        $gw = PaymentGateway::store()->where('enabled', true)->find($gatewayId);
        if (! $gw) {
            throw new \DomainException('Moyen de paiement indisponible.');
        }
        $order = ModuleOrder::create([
            'company_id' => $company->id, 'user_id' => $user->id, 'module_key' => $key, 'billing' => 'monthly', 'items' => ['minutes' => (int) $pack['minutes']],
            'amount' => (float) $pack['price'], 'currency' => 'XOF', 'status' => 'pending', 'gateway_id' => $gw->id, 'number' => 'TMP-'.uniqid(),
        ]);
        $order->update(['number' => sprintf('MOD-%s-%05d', now()->format('Y'), $order->id)]);
        ActivityLog::record('store.order.created', $order, ['module' => $key, 'amount' => $order->amount]);

        return $this->pay($company, $user, $order, $gw, 'Yomeet — '.number_format((int) $pack['minutes'], 0, ',', ' ').' minutes');
    }

    /** @return array{0: float, 1: array} montant après remise et champs de la commande */
    protected function withPromo(?string $code, Company $company, User $user, string $scope, string $target, string $kind, float $amount): array
    {
        $extra = ['base_amount' => $amount, 'kind' => $kind, 'discount' => 0, 'credit_used' => 0];
        if (trim((string) $code)) {
            $r = app(PromoService::class)->check($code, $company, $user, $scope, $target, $kind, $amount);
            $amount = max(0.0, $amount - $r['discount']);
            $extra = ['promo_code_id' => $r['promo']->id, 'discount' => $r['discount']] + $extra;
        } elseif (($welcome = Referrals::refereeDiscount($company, $amount)) > 0) {
            // Filleul : remise de bienvenue sur le premier achat
            $amount = max(0.0, $amount - $welcome);
            $extra['discount'] = $welcome;
        }
        if ($this->useCredit && $amount > 0) {
            $credit = min(Referrals::available($company), $amount);
            $amount -= $credit;
            $extra['credit_used'] = $credit;
        }

        return [$amount, $extra];
    }

    /** Paiements de formule déjà faits au mois (pour la remise de lancement). */
    public static function paidPlanMonths(Company $company): int
    {
        return ModuleOrder::where('company_id', $company->id)->where('module_key', 'like', 'plan:%')->where('billing', 'monthly')->where('status', 'paid')->count();
    }

    /** Formules proposées à cette société, avec remises applicables. */
    public function plans(Company $company): array
    {
        $paid = self::paidPlanMonths($company);

        return Plan::where('is_active', true)->orderBy('sort')->get()->map(fn (Plan $p) => $p->offer($paid) + [
            'current' => $company->plan_id === $p->id,
        ])->values()->all();
    }

    /** Abonnement à une formule (nouvelle ou renouvelée), au mois ou à l'année. */
    protected function orderPlan(Company $company, User $user, string $code, string $billing, int $gatewayId, ?string $promo): array
    {
        $plan = Plan::where('code', $code)->where('is_active', true)->first();
        if (! $plan) {
            throw new \DomainException('Formule introuvable.');
        }
        if (! in_array($billing, ['monthly', 'yearly'], true)) {
            throw new \DomainException('Une formule se prend au mois ou à l\'année.');
        }
        $offer = $plan->offer(self::paidPlanMonths($company));
        if ($offer['is_free']) {
            $company->update(['plan_id' => $plan->id, 'status' => 'active', 'trial_ends_at' => null, 'subscription_ends_at' => null]);
            $this->registry->flush();
            ActivityLog::record('store.plan.free', $company, ['plan' => $plan->code]);

            return ['order' => null, 'payment_url' => null, 'instructions' => null, 'activated' => true];
        }
        $gw = PaymentGateway::store()->where('enabled', true)->find((int) $gatewayId);
        $kind = $company->plan_id === $plan->id && $company->status === 'active' && $company->subscription_ends_at ? 'renew' : 'new';
        $base = $billing === 'yearly' ? $offer['yearly'] : $offer['monthly_now'];
        [$amount, $extra] = $this->withPromo($promo, $company, $user, 'plan', $plan->code, $kind, (float) $base);
        if (! $gw && $amount > 0) {
            throw new \DomainException('Moyen de paiement indisponible.');
        }
        $order = ModuleOrder::create([
            'company_id' => $company->id, 'user_id' => $user->id, 'module_key' => 'plan:'.$plan->code, 'billing' => $billing,
            'amount' => $amount, 'currency' => $plan->currency ?: 'XOF', 'status' => 'pending', 'gateway_id' => $gw?->id, 'number' => 'TMP-'.uniqid(),
        ] + $extra);
        $order->update(['number' => sprintf('ABO-%s-%05d', now()->format('Y'), $order->id)]);
        ActivityLog::record('store.order.created', $order, ['plan' => $plan->code, 'billing' => $billing, 'amount' => $order->amount, 'kind' => $kind]);

        return $this->pay($company, $user, $order, $gw, 'Formule '.$plan->name);
    }

    /**
     * Essai terminé ou abonnement échu (après le délai de grâce) : la société repasse sur la formule gratuite
     * au lieu d'être bloquée. Ses modules achetés à part restent actifs jusqu'à leur échéance.
     */
    public static function downgradeIfExpired(Company $company): bool
    {
        if (config('erp.mode') !== 'saas' || $company->status === 'suspended') {
            return false;
        }
        $trialOver = $company->status === 'trial' && $company->trial_ends_at && $company->trial_ends_at->isPast();
        $subOver = $company->subscription_ends_at && $company->subscription_ends_at->lt(now()->subDays((int) config('erp.subscription_grace_days', 7)));
        if (! $trialOver && ! $subOver) {
            return false;
        }
        $free = Plan::where('is_free', true)->where('is_active', true)->orderBy('sort')->first();
        if (! $free) {
            return false;
        }
        $from = $company->plan;
        $company->update(['plan_id' => $free->id, 'status' => 'active', 'trial_ends_at' => null, 'subscription_ends_at' => null]);
        app(\App\Support\ModuleRegistry::class)->flush();
        rescue(fn () => \App\Platform\SubscriptionLifecycle::downgraded($company->fresh(), $from, $free, $trialOver ? 'trial' : 'subscription'), null, false);
        ActivityLog::record('store.plan.downgraded', $company, ['to' => $free->code, 'reason' => $trialOver ? 'trial' : 'subscription']);

        return true;
    }

    protected function pay(Company $company, User $user, ModuleOrder $order, ?PaymentGateway $gw, string $name): array
    {
        $billing = $order->billing;
        // Entièrement payé par le code promo : activation immédiate
        if ((float) $order->amount <= 0) {
            $this->activate($order);

            return ['order' => $order->fresh(), 'payment_url' => null, 'instructions' => null, 'activated' => true];
        }
        $notify = fn () => rescue(fn () => \App\Platform\PlatformNotifier::orderPlaced($order->fresh()), null, false);
        if (! $gw->isOnline()) {
            $notify();

            return ['order' => $order, 'payment_url' => null, 'instructions' => $gw->instructions];
        }
        \Illuminate\Support\Facades\DB::afterCommit($notify);
        $tx = $this->payments->start($gw, 'module_order', (string) $order->id, $order->amount, $order->currency, $company->id, fn (PaymentTransaction $tx) => new PaymentContext(
            description: $name.' — '.ModuleOrder::BILLINGS[$billing].' ('.$company->name.')',
            returnUrl: url('/payments/'.$tx->id.'/return'),
            notifyUrl: url('/api/payments/'.$tx->id.'/notify'),
            cancelUrl: url('/payments/'.$tx->id.'/return?cancel=1'),
            customerName: $user->name, customerEmail: $user->email, customerPhone: $user->phone,
            merchantName: self::contact()['name'] ?? config('erp.name'),
        ));

        return ['order' => $order, 'payment_url' => $tx->payment_url, 'instructions' => null, 'transaction' => $tx->id];
    }

    /** Active le module payé (prolonge l'abonnement en cours le cas échéant). */
    public function activate(ModuleOrder $order, ?int $validatedBy = null): ModuleOrder
    {
        $wasPaid = ModuleOrder::whereKey($order->id)->value('status') === 'paid';
        $done = $this->activateOrder($order, $validatedBy);
        if (! $wasPaid && $done->status === 'paid') {
            // Client (notification + e-mail) et éditeur prévenus une fois la transaction validée
            DB::afterCommit(fn () => rescue(fn () => \App\Platform\PlatformNotifier::activated($done->fresh()), null, false));
        }

        return $done;
    }

    protected function activateOrder(ModuleOrder $order, ?int $validatedBy = null): ModuleOrder
    {
        return DB::transaction(function () use ($order, $validatedBy) {
            $order = ModuleOrder::lockForUpdate()->find($order->id);
            if ($order->status === 'paid') {
                return $order;
            }
            if (str_starts_with((string) $order->module_key, 'plan:')) {
                $plan = Plan::where('code', substr($order->module_key, 5))->first();
                $company = Company::find($order->company_id);
                $start = now();
                if ($company && $plan && $company->plan_id === $plan->id && $company->subscription_ends_at?->isFuture()) {
                    $start = $company->subscription_ends_at->copy();
                }
                $end = $order->billing === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonthNoOverflow();
                $company?->update(['plan_id' => $plan?->id ?? $company->plan_id, 'status' => 'active', 'trial_ends_at' => null, 'subscription_ends_at' => $end]);
                $order->update(['status' => 'paid', 'paid_at' => now(), 'period_start' => $start, 'period_end' => $end, 'validated_by' => $validatedBy]);
                app(PromoService::class)->redeem($order);
                Referrals::onPaid($order->fresh());
                $this->registry->flush();
                if ($company && $plan) {
                    $this->registry->ensureSetup($company->fresh(), $plan->effectiveModules());
                }
                ActivityLog::record('store.order.paid', $order, ['plan' => $plan?->code, 'until' => $end->toDateString()]);

                return $order->fresh();
            }
            if (str_starts_with((string) $order->module_key, 'minutes:')) {
                app(\App\Modules\Visio\VisioService::class)->addCredit($order->company_id, (int) ($order->items['minutes'] ?? 0), $order->id, $order->label());
                $order->update(['status' => 'paid', 'paid_at' => now(), 'period_start' => now(), 'period_end' => now()->addMonthNoOverflow(), 'validated_by' => $validatedBy]);
                ActivityLog::record('store.order.paid', $order, ['module' => $order->module_key]);

                return $order->fresh();
            }
            $keys = $order->isPack() ? (array) $order->items : [$order->module_key];
            $start = $end = null;
            foreach ($keys as $key) {
                [$s, $e] = $this->extend($order->company_id, $key, $order->billing);
                $start ??= $s;
                $end = $end && $e && $end->gt($e) ? $end : $e;
            }
            $order->update(['status' => 'paid', 'paid_at' => now(), 'period_start' => $start, 'period_end' => $end, 'validated_by' => $validatedBy]);
            app(PromoService::class)->redeem($order);
            Referrals::onPaid($order->fresh());
            $this->registry->flush();
            if ($company = Company::find($order->company_id)) {
                $this->registry->ensureSetup($company, $keys);
            } elseif ($lic = \App\Models\License::where('company_id', $order->company_id)->first()) {
                // Achat pour une installation sous licence : transmis à sa prochaine vérification
                $lic->log('module', ['modules' => $keys, 'until' => $end?->toDateString(), 'order' => $order->number]);
            }
            ActivityLog::record('store.order.paid', $order, ['module' => $order->module_key, 'until' => $end?->toDateString()]);

            return $order->fresh();
        });
    }

    /** Active ou prolonge un module acheté. @return array{0: \Carbon\Carbon, 1: ?\Carbon\Carbon} */
    protected function extend(int $companyId, string $key, string $billing): array
    {
        $cm = CompanyModule::firstOrNew(['company_id' => $companyId, 'module_key' => $key]);
        $start = now();
        if ($billing !== 'one_time' && $cm->exists && $cm->enabled && $cm->expires_at?->isFuture() && $cm->source === 'purchase') {
            $start = $cm->expires_at->copy();
        }
        $end = match ($billing) {
            'monthly' => $start->copy()->addMonthNoOverflow(),
            'yearly' => $start->copy()->addYear(),
            default => null,
        };
        $cm->fill(['enabled' => true, 'expires_at' => $end, 'source' => 'purchase', 'billing' => $billing]);
        $cm->expiry_notice = null; // nouveaux rappels pour la nouvelle échéance
        $cm->save();

        return [$start, $end];
    }

    public function startTrial(Company $company, string $key): CompanyModule
    {
        $m = Module::where('key', $key)->where('is_active', true)->firstOrFail();
        $row = collect($this->catalog($company))->firstWhere('key', $key);
        if (! $row || ! $row['can_trial']) {
            throw new \DomainException('L\'essai gratuit n\'est pas disponible pour ce module.');
        }
        if ($row['missing_dependencies']) {
            throw new \DomainException('Ce module nécessite d\'abord : '.collect($row['missing_dependencies'])->pluck('name')->implode(', ').'.');
        }
        $cm = CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $key], [
            'enabled' => true, 'expires_at' => Carbon::now()->addDays($m->trial_days), 'source' => 'trial', 'billing' => 'trial', 'trial_used' => true,
        ]);
        $this->registry->flush();
        if ($company->status !== 'license') {
            $this->registry->ensureSetup($company, [$key]);
        }
        ActivityLog::record('store.trial.started', $cm, ['module' => $key, 'days' => $m->trial_days]);

        return $cm;
    }

    /**
     * Rappels avant l'expiration des modules achetés ou à l'essai : à 7, 3 et 1 jour(s), puis le jour même.
     * Notification aux gestionnaires de la société et e-mail si un canal est configuré.
     */
    public function notifyExpiring(): int
    {
        $n = 0;
        $names = Module::pluck('name', 'key');
        foreach (CompanyModule::where('enabled', true)->whereIn('source', ['purchase', 'trial'])->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(7))->where('expires_at', '>', now()->subDay())->get() as $cm) {
            $days = max(0, (int) now()->startOfDay()->diffInDays($cm->expires_at->copy()->startOfDay(), false)); // jours calendaires
            $step = collect([0, 1, 3, 7])->first(fn ($t) => $days <= $t); // palier atteint : 7, 3, 1 ou 0 jour
            if ($step === null || ($cm->expiry_notice !== null && $cm->expiry_notice <= $step)) {
                continue;
            }
            $company = Company::find($cm->company_id);
            if (! $company || $company->status === 'suspended') {
                continue;
            }
            $cm->forceFill(['expiry_notice' => $step])->save();
            $name = $names[$cm->module_key] ?? $cm->module_key;
            $when = $step === 0 ? 'aujourd\'hui' : 'dans '.$days.' jour'.($days > 1 ? 's' : '');
            $title = ($cm->source === 'trial' ? 'Essai' : 'Abonnement').' « '.$name.' » : fin '.$when;
            $body = 'Le '.$cm->expires_at->format('d/m/Y').'. Renouvelez depuis la boutique des modules (paiement en ligne ou hors ligne) pour continuer sans interruption.';
            $admins = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('core.settings.manage'));
            \App\Models\AppNotification::send($company->id, $admins->pluck('id')->all(), 'store', $title, $body, 'store', $cm->module_key);
            if ($company->email) {
                rescue(fn () => app(\App\Messaging\MessagingService::class)->send($company, 'email', new \App\Messaging\Message(to: $company->email, subject: $title,
                    html: '<p>Bonjour,</p><p>'.e($title).'.</p><p>'.e($body).'</p><p><a href="'.url('/app/').'">Ouvrir Yomara ERP</a></p>', text: $title.".\n\n".$body."\n".url('/app/'), toName: $company->name), 'store'), null, false);
            }
            $n++;
        }

        return $n;
    }
}
