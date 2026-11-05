<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Module;
use App\Models\User;
use App\Services\Billing;

/**
 * Vue d'ensemble du super admin + utilisateurs connectés en temps réel.
 */
class PlatformController extends Controller
{
    public function overview(Billing $billing)
    {
        $window = now()->subSeconds(config('erp.presence_window'));
        $online = User::where('last_seen_at', '>', $window);

        return [
            'mode' => config('erp.mode'),
            'version' => config('erp.version'),
            'companies' => [
                'total' => Company::count(),
                'active' => Company::where('status', 'active')->count(),
                'trial' => Company::where('status', 'trial')->count(),
                'suspended' => Company::where('status', 'suspended')->count(),
            ],
            'users' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'online' => (clone $online)->count(),
                'devices_online' => \Laravel\Sanctum\PersonalAccessToken::where('last_used_at', '>', $window)->count(),
                'online_by_platform' => (clone $online)->selectRaw('COALESCE(last_platform, ?) as platform, COUNT(*) as total', ['inconnu'])
                    ->groupBy('platform')->pluck('total', 'platform'),
            ],
            'modules' => [
                'available' => Module::where('is_available', true)->count(),
                'catalog' => Module::count(),
            ],
            'mrr' => $billing->platformMrr(),
            'currency' => config('erp.defaults.currency'),
        ];
    }

    /** Console éditeur : connectés en temps réel, activité par jour et par mois, ventes, support. */
    public function analytics(\Illuminate\Http\Request $r)
    {
        return app(\App\Services\PlatformActivity::class)->analytics((int) $r->query('days', 30));
    }

    /** Liste des personnes connectées (fenêtre de présence configurable). */
    public function online()
    {
        $window = now()->subSeconds(config('erp.presence_window'));

        $users = User::with('company:id,name')
            ->where('last_seen_at', '>', $window)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'company' => $u->company?->name,
                'company_id' => $u->company_id,
                'platform' => $u->last_platform,
                'ip' => $u->last_ip,
                'last_seen_at' => $u->last_seen_at?->toIso8601String(),
                'is_super_admin' => $u->is_super_admin,
            ]);

        return [
            'count' => $users->count(),
            'window_seconds' => config('erp.presence_window'),
            'by_company' => $users->groupBy('company')->map->count(),
            'data' => $users,
        ];
    }
}
