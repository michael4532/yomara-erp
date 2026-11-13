<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Models\ModuleOrder;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing;
use App\Services\CompanySetup;
use App\Support\ModuleRegistry;
use Illuminate\Http\Request;

/**
 * Gestion des sociétés clientes par le super admin :
 * statut, formule, échéances, et modules activés/désactivés au cas par cas.
 */
class CompanyAdminController extends Controller
{
    public function index(Request $request, Billing $billing)
    {
        $window = now()->subSeconds(config('erp.presence_window'));
        $modules = Module::all()->keyBy('key');

        $query = Company::with(['plan', 'moduleOverrides'])
            ->withCount([
                'users',
                'users as online_count' => fn ($q) => $q->where('last_seen_at', '>', $window),
            ])
            ->orderBy('name');

        if ($q = $request->query('q')) {
            $query->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%"));
        }
        if ($s = $request->query('status')) {
            $query->where('status', $s);
        }

        $editor = \App\Services\SiteTraffic::editorCompany()?->id;

        return ['data' => $query->get()->map(fn (Company $c) => [
            'id' => $c->id,
            'is_editor' => $c->id === $editor,
            'is_store_demo' => (bool) data_get($c->settings, 'store_demo'),
            'name' => $c->name,
            'email' => $c->email,
            'phone' => $c->phone,
            'status' => $c->status,
            'plan' => $c->plan?->only(['id', 'code', 'name']),
            'users_count' => $c->users_count,
            'online_count' => $c->online_count,
            'trial_ends_at' => $c->trial_ends_at,
            'subscription_ends_at' => $c->subscription_ends_at,
            'monthly' => $c->id === $editor || data_get($c->settings, 'store_demo') ? 0 : $billing->companyMonthly($c, $modules)['total'],
            'created_at' => $c->created_at,
        ])];
    }

    public function store(Request $request, CompanySetup $setup)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'plan_id' => 'nullable|exists:plans,id',
            'status' => 'sometimes|in:active,trial,suspended',
            'owner_name' => 'required|string|max:255',
            'owner_email' => 'required|email|unique:users,email',
            'owner_password' => 'required|string|min:8',
        ]);

        ['company' => $company] = $setup->create(
            ['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null],
            ['name' => $data['owner_name'], 'email' => strtolower($data['owner_email']), 'password' => $data['owner_password']],
            isset($data['plan_id']) ? Plan::find($data['plan_id']) : null,
            $data['status'] ?? 'active',
        );
        ActivityLog::record('admin.company.created', $company);

        return response()->json(['data' => $company], 201);
    }

    public function show(int $id, Billing $billing)
    {
        $company = Company::with(['plan', 'moduleOverrides'])->findOrFail($id);

        return [
            'data' => $company,
            'billing' => $billing->companyMonthly($company),
            'users' => User::where('company_id', $id)->get(['id', 'name', 'email', 'is_active', 'last_seen_at', 'last_platform']),
        ];
    }

    public function update(Request $request, int $id, ModuleRegistry $registry)
    {
        $company = Company::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'status' => 'sometimes|in:active,trial,suspended',
            'plan_id' => 'nullable|exists:plans,id',
            'trial_ends_at' => 'nullable|date',
            'subscription_ends_at' => 'nullable|date',
        ]);
        $company->update($data);
        $registry->flush();

        // Suspension : déconnexion immédiate des utilisateurs de la société.
        if (($data['status'] ?? null) === 'suspended') {
            User::where('company_id', $company->id)->where('is_super_admin', false)->get()->each(fn ($u) => $u->tokens()->delete());
        }
        ActivityLog::record('admin.company.updated', $company, $data);

        return ['data' => $company->fresh('plan')];
    }

    /** État de chaque module pour cette société (plan, forçage, résultat). */
    public function modules(int $id, ModuleRegistry $registry)
    {
        // Inclut les sociétés « licence » (modules des installations chez les clients)
        $company = Company::withoutGlobalScope('real')->with(['plan', 'moduleOverrides'])->findOrFail($id);
        $enabled = $company->enabledModules();
        $overrides = $company->moduleOverrides->keyBy('module_key');
        $planModules = $company->plan?->modules ?? [];

        return ['data' => Module::orderBy('sort')->get()->map(fn (Module $m) => [
            'key' => $m->key,
            'name' => $m->name,
            'category' => $m->category,
            'is_core' => $m->is_core,
            'available' => $m->is_available,
            'globally_active' => $m->is_active,
            'in_plan' => in_array($m->key, $planModules, true),
            'override' => $overrides->get($m->key),
            'price_monthly' => $overrides->get($m->key)?->price_override ?? $m->price_monthly,
            'price_yearly' => (float) $m->price_yearly,
            'price_one_time' => (float) $m->price_one_time,
            'billing_mode' => $m->billing_mode,
            'enabled' => in_array($m->key, $enabled, true),
        ])];
    }

    /**
     * Active / désactive un module pour une société (avec échéance et prix spécifique optionnels).
     * reset=true supprime le forçage : on revient au comportement du plan.
     */
    public function setModule(Request $request, int $id, string $key, ModuleRegistry $registry)
    {
        $company = Company::withoutGlobalScope('real')->findOrFail($id);
        $module = Module::where('key', $key)->firstOrFail();
        abort_if($module->is_core, 422, 'Le module de base est toujours actif.');

        $data = $request->validate([
            'enabled' => 'required_without:reset|boolean',
            'expires_at' => 'nullable|date',
            'price_override' => 'nullable|numeric|min:0',
            'reset' => 'sometimes|boolean',
        ]);

        if ($data['reset'] ?? false) {
            CompanyModule::where(['company_id' => $id, 'module_key' => $key])->delete();
        } else {
            if ($data['enabled']) {
                $missing = array_diff($module->depends ?? [], $company->enabledModules());
                if ($missing) {
                    return response()->json(['message' => 'Activez d\'abord : '.implode(', ', $missing)], 422);
                }
            }
            CompanyModule::updateOrCreate(
                ['company_id' => $id, 'module_key' => $key],
                ['enabled' => $data['enabled'], 'expires_at' => $data['expires_at'] ?? null, 'price_override' => $data['price_override'] ?? null],
            );
        }

        $registry->flush();
        // Module activé par l'éditeur : rôles, paramètres et données de départ créés tout de suite
        if (! ($data['reset'] ?? false) && ($data['enabled'] ?? false) && $company->status !== 'license') {
            $registry->ensureSetup($company->fresh(), [$key]);
        }
        ActivityLog::record('admin.company.module', $company, ['module' => $key] + $data);
        if (! ($data['reset'] ?? false) && ($data['enabled'] ?? false)) {
            rescue(fn () => \App\Platform\PlatformNotifier::grantedByAdmin($company, $module->name, isset($data['expires_at']) ? \Illuminate\Support\Carbon::parse($data['expires_at'])->format('d/m/Y') : null), null, false);
        }

        return $this->modules($id, $registry);
    }

    /** Reçu PDF d'une commande payée (activation manuelle ou achat en ligne). */
    public function receipt(int $order)
    {
        $o = ModuleOrder::findOrFail($order);
        abort_unless($o->status === 'paid', 422, 'Cette commande n\'est pas payée.');

        return response(\App\Platform\OrderReceipt::pdf($o), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.\App\Platform\OrderReceipt::filename($o).'"']);
    }

    /**
     * Activation manuelle par l'éditeur : un module ou une formule pour N mois (0 = sans fin), sans paiement en ligne
     * (geste commercial, paiement reçu en direct…). Trace dans les commandes, client prévenu par notification et e-mail.
     */
    public function grant(Request $request, int $id, ModuleRegistry $registry)
    {
        $company = Company::withoutGlobalScope('real')->findOrFail($id);
        $d = $request->validate([
            'type' => 'required|in:module,plan',
            'key' => 'required_if:type,module|nullable|string|max:60',
            'plan_id' => 'required_if:type,plan|nullable|integer|exists:plans,id',
            'months' => 'required|integer|min:0|max:120',
            'amount' => 'nullable|numeric|min:0|max:100000000',
            'payment_method' => 'nullable|in:'.implode(',', array_keys(ModuleOrder::PAYMENT_METHODS)),
            'reference' => 'nullable|string|max:120',
            'send_receipt' => 'sometimes|boolean',
            'keep_price' => 'sometimes|boolean',
            'note' => 'nullable|string|max:300',
        ]);
        $months = (int) $d['months'];
        $amount = ($d['payment_method'] ?? null) === 'free' ? 0.0 : round((float) ($d['amount'] ?? 0), 2);
        $method = $d['payment_method'] ?? ($amount > 0 ? 'cash' : 'free');
        if ($d['type'] === 'plan') {
            $plan = Plan::findOrFail($d['plan_id']);
            $start = $company->plan_id === $plan->id && $company->subscription_ends_at?->isFuture() ? $company->subscription_ends_at->copy() : now();
            $end = $months > 0 ? $start->copy()->addMonthsNoOverflow($months) : null;
            $company->update(['plan_id' => $plan->id, 'status' => 'active', 'trial_ends_at' => null, 'subscription_ends_at' => $end]);
            $key = 'plan:'.$plan->code;
            $label = 'Formule '.$plan->name;
            $registry->flush();
            $registry->ensureSetup($company->fresh(), $plan->modules ?? []);
        } else {
            $module = Module::where('key', $d['key'])->firstOrFail();
            abort_if($module->is_core, 422, 'Le module de base est toujours actif.');
            $missing = array_diff($module->depends ?? [], $company->enabledModules());
            if ($missing) {
                return response()->json(['message' => 'Activez d\'abord : '.Module::whereIn('key', $missing)->pluck('name')->implode(', ')], 422);
            }
            $cm = CompanyModule::firstOrNew(['company_id' => $company->id, 'module_key' => $module->key]);
            $start = $cm->exists && $cm->enabled && $cm->expires_at?->isFuture() ? $cm->expires_at->copy() : now();
            $end = $months > 0 ? $start->copy()->addMonthsNoOverflow($months) : null;
            $cm->fill(['enabled' => true, 'expires_at' => $end, 'source' => 'purchase', 'billing' => $months === 0 ? 'one_time' : ($months % 12 === 0 ? 'yearly' : 'monthly')]);
            // Prix négocié gardé pour les renouvellements : prix mensuel de cette société
            if ($request->boolean('keep_price') && $months > 0 && $amount > 0) {
                $cm->price_override = round($amount / $months, 2);
            }
            $cm->expiry_notice = null;
            $cm->save();
            $key = $module->key;
            $label = $module->name;
            $registry->flush();
            if ($company->status !== 'license') {
                $registry->ensureSetup($company->fresh(), [$key]);
            }
        }
        $order = \App\Models\ModuleOrder::create([
            'company_id' => $company->id, 'user_id' => null, 'module_key' => $key, 'billing' => $months === 0 ? 'one_time' : ($months % 12 === 0 ? 'yearly' : 'monthly'),
            'amount' => $amount, 'base_amount' => $amount, 'currency' => config('erp.defaults.currency', 'XOF'), 'status' => 'paid', 'paid_at' => now(),
            'payment_method' => $method, 'payer_reference' => $d['reference'] ?? null,
            'period_start' => $start, 'period_end' => $end, 'validated_by' => $request->user()->id, 'kind' => 'new', 'number' => 'TMP-'.uniqid(),
            'notes' => trim('Activation manuelle par '.$request->user()->name.'. '.($d['note'] ?? '')),
        ]);
        $order->update(['number' => sprintf('MAN-%s-%05d', now()->format('Y'), $order->id)]);
        ActivityLog::record('admin.company.grant', $company, ['what' => $key, 'months' => $months, 'order' => $order->number]);
        // Le client reçoit l'e-mail de confirmation avec le reçu PDF (sauf si l'éditeur ne le veut pas)
        if ($request->boolean('send_receipt', true)) {
            rescue(fn () => \App\Platform\PlatformNotifier::grantedWithReceipt($company, $order->fresh()), null, false);
        } else {
            rescue(fn () => \App\Platform\PlatformNotifier::grantedByAdmin($company, $label, $end?->format('d/m/Y')), null, false);
        }

        return ['message' => $label.' activé pour '.$company->name.($end ? ' jusqu\'au '.$end->format('d/m/Y') : ' sans date de fin').($request->boolean('send_receipt', true) ? '. Reçu envoyé au client.' : '.'), 'order' => ['id' => $order->id, 'number' => $order->number], 'modules' => $this->modules($id, $registry)['data'], 'company' => $company->fresh('plan')];
    }
}
