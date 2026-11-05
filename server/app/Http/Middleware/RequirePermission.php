<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();
        foreach ($permissions as $p) {
            if ($user && $user->hasPermission($p)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Action non autorisée.', 'code' => 'forbidden'], 403);
    }
}
