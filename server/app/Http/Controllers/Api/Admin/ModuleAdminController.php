<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Support\ModuleRegistry;
use App\Support\PricingGrid;
use Illuminate\Http\Request;

/**
 * Catalogue des modules : activation globale et tarifs (super admin).
 */
class ModuleAdminController extends Controller
{
    public function index()
    {
        $usage = CompanyModule::where('enabled', true)->selectRaw('module_key, COUNT(*) as total')->groupBy('module_key')->pluck('total', 'module_key');

        return ['data' => Module::orderBy('sort')->get()->map(fn (Module $m) => $m->toArray() + [
            'companies_with_override' => (int) ($usage[$m->key] ?? 0),
            'screenshot_urls' => array_map(fn ($s) => \App\Store\ModuleStore::url($s), $m->screenshots ?? []),
        ]), 'billing_modes' => Module::BILLING_MODES];
    }

    public function update(Request $request, ModuleRegistry $registry, string $key)
    {
        $module = Module::where('key', $key)->firstOrFail();
        $data = $request->validate([
            'is_active' => 'sometimes|boolean',
            'price_monthly' => 'sometimes|numeric|min:0',
            'price_yearly' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'billing_mode' => 'sometimes|in:free,one_time,subscription,both',
            'price_one_time' => 'sometimes|numeric|min:0',
            'trial_days' => 'sometimes|integer|min:0|max:365',
            'in_store' => 'sometimes|boolean',
            'tagline' => 'nullable|string|max:255',
            'long_description' => 'nullable|string|max:20000',
            'features' => 'nullable|array|max:30',
            'features.*' => 'string|max:255',
            'demo_url' => 'nullable|url|max:255',
        ]);

        if ($module->is_core && array_key_exists('is_active', $data) && ! $data['is_active']) {
            return response()->json(['message' => 'Le module de base ne peut pas être désactivé.'], 422);
        }

        $module->update($data);
        $registry->flush();
        ActivityLog::record('admin.module.updated', $module, $data);

        return ['data' => $module];
    }

    /** Même tarif pour tous les modules payants (annuel et licence calculés si non fournis). */
    public function bulkPrice(Request $request, ModuleRegistry $registry)
    {
        $v = $request->validate(['price_monthly' => 'required|numeric|min:1', 'price_yearly' => 'nullable|numeric|min:0', 'price_one_time' => 'nullable|numeric|min:0']);
        $m = (float) $v['price_monthly'];
        $n = Module::where('is_core', false)->where('billing_mode', '!=', 'free')->update([
            'price_monthly' => $m, 'price_yearly' => $v['price_yearly'] ?? $m * 10, 'price_one_time' => $v['price_one_time'] ?? $m * 24,
        ]);
        $registry->flush();
        ActivityLog::record('admin.module.bulk_price', null, $v + ['modules' => $n]);

        return $this->index() + ['updated' => $n];
    }

    /**
     * Aligne les tarifs sur les formules : un module inclus dans une formule gratuite devient gratuit ;
     * un module vendu sans prix reçoit le prix de son palier (Essentiel, Standard, Premium ; annuel = 10 mois,
     * achat définitif = 24 mois) ; les modules Standard et Premium sont ramenés au prix de leur palier.
     * Les autres modules déjà tarifés et les tarifs accordés à une société ne bougent pas.
     * « apply » absent : aperçu seulement.
     */
    public function alignPrices(Request $request, ModuleRegistry $registry)
    {
        $free = \App\Models\Plan::where('is_active', true)->where(fn ($q) => $q->where('is_free', true)->orWhere('price_monthly', '<=', 0))->get()
            ->flatMap(fn ($p) => (array) $p->modules)->unique()->values()->all();
        $defaults = config('erp_store.defaults', []);
        $toFree = $toPrice = [];
        foreach (Module::where('is_core', false)->orderBy('sort')->get() as $m) {
            if (in_array($m->key, $free, true)) {
                if ($m->billing_mode !== 'free' || (float) $m->price_monthly > 0 || (float) $m->price_one_time > 0) {
                    $toFree[] = $m;
                }
            } elseif ($m->billing_mode === 'free' || (float) $m->price_monthly <= 0) {
                $toPrice[] = $m;
            } elseif (($tier = PricingGrid::tierOf($m->key)) !== 'essentiel' && (float) $m->price_monthly !== (float) PricingGrid::TIERS[$tier]['price']) {
                // Modules coûteux pour la plateforme (SMS, WhatsApp, e-mailing…, visio, IA) : toujours au prix de leur palier
                $toPrice[] = $m;
            }
        }
        $preview = [
            'to_free' => array_map(fn ($m) => ['key' => $m->key, 'name' => $m->name], $toFree),
            'to_price' => array_map(function ($m) {
                $tier = PricingGrid::tierOf($m->key);

                return ['key' => $m->key, 'name' => $m->name, 'tier' => PricingGrid::TIERS[$tier]['label'], 'price_monthly' => PricingGrid::TIERS[$tier]['price']];
            }, $toPrice),
        ];
        if (! $request->boolean('apply')) {
            return $preview;
        }
        foreach ($toFree as $m) {
            $m->update(['billing_mode' => 'free', 'price_monthly' => 0, 'price_yearly' => 0, 'price_one_time' => 0]);
        }
        foreach ($toPrice as $m) {
            $p = (float) PricingGrid::TIERS[PricingGrid::tierOf($m->key)]['price'];
            $m->update([
                'billing_mode' => $defaults['billing_mode'] ?? 'both', 'price_monthly' => $p, 'price_yearly' => $p * 10,
                'price_one_time' => $p * ($defaults['one_time_months'] ?? 24), 'trial_days' => $m->trial_days ?: ($defaults['trial_days'] ?? 14),
            ]);
        }
        $registry->flush();
        ActivityLog::record('admin.module.align_prices', null, ['free' => count($toFree), 'priced' => count($toPrice)]);

        return $this->index() + ['free' => count($toFree), 'priced' => count($toPrice)];
    }

    /** Réinscrit les modules du code dans le catalogue (après une mise à jour). */
    public function refresh(ModuleRegistry $registry)
    {
        $registry->syncToDatabase();

        return $this->index();
    }
}
