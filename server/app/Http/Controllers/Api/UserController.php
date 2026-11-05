<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    protected function query()
    {
        return User::where('company_id', app('tenant')->id);
    }

    public function index()
    {
        return ['data' => $this->query()->with('role:id,name')->orderBy('name')->get()->map(fn (User $u) => $this->row($u))];
    }

    public function store(Request $request)
    {
        $company = app('tenant');
        $max = $company->plan?->max_users;
        if ($max && $this->query()->where('is_active', true)->count() >= $max) {
            return response()->json(['message' => "Votre formule est limitée à $max utilisateurs."], 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:50',
            'password' => 'required|string|min:8',
            'role_id' => ['required', Rule::exists('roles', 'id')->where('company_id', $company->id)],
            'is_active' => 'sometimes|boolean',
            'branch_ids' => 'sometimes|array',
            'branch_ids.*' => 'uuid',
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company->id)],
            'job_title' => 'nullable|string|max:120',
            'modules' => 'sometimes|array',
            'modules.*' => 'string|max:50',
        ]);
        $modules = $data['modules'] ?? null;
        unset($data['modules']);
        abort_unless(\App\Support\Delegation::roleWithin($request->user(), \App\Models\Role::findOrFail($data['role_id'])), 403, 'Ce rôle a plus de droits que vous : seul un administrateur peut l\'attribuer.');
        $data['email'] = strtolower($data['email']);
        $branches = $data['branch_ids'] ?? null;
        unset($data['branch_ids']);
        $user = User::create($data + ['company_id' => $company->id]);
        $this->syncBranches($user, $branches);
        if ($modules !== null) {
            $this->applyModules($request, $user, $modules);
        }
        ActivityLog::record('user.created', $user);

        return response()->json(['data' => $this->row($user->refresh())], 201);
    }

    public function update(Request $request, int $id)
    {
        $user = $this->query()->findOrFail($id);
        // Pas de prise de contrôle d'un compte plus puissant (changer l'e-mail ou le mot de passe de l'administrateur…)
        abort_unless($user->id === $request->user()->id || \App\Support\Delegation::within($request->user(), $user), 403, 'Ce compte a plus de droits que vous : seul un administrateur peut le modifier.');
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:50',
            'password' => 'nullable|string|min:8',
            'role_id' => ['sometimes', Rule::exists('roles', 'id')->where('company_id', app('tenant')->id)],
            'is_active' => 'sometimes|boolean',
            'branch_ids' => 'sometimes|array',
            'branch_ids.*' => 'uuid',
            'manager_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('company_id', app('tenant')->id)],
            'job_title' => 'sometimes|nullable|string|max:120',
            'modules' => 'sometimes|array',
            'modules.*' => 'string|max:50',
        ]);
        $modules = $data['modules'] ?? null;
        unset($data['modules']);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $changesLogin = array_key_exists('password', $data) || (isset($data['email']) && strtolower($data['email']) !== strtolower((string) $user->email));
        // Compte démo des stores : identifiants gérés uniquement depuis l'administration de la plateforme
        abort_if($changesLogin && \App\Platform\StoreDemo::isDemoUser($user), 422, 'Les identifiants du compte de démonstration se changent depuis l\'administration de la plateforme.');
        if ($changesLogin && $user->id === $request->user()->id) {
            // son propre mot de passe ou e-mail : le mot de passe actuel est exigé (un jeton volé ne suffit pas)
            abort_if(array_key_exists('password', $data), 422, 'Pour changer votre mot de passe, utilisez « Mon compte › Changer le mot de passe ».');
            abort_unless(\Illuminate\Support\Facades\Hash::check((string) $request->input('current_password'), $user->password), 422, 'Saisissez votre mot de passe actuel pour changer votre adresse e-mail.');
        }
        if (! empty($data['manager_id'])) {
            // pas de boucle : on ne se rattache ni à soi-même ni à quelqu'un qui dépend de soi
            $cur = (int) $data['manager_id'];
            $seen = [];
            while ($cur && ! in_array($cur, $seen, true) && count($seen) < 50) {
                abort_if($cur === $user->id, 422, 'Responsable impossible : cette personne dépend déjà de lui.');
                $seen[] = $cur;
                $cur = (int) $this->query()->where('id', $cur)->value('manager_id');
            }
        }
        if (array_key_exists('branch_ids', $data)) {
            // on ne lève pas sa propre limite d'agences (sauf administrateur complet)
            if ($user->id !== $request->user()->id || \App\Support\Delegation::full($request->user())) {
                $this->syncBranches($user, $data['branch_ids']);
            }
            unset($data['branch_ids']);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if ($user->id === $request->user()->id) {
            unset($data['is_active'], $data['role_id']); // on ne se bloque pas soi-même
        }
        if (isset($data['role_id'])) {
            abort_unless(\App\Support\Delegation::roleWithin($request->user(), \App\Models\Role::findOrFail($data['role_id'])), 403, 'Ce rôle a plus de droits que vous : seul un administrateur peut l\'attribuer.');
        }
        // Réactivation : la limite d'utilisateurs de la formule s'applique aussi
        if (($data['is_active'] ?? false) && ! $user->is_active && ($max = app('tenant')->plan?->max_users) && $this->query()->where('is_active', true)->count() >= $max) {
            return response()->json(['message' => "Votre formule est limitée à $max utilisateurs."], 422);
        }
        $user->update($data);
        if ($modules !== null && $user->id !== $request->user()->id) {
            $this->applyModules($request, $user->fresh(), $modules);
        }
        if (($data['is_active'] ?? true) === false || ! empty($data['password'])) {
            // désactivé ou mot de passe changé : reconnexion obligatoire partout (sauf la session en cours si c'est soi-même)
            $user->tokens()->when($user->id === $request->user()->id, fn ($q) => $q->where('id', '!=', $request->user()->currentAccessToken()?->id))->delete();
        }
        ActivityLog::record('user.updated', $user);

        return ['data' => $this->row($user->fresh())];
    }

    public function destroy(Request $request, int $id)
    {
        $user = $this->query()->findOrFail($id);
        abort_if($user->id === $request->user()->id, 422, 'Vous ne pouvez pas supprimer votre propre compte.');
        abort_unless(\App\Support\Delegation::within($request->user(), $user), 403, 'Ce compte a plus de droits que vous.');
        $user->tokens()->delete();
        $user->update(['is_active' => false]);

        return ['ok' => true];
    }

    /**
     * Accès d'un utilisateur : modules de la société (gratuits / payants) qu'il voit, et pour chacun ses droits
     * (hérités du rôle, ajoutés ou retirés pour lui seul).
     */
    /** Tout ce qu'il faut pour le formulaire utilisateur : fonctions (et modules qu'elles ouvrent), modules actifs, responsables possibles. */
    public function formMeta(\App\Support\ModuleRegistry $registry)
    {
        $company = app('tenant');
        $enabled = $company->enabledModules();
        $perms = $registry->permissions($enabled);
        $modules = \App\Models\Module::whereIn('key', $enabled)->orderBy('sort')->get();
        $covers = fn (Role $r) => collect($perms)->filter(fn ($p, $k) => $r->allows($k))->pluck('module')->unique()->values()->all();

        return [
            'roles' => Role::withCount('users')->orderBy('name')->get()->map(fn (Role $r) => [
                'id' => $r->id, 'name' => $r->name, 'parent_id' => $r->parent_id, 'description' => $r->description, 'color' => $r->color,
                'is_system' => $r->is_system, 'is_admin' => in_array('*', (array) $r->permissions, true), 'users_count' => $r->users_count, 'modules' => $covers($r),
            ])->values(),
            'modules' => $modules->map(fn ($m) => ['key' => $m->key, 'name' => $m->name, 'category' => $m->category, 'icon' => $m->icon, 'core' => (bool) $m->is_core,
                'description' => $m->description])->values(),
            'users' => $this->query()->where('is_active', true)->with('role:id,name')->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role?->name, 'job_title' => $u->job_title, 'manager_id' => $u->manager_id])->values(),
            'branches' => \Illuminate\Support\Facades\Schema::hasTable('branches') && $company->hasModule('agences')
                ? \Illuminate\Support\Facades\DB::table('branches')->where('company_id', $company->id)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) : [],
        ];
    }

    /**
     * Modules choisis dans le formulaire : ceux de la fonction qui ne sont pas cochés sont masqués pour cette personne ;
     * un module coché hors de sa fonction lui est ouvert en entier (dans la limite des droits de celui qui l'accorde).
     */
    protected function applyModules(Request $request, User $user, array $wanted): void
    {
        $registry = app(\App\Support\ModuleRegistry::class);
        $company = app('tenant');
        $enabled = $company->enabledModules();
        $core = \App\Models\Module::where('is_core', true)->pluck('key')->all();
        $wanted = array_values(array_intersect(array_unique($wanted), $enabled));
        $perms = $registry->permissions($enabled);
        $role = $user->role_id ? Role::find($user->role_id) : null;
        $fromRole = collect($perms)->filter(fn ($p, $k) => $role?->allows($k))->pluck('module')->unique()->all();
        $denied = array_values(array_diff($enabled, $wanted, $core));
        $extra = array_diff($wanted, $fromRole, $core);
        $grants = collect($perms)->filter(fn ($p) => in_array($p['module'], $extra, true))->keys()
            ->reject(fn ($k) => in_array($k, Role::EXPLICIT, true))->values()->all();
        $grants = array_values(array_filter($grants, fn ($k) => \App\Support\Delegation::canGrant($request->user(), $k)));
        $keep = array_values(array_diff((array) $user->permission_grants, collect($perms)->filter(fn ($p) => in_array($p['module'], $denied, true))->keys()->all()));
        $user->forceFill([
            'modules_denied' => $denied ?: null,
            'permission_grants' => array_values(array_unique(array_merge($keep, $grants))) ?: null,
        ])->save();
    }

    public function access(int $id, \App\Support\ModuleRegistry $registry)
    {
        $user = $this->query()->with('role')->findOrFail($id);
        $company = app('tenant');
        $enabled = $company->enabledModules();
        $perms = $registry->permissions($enabled);
        $grants = (array) $user->permission_grants;
        $denies = (array) $user->permission_denies;
        $byModule = [];
        foreach ($perms as $key => $p) {
            $byModule[$p['module']][] = ['key' => $key, 'label' => $p['label'], 'from_role' => (bool) $user->role?->allows($key),
                'override' => in_array($key, $denies, true) ? 'deny' : (in_array($key, $grants, true) ? 'grant' : null), 'effective' => $user->hasPermission($key)];
        }
        $modules = \App\Models\Module::whereIn('key', $enabled)->orderBy('sort')->get()->map(fn ($m) => [
            'key' => $m->key, 'name' => $m->name, 'category' => $m->category, 'icon' => $m->icon,
            'free' => (bool) $m->is_core || (float) $m->price_monthly <= 0, 'core' => (bool) $m->is_core,
            'allowed' => $m->is_core || ! in_array($m->key, (array) $user->modules_denied, true),
            'permissions' => $byModule[$m->key] ?? [],
        ])->values();

        return ['user' => $this->row($user), 'role' => $user->role?->name, 'is_admin' => in_array('*', (array) $user->role?->permissions, true), 'modules' => $modules];
    }

    public function updateAccess(Request $request, int $id, \App\Support\ModuleRegistry $registry)
    {
        $user = $this->query()->findOrFail($id);
        abort_if($user->id === $request->user()->id, 422, 'Vous ne pouvez pas modifier vos propres droits.');
        abort_unless(\App\Support\Delegation::within($request->user(), $user), 403, 'Ce compte a plus de droits que vous.');
        $known = array_keys($registry->permissions());
        $d = $request->validate(['modules_denied' => 'present|array', 'modules_denied.*' => 'string|max:50', 'grants' => 'present|array', 'grants.*' => ['string', \Illuminate\Validation\Rule::in($known)],
            'denies' => 'present|array', 'denies.*' => ['string', \Illuminate\Validation\Rule::in($known)]]);
        $core = \App\Models\Module::where('is_core', true)->pluck('key')->all();
        $user->forceFill([
            'modules_denied' => array_values(array_diff(array_unique($d['modules_denied']), $core)) ?: null,
            'permission_grants' => array_values(array_unique(array_diff($d['grants'], $d['denies']))) ?: null,
            'permission_denies' => array_values(array_unique($d['denies'])) ?: null,
        ]);
        abort_unless(\App\Support\Delegation::covers($request->user(), $d['grants']), 403, 'Vous ne pouvez pas donner des droits que vous n\'avez pas vous-même.');
        $user->save();
        ActivityLog::record('user.access', $user, $d);

        return $this->access($id, $registry);
    }

    /** Agences accessibles (multi-agences) : aucune = toutes. */
    protected function syncBranches(User $user, ?array $ids): void
    {
        if ($ids === null || ! \Illuminate\Support\Facades\Schema::hasTable('branch_user')) {
            return;
        }
        $valid = \Illuminate\Support\Facades\DB::table('branches')->where('company_id', app('tenant')->id)->whereNull('deleted_at')->whereIn('id', $ids)->pluck('id')->all();
        // un gestionnaire limité à certaines agences n'attribue que celles-ci (et jamais « toutes les agences » = liste vide)
        $mine = \App\Support\BranchScope::allowedFor(request()->user(), app('tenant'));
        if ($mine !== null) {
            $valid = array_values(array_intersect($valid, $mine)) ?: $mine;
        }
        \Illuminate\Support\Facades\DB::table('branch_user')->where('user_id', $user->id)->delete();
        \Illuminate\Support\Facades\DB::table('branch_user')->insert(array_map(fn ($b) => ['branch_id' => $b, 'user_id' => $user->id], $valid));
    }

    protected function row(User $u): array
    {
        return [
            'branch_ids' => \Illuminate\Support\Facades\Schema::hasTable('branch_user') ? \Illuminate\Support\Facades\DB::table('branch_user')->where('user_id', $u->id)->pluck('branch_id')->all() : [],
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'role_id' => $u->role_id, 'role' => $u->role?->name, 'is_active' => $u->is_active,
            'manager_id' => $u->manager_id, 'job_title' => $u->job_title,
            'online' => $u->isOnline(), 'last_seen_at' => $u->last_seen_at, 'last_platform' => $u->last_platform,
            'custom_access' => (bool) ($u->modules_denied || $u->permission_grants || $u->permission_denies),
        ];
    }
}
