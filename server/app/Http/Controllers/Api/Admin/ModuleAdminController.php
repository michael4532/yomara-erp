<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Support\ModuleRegistry;
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

    /** Réinscrit les modules du code dans le catalogue (après une mise à jour). */
    public function refresh(ModuleRegistry $registry)
    {
        $registry->syncToDatabase();

        return $this->index();
    }
}
