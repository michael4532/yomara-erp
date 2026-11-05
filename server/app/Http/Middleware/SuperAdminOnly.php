<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SuperAdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->is_super_admin) {
            return response()->json(['message' => 'Réservé au super administrateur.'], 403);
        }

        return $next($request);
    }
}
