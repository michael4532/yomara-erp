<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireModule
{
    public function handle(Request $request, Closure $next, string $module)
    {
        $company = app()->bound('tenant') ? app('tenant') : null;

        if (! $company || ! $company->hasModule($module)) {
            return response()->json([
                'message' => 'Le module « '.$module.' » n\'est pas activé pour votre société.',
                'code' => 'module_disabled',
                'module' => $module,
            ], 403);
        }

        $user = $request->user();
        if ($user && ! $user->is_super_admin && in_array($module, (array) $user->modules_denied, true)) {
            return response()->json([
                'message' => 'Vous n\'avez pas accès au module « '.$module.' ». Demandez-le à l\'administrateur.',
                'code' => 'module_denied',
                'module' => $module,
            ], 403);
        }

        app(\App\Support\ModuleRegistry::class)->ensureSetup($company, [$module]);
        \App\Platform\Adoption::touch($company->id, $module);

        return $next($request);
    }
}
