<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ModuleOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques d'utilisation de la plateforme pour la console éditeur :
 * activité quotidienne par utilisateur (écrite au plus toutes les 30 s par la présence),
 * échantillons des connectés toutes les 5 minutes, ventes de modules.
 */
class PlatformActivity
{
    public static function record(User $user, ?string $platform): void
    {
        try {
            $day = now()->toDateString();
            $n = DB::table('platform_activity')->where('day', $day)->where('user_id', $user->id)
                ->update(['last_at' => now(), 'hits' => DB::raw('hits + 1'), 'platform' => $platform]);
            if ($n === 0) {
                DB::table('platform_activity')->insertOrIgnore(['day' => $day, 'user_id' => $user->id, 'company_id' => $user->company_id,
                    'platform' => $platform, 'first_at' => now(), 'last_at' => now(), 'hits' => 1]);
            }
        } catch (\Throwable) {
            // statistiques : jamais bloquant
        }
    }

    /** Planifié toutes les 5 minutes : nombre de connectés, pour la courbe en temps réel. */
    public static function sample(): array
    {
        $window = now()->subSeconds(config('erp.presence_window'));
        $q = User::where('last_seen_at', '>', $window);
        $row = ['at' => now(), 'users' => (clone $q)->count(), 'companies' => (clone $q)->distinct()->count('company_id')];
        DB::table('platform_online_samples')->insert($row);
        DB::table('platform_online_samples')->where('at', '<', now()->subDays(60))->delete();

        return $row;
    }

    public static function productName(string $key): string
    {
        if (str_starts_with($key, 'minutes:')) {
            return 'Minutes Yomeet';
        }
        if (str_starts_with($key, 'pack:')) {
            return \App\Support\PricingGrid::PACKS[substr($key, 5)]['name'] ?? 'Pack';
        }

        return (string) (\App\Models\Module::where('key', $key)->value('name') ?? $key);
    }

    public function analytics(int $days = 30): array
    {
        $days = max(7, min(365, $days));
        $from = now()->subDays($days - 1)->startOfDay();
        $window = now()->subSeconds(config('erp.presence_window'));
        $online = User::with('company:id,name')->where('last_seen_at', '>', $window)->orderByDesc('last_seen_at')->limit(200)->get();
        $every = max(1, (int) config('erp.presence_write_every'));

        // Par jour
        $act = DB::table('platform_activity')->where('day', '>=', $from->toDateString())
            ->selectRaw('day, count(*) as users, count(distinct company_id) as companies, sum(hits) as hits')->groupBy('day')->get()->keyBy(fn ($r) => substr((string) $r->day, 0, 10));
        $paid = ModuleOrder::where('status', 'paid')->where('paid_at', '>=', $from)->get(['amount', 'paid_at', 'module_key', 'company_id']);
        $signups = Company::where('created_at', '>=', $from)->get(['created_at']);
        $daily = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addDay()) {
            $k = $d->toDateString();
            $a = $act->get($k);
            $daily[] = [
                'day' => $k,
                'users' => (int) ($a->users ?? 0),
                'companies' => (int) ($a->companies ?? 0),
                'hours' => round(((int) ($a->hits ?? 0)) * $every / 3600, 1),
                'revenue' => round($paid->filter(fn ($o) => $o->paid_at->toDateString() === $k)->sum('amount'), 2),
                'signups' => $signups->filter(fn ($c) => $c->created_at->toDateString() === $k)->count(),
            ];
        }

        // Par mois (12 mois)
        $m0 = now()->subMonths(11)->startOfMonth();
        $monthly = [];
        for ($m = $m0->copy(); $m->lte(now()); $m->addMonth()) {
            $end = $m->copy()->endOfMonth();
            $monthly[] = [
                'month' => $m->format('Y-m'),
                'users' => DB::table('platform_activity')->whereBetween('day', [$m->toDateString(), $end->toDateString()])->distinct()->count('user_id'),
                'companies' => DB::table('platform_activity')->whereBetween('day', [$m->toDateString(), $end->toDateString()])->distinct()->count('company_id'),
                'revenue' => round((float) ModuleOrder::where('status', 'paid')->whereBetween('paid_at', [$m, $end])->sum('amount'), 2),
                'signups' => Company::whereBetween('created_at', [$m, $end])->count(),
            ];
        }

        // Courbe des connectés (dernières 24 h)
        $samples = DB::table('platform_online_samples')->where('at', '>=', now()->subDay())->orderBy('at')->get(['at', 'users', 'companies'])
            ->map(fn ($r) => ['at' => Carbon::parse($r->at)->toIso8601String(), 'users' => (int) $r->users, 'companies' => (int) $r->companies])->values();

        $today = DB::table('platform_activity')->where('day', now()->toDateString());
        $monthStart = now()->startOfMonth();
        $prevStart = now()->subMonthNoOverflow()->startOfMonth();
        $top = ModuleOrder::where('status', 'paid')->where('paid_at', '>=', now()->subDays(90))->selectRaw('module_key, count(*) as orders, sum(amount) as revenue')
            ->groupBy('module_key')->orderByDesc('revenue')->limit(8)->get()->map(fn ($r) => ['key' => $r->module_key, 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2),
                'name' => self::productName((string) $r->module_key)]);

        $editor = \App\Services\SiteTraffic::editorCompany()?->id;
        $clients = fn () => Company::query()->when($editor, fn ($q) => $q->where('id', '!=', $editor)); // sociétés clientes (hors éditeur)

        return [
            'currency' => config('erp.defaults.currency'),
            'now' => [
                'users' => $online->count(),
                'companies' => $online->pluck('company_id')->unique()->count(),
                'by_platform' => $online->groupBy(fn ($u) => $u->last_platform ?: 'inconnu')->map->count(),
                'by_company' => $online->groupBy(fn ($u) => $u->company?->name ?? '—')->map->count()->sortDesc()->take(10),
                'list' => $online->take(50)->map(fn ($u) => ['name' => $u->name, 'company' => $u->company?->name, 'platform' => $u->last_platform, 'last_seen_at' => $u->last_seen_at?->toIso8601String()])->values(),
                'peak_24h' => (int) ($samples->max('users') ?? 0),
            ],
            'today' => [
                'users' => (clone $today)->count(),
                'companies' => (clone $today)->distinct()->count('company_id'),
                'hours' => round(((int) (clone $today)->sum('hits')) * $every / 3600, 1),
            ],
            'revenue' => [
                'month' => round((float) ModuleOrder::where('status', 'paid')->where('paid_at', '>=', $monthStart)->sum('amount'), 2),
                'previous_month' => round((float) ModuleOrder::where('status', 'paid')->whereBetween('paid_at', [$prevStart, $monthStart])->sum('amount'), 2),
                'mrr' => app(Billing::class)->platformMrr(),
                'pending_orders' => ModuleOrder::whereIn('status', ['pending', 'awaiting_validation'])->count(),
                'to_validate' => ModuleOrder::where('status', 'awaiting_validation')->count(),
            ],
            'companies' => [
                'total' => $clients()->count(),
                'active' => $clients()->where('status', 'active')->count(),
                'trial' => $clients()->where('status', 'trial')->count(),
                'new_month' => $clients()->where('created_at', '>=', $monthStart)->count(),
            ],
            'licenses' => self::licenseStats($monthStart),
            'platforms_30d' => User::where('last_seen_at', '>=', now()->subDays(30))->get(['last_platform'])
                ->groupBy(fn ($u) => $u->last_platform ?: 'web')->map->count(),
            'support' => [
                'open' => DB::table('support_conversations')->where('status', '!=', 'closed')->count(),
                'unread' => (int) DB::table('support_conversations')->sum('unread_platform'),
            ],
            'top_modules' => $top,
            'daily' => $daily,
            'monthly' => $monthly,
            'online_series' => $samples,
        ];
    }

    /** Installations sous licence comparées aux abonnés en ligne. */
    public static function licenseStats($monthStart): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('licenses')) {
            return [];
        }
        $all = \App\Models\License::all();
        $ids = $all->pluck('company_id');
        $mods = \App\Models\CompanyModule::whereIn('company_id', $ids)->where('enabled', true)->where('source', 'purchase')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        return [
            'total' => $all->count(),
            'active' => $all->where('status', 'active')->count(),
            'unused' => $all->where('status', 'unused')->count(),
            'online' => $all->filter->isOnline()->count(),
            'new_month' => $all->where('created_at', '>=', $monthStart)->count(),
            'owners' => $all->pluck('owner_company_id')->unique()->count(),
            'users' => (int) $all->sum('users_count'),
            'by_target' => $all->groupBy('target')->map->count(),
            'paying' => (clone $mods)->distinct()->count('company_id'),
            'modules_running' => (clone $mods)->count(),
            'revenue_month' => round((float) ModuleOrder::whereIn('company_id', $ids)->where('status', 'paid')->where('paid_at', '>=', $monthStart)->sum('amount'), 2),
            'saas_subscriptions' => Company::where('status', 'active')->where(fn ($q) => $q->whereNull('subscription_ends_at')->orWhere('subscription_ends_at', '>', now()))->count(),
        ];
    }
}
