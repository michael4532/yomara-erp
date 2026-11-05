<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\ModuleRegistry;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function permissions(ModuleRegistry $registry)
    {
        $mods = app('tenant')->enabledModules();

        return ['data' => $registry->permissions($mods),
            'modules' => \App\Models\Module::whereIn('key', $mods)->pluck('name', 'key')->put('core', 'Socle : contacts, produits, utilisateurs, paramètres')];
    }

    public function index()
    {
        return ['data' => Role::withCount('users')->orderBy('name')->get()];
    }

    /** Rattachement hiérarchique : une fonction de la société, jamais elle-même ni une de ses subordonnées. */
    protected function checkParent(?int $parentId, ?Role $role = null): void
    {
        if (! $parentId) {
            return;
        }
        $parent = Role::find($parentId);
        abort_unless($parent, 422, 'Fonction supérieure introuvable.');
        abort_if($role && ($parent->id === $role->id || in_array($role->id, $parent->ancestorIds(), true)), 422, 'Une fonction ne peut pas être placée sous elle-même ou sous une fonction qui dépend d\'elle.');
    }

    /** Droits connus uniquement, et jamais plus que ceux de la personne qui crée ou modifie le rôle. */
    protected function checkPermissions(Request $request, array $perms, array $existing = []): void
    {
        $known = array_keys(app(ModuleRegistry::class)->permissions());
        foreach ($perms as $p) {
            $p = (string) $p;
            $valid = in_array($p, $known, true) || ($p !== '*' && in_array($p, $existing, true)) || ($p !== '*' && (str_contains($p, '*') || str_contains($p, '?')) && collect($known)->contains(fn ($k) => fnmatch($p, $k)));
            abort_unless($valid, 422, 'Droit inconnu : '.$p);
        }
        abort_unless(\App\Support\Delegation::covers($request->user(), array_values(array_diff($perms, $existing))), 403, 'Vous ne pouvez pas donner des droits que vous n\'avez pas vous-même.');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'permissions' => 'array', 'permissions.*' => 'string|max:100',
            'parent_id' => 'nullable|integer', 'description' => 'nullable|string|max:300', 'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']]);
        abort_if(Role::where('name', $data['name'])->exists(), 422, 'Une fonction porte déjà ce nom.');
        $this->checkPermissions($request, $data['permissions'] ?? []);
        $this->checkParent($data['parent_id'] ?? null);

        return response()->json(['data' => Role::create($data)], 201);
    }

    public function update(Request $request, int $id)
    {
        $role = Role::findOrFail($id);
        $data = $request->validate(['name' => 'sometimes|string|max:100', 'permissions' => 'sometimes|array', 'permissions.*' => 'string|max:100',
            'parent_id' => 'sometimes|nullable|integer', 'description' => 'sometimes|nullable|string|max:300', 'color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']]);
        if (array_key_exists('parent_id', $data)) {
            $this->checkParent($data['parent_id'], $role);
        }
        abort_if(isset($data['name']) && Role::where('name', $data['name'])->where('id', '!=', $role->id)->exists(), 422, 'Une fonction porte déjà ce nom.');
        abort_if($role->is_system && ! \App\Support\Delegation::full($request->user()), 403, 'Seul un administrateur peut modifier ce rôle.');
        abort_unless(\App\Support\Delegation::roleWithin($request->user(), $role), 403, 'Ce rôle a plus de droits que vous : seul un administrateur peut le modifier.');
        if ($role->is_system) {
            unset($data['permissions']); // l'administrateur garde tous les droits
        }
        if (isset($data['permissions'])) {
            $this->checkPermissions($request, $data['permissions'], (array) $role->permissions);
        }
        $role->update($data);

        return ['data' => $role];
    }

    public function destroy(Request $request, int $id)
    {
        $role = Role::withCount('users')->findOrFail($id);
        abort_unless(\App\Support\Delegation::roleWithin($request->user(), $role), 403, 'Ce rôle a plus de droits que vous.');
        abort_if($role->is_system || $role->users_count > 0, 422, 'Rôle système ou encore attribué à des utilisateurs.');
        Role::where('parent_id', $role->id)->update(['parent_id' => $role->parent_id]); // les fonctions rattachées remontent d'un cran
        $role->delete();

        return ['ok' => true];
    }
}
