<?php

namespace App\Http\Middleware;

use App\Support\Installation;
use Closure;
use Illuminate\Http\Request;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next)
    {
        if (Installation::isInstalled() || app()->runningUnitTests()) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Yomara ERP n\'est pas encore installé.', 'installed' => false], 503);
        }

        return redirect('/install');
    }
}
