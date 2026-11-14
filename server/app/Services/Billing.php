<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Module;

/**
 * Calcul de ce que paie une société chaque mois :
 *   prix du plan + modules activés hors plan (prix du module, ou prix spécifique
 *   fixé par le super admin pour cette société).
 */
class Billing
{
    public function companyMonthly(Company $company, ?\Illuminate\Support\Collection $modules = null): array
    {
        $modules ??= Module::all()->keyBy('key');
        $planModules = \App\Models\Plan::modulesFor($company->plan);
        $overrides = $company->moduleOverrides->keyBy('module_key');
        $lines = [];
        $total = 0.0;

        if ($company->plan) {
            $lines[] = ['label' => 'Formule '.$company->plan->name, 'amount' => $company->plan->price_monthly];
            $total += $company->plan->price_monthly;
        }

        foreach ($company->enabledModules() as $key) {
            $m = $modules->get($key);
            if (! $m || $m->is_core || in_array($key, $planModules, true)) {
                continue;
            }
            if (config('erp.mode') !== 'saas' && ! $overrides->has($key)) {
                continue; // installation unique : modules inclus dans la licence
            }
            $o = $overrides->get($key);
            if ($o?->billing === 'one_time' || $o?->billing === 'trial' || (! $o && $m->billing_mode === 'free')) {
                continue; // acheté définitivement, en essai ou gratuit : rien de mensuel
            }
            if ($o?->billing === 'yearly') {
                $price = round($m->price_yearly / 12, 2);
                $lines[] = ['label' => $m->name.' (annuel, ramené au mois)', 'amount' => $price, 'module' => $key];
            } else {
                $price = $o?->price_override ?? $m->price_monthly;
                $lines[] = ['label' => $m->name, 'amount' => (float) $price, 'module' => $key];
            }
            $total += $price;
        }

        return ['lines' => $lines, 'total' => round($total, 2)];
    }

    public function platformMrr(): float
    {
        $modules = Module::all()->keyBy('key');
        $sum = 0.0;
        $editor = \App\Services\SiteTraffic::editorCompany()?->id; // la société de l'éditeur n'est pas une cliente
        Company::with(['plan', 'moduleOverrides'])->where('status', 'active')->when($editor, fn ($q) => $q->where('id', '!=', $editor))->each(function (Company $c) use (&$sum, $modules) {
            $sum += $this->companyMonthly($c, $modules)['total'];
        });

        return round($sum, 2);
    }
}
