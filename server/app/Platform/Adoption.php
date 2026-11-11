<?php

namespace App\Platform;

use App\Models\Company;
use App\Models\Module;
use App\Support\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord d'adoption (console éditeur) : qui utilise vraiment le logiciel, quels modules,
 * qui relancer et pourquoi.
 *
 * Sources, sans rien demander aux clients :
 * - platform_activity : jours d'activité par utilisateur (écrits par la présence) ;
 * - sync_changes : chaque fiche créée ou modifiée, par entité → module ;
 * - module_usage : jours où un écran du module a appelé le serveur (une ligne par société, module et jour) ;
 * - activity_logs : connexions.
 */
class Adoption
{
    public const WINDOW = 30;

    /** Modules toujours présents : pas comptés dans l'adoption. */
    protected const BASE = ['core'];

    /** Appelé par le middleware des modules : une écriture par société, module et jour au plus. */
    public static function touch(int $companyId, string $module): void
    {
        $day = now()->toDateString();
        $key = "mu:$companyId:$module:$day";
        try {
            if (Cache::add($key, 1, 93600)) {
                DB::table('module_usage')->insertOrIgnore(['company_id' => $companyId, 'module_key' => $module, 'day' => $day]);
            }
        } catch (\Throwable) {
            // statistiques : jamais bloquant
        }
    }

    public function report(): array
    {
        $now = now();
        $from = $now->copy()->subDays(self::WINDOW)->startOfDay();
        $from7 = $now->copy()->subDays(7)->startOfDay();
        $editor = \App\Services\SiteTraffic::editorCompany()?->id;
        $companies = Company::withoutGlobalScopes()->with('plan:id,name,code')->when($editor, fn ($q) => $q->where('id', '!=', $editor))->get();
        $ids = $companies->pluck('id')->all();
        if (! $ids) {
            return ['window_days' => self::WINDOW, 'kpis' => $this->kpis(collect()), 'companies' => [], 'modules' => []];
        }

        // Entité synchronisée → module
        $entityModule = [];
        foreach (app(ModuleRegistry::class)->manifests() as $key => $m) {
            foreach (array_keys($m['sync'] ?? []) as $entity) {
                $entityModule[$entity] = $key;
            }
        }
        $names = Module::pluck('name', 'key')->all();
        $prices = Module::pluck('price_monthly', 'key')->all();

        $users = DB::table('users')->whereIn('company_id', $ids)->where('is_active', true)
            ->selectRaw('company_id, count(*) as n')->groupBy('company_id')->pluck('n', 'company_id');
        $act = DB::table('platform_activity')->whereIn('company_id', $ids)->where('day', '>=', $from->toDateString())
            ->selectRaw('company_id, count(distinct user_id) as users30, max(last_at) as last_at, count(distinct day) as days')->groupBy('company_id')->get()->keyBy('company_id');
        $act7 = DB::table('platform_activity')->whereIn('company_id', $ids)->where('day', '>=', $from7->toDateString())
            ->selectRaw('company_id, count(distinct user_id) as n')->groupBy('company_id')->pluck('n', 'company_id');
        // Dernière activité, même ancienne (au-delà de la fenêtre)
        $lastEver = DB::table('platform_activity')->whereIn('company_id', $ids)->selectRaw('company_id, max(last_at) as at')->groupBy('company_id')->pluck('at', 'company_id');
        $changes = DB::table('sync_changes')->whereIn('company_id', $ids)->where('created_at', '>=', $from)
            ->selectRaw('company_id, entity, count(*) as n')->groupBy('company_id', 'entity')->get()->groupBy('company_id');
        $screens = DB::table('module_usage')->whereIn('company_id', $ids)->where('day', '>=', $from->toDateString())
            ->selectRaw('company_id, module_key, count(*) as days')->groupBy('company_id', 'module_key')->get()->groupBy('company_id');
        $logins = DB::table('activity_logs')->whereIn('company_id', $ids)->where('action', 'auth.login')->where('created_at', '>=', $from)
            ->selectRaw('company_id, count(*) as n')->groupBy('company_id')->pluck('n', 'company_id');
        $enabled = DB::table('company_modules')->whereIn('company_id', $ids)->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->get(['company_id', 'module_key', 'created_at', 'source', 'billing'])->groupBy('company_id');

        $rows = [];
        $moduleStats = [];
        foreach ($companies as $c) {
            $a = $act->get($c->id);
            $used = [];
            foreach ($changes->get($c->id, collect()) as $ch) {
                $k = $entityModule[$ch->entity] ?? null;
                if ($k) {
                    $used[$k] = ($used[$k] ?? 0) + (int) $ch->n;
                }
            }
            foreach ($screens->get($c->id, collect()) as $s) {
                $used[$s->module_key] = ($used[$s->module_key] ?? 0) + (int) $s->days;
            }
            $mods = $enabled->get($c->id, collect())->reject(fn ($m) => in_array($m->module_key, self::BASE, true));
            $usedKeys = array_values(array_diff(array_keys($used), self::BASE));
            // Modules ouverts à la société (forfait + achats), pas seulement ceux achetés à part
            $enabledKeys = array_values(array_diff(rescue(fn () => $c->enabledModules(), $mods->pluck('module_key')->all(), false), self::BASE));
            // Modules payés, actifs depuis plus de 14 jours, jamais ouverts sur la période
            $idle = $mods->filter(fn ($m) => ! isset($used[$m->module_key]) && (float) ($prices[$m->module_key] ?? 0) > 0
                && $m->source !== 'trial' && Carbon::parse($m->created_at)->lt($now->copy()->subDays(14)))
                ->map(fn ($m) => ['key' => $m->module_key, 'name' => $names[$m->module_key] ?? $m->module_key])->values()->all();
            foreach ($enabledKeys as $k) {
                $moduleStats[$k]['enabled'] = ($moduleStats[$k]['enabled'] ?? 0) + 1;
                if (isset($used[$k])) {
                    $moduleStats[$k]['used'] = ($moduleStats[$k]['used'] ?? 0) + 1;
                }
            }

            $last = $lastEver->get($c->id) ? Carbon::parse($lastEver->get($c->id)) : null;
            $idleDays = $last ? (int) $last->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()) : null;
            $age = (int) Carbon::parse($c->created_at)->startOfDay()->diffInDays($now->copy()->startOfDay());
            $totalUsers = (int) ($users[$c->id] ?? 0);
            $active7 = (int) ($act7[$c->id] ?? 0);
            $trialLeft = $c->status === 'trial' && $c->trial_ends_at ? (int) $now->copy()->startOfDay()->diffInDays(Carbon::parse($c->trial_ends_at)->startOfDay(), false) : null;
            // Largeur d'usage : 5 modules utilisés ou plus = note pleine (un forfait ouvre des dizaines de modules)
            $breadth = min(1, count($usedKeys) / 5);
            $recency = $idleDays === null ? 0 : max(0, 1 - $idleDays / self::WINDOW);
            $people = $totalUsers ? min(1, ((int) ($a->users30 ?? 0)) / $totalUsers) : 0;
            $score = (int) round(50 * $recency + 30 * $breadth + 20 * $people);

            $status = match (true) {
                $c->status === 'suspended' => 'suspended',
                $idleDays === null && $age < 7 => 'new',
                $idleDays === null => 'never',
                $idleDays > 14 => 'risk',
                $idleDays > 7 => 'follow_up',
                default => 'active',
            };
            $rows[] = [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'city' => $c->city,
                'plan' => $c->plan?->name,
                'company_status' => $c->status,
                'created_at' => Carbon::parse($c->created_at)->toIso8601String(),
                'age_days' => $age,
                'trial_days_left' => $trialLeft,
                'last_activity_at' => $last?->toIso8601String(),
                'idle_days' => $idleDays,
                'users' => $totalUsers,
                'active_users_7d' => $active7,
                'active_users_30d' => (int) ($a->users30 ?? 0),
                'active_days_30d' => (int) ($a->days ?? 0),
                'logins_30d' => (int) ($logins[$c->id] ?? 0),
                'records_30d' => (int) $changes->get($c->id, collect())->sum('n'),
                'modules_enabled' => count($enabledKeys),
                'modules_used' => collect($usedKeys)->map(fn ($k) => ['key' => $k, 'name' => $names[$k] ?? $k, 'activity' => $used[$k]])->sortByDesc('activity')->values()->all(),
                'idle_paid_modules' => $idle,
                'score' => $score,
                'status' => $status,
                'advice' => $this->advice($status, $age, $idleDays, $trialLeft, $idle, $usedKeys, $totalUsers, $active7),
            ];
        }
        $rows = collect($rows)->sortBy([['score', 'asc'], ['name', 'asc']])->values();

        $modules = collect($moduleStats)->map(fn ($s, $k) => [
            'key' => $k, 'name' => $names[$k] ?? $k, 'enabled' => $s['enabled'] ?? 0, 'used' => $s['used'] ?? 0,
            'rate' => ($s['enabled'] ?? 0) ? round(100 * ($s['used'] ?? 0) / $s['enabled']) : 0,
        ])->sortByDesc('enabled')->values()->all();

        return ['window_days' => self::WINDOW, 'generated_at' => $now->toIso8601String(), 'kpis' => $this->kpis($rows), 'companies' => $rows->all(), 'modules' => $modules];
    }

    protected function kpis($rows): array
    {
        $count = fn (string $s) => $rows->where('status', $s)->count();

        return [
            'companies' => $rows->count(),
            'active' => $count('active'),
            'follow_up' => $count('follow_up'),
            'risk' => $count('risk'),
            'never' => $count('never') + $count('new'),
            'trial_ending' => $rows->filter(fn ($r) => $r['trial_days_left'] !== null && $r['trial_days_left'] >= 0 && $r['trial_days_left'] <= 5)->count(),
            'idle_paid_modules' => $rows->sum(fn ($r) => count($r['idle_paid_modules'])),
            'avg_score' => $rows->count() ? (int) round($rows->avg('score')) : 0,
        ];
    }

    /** Action conseillée, en une phrase. */
    protected function advice(string $status, int $age, ?int $idle, ?int $trialLeft, array $idleModules, array $used, int $users, int $active7): string
    {
        if ($status === 'suspended') {
            return 'Compte suspendu : relancer pour le renouvellement de l\'abonnement.';
        }
        if ($trialLeft !== null && $trialLeft >= 0 && $trialLeft <= 5) {
            return $trialLeft === 0 ? 'L\'essai se termine aujourd\'hui : appeler pour l\'abonnement.' : "L'essai se termine dans $trialLeft jour(s) : appeler pour l'abonnement.";
        }
        if ($status === 'new') {
            return "Inscrit il y a $age jour(s), pas encore d'activité : proposer une prise en main de 15 minutes.";
        }
        if ($status === 'never') {
            return 'Jamais utilisé depuis l\'inscription : appeler pour comprendre ce qui bloque.';
        }
        if ($status === 'risk') {
            return "Aucune activité depuis $idle jours : appeler, risque de départ.";
        }
        if ($status === 'follow_up') {
            return "Pas d'activité depuis $idle jours : envoyer un message de relance.";
        }
        if ($idleModules) {
            $n = $idleModules[0]['name'];

            return count($idleModules) > 1
                ? "« $n » et ".(count($idleModules) - 1).' autre(s) module(s) payés mais pas utilisés : proposer une formation.'
                : "Module « $n » payé mais pas utilisé : proposer une formation.";
        }
        if ($users > 1 && $active7 <= 1) {
            return 'Une seule personne utilise le logiciel : inviter l\'équipe à s\'en servir.';
        }
        if (count($used) <= 1) {
            return 'Utilise un seul module : présenter les modules utiles à son métier.';
        }

        return 'Client actif : rien à faire, ou proposer un module complémentaire.';
    }
}
