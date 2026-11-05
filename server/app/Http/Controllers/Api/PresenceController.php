<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class PresenceController extends Controller
{
    /** Heartbeat envoyé par l'app toutes les 60 s (le middleware "presence" fait l'écriture). */
    public function ping(\Illuminate\Http\Request $r)
    {
        $u = $r->user();
        $c = app()->bound('tenant') ? app('tenant') : $u?->company;
        app(\App\Support\ModuleRegistry::class)->flush(); // modules activés entre deux appels : lus à nouveau

        return ['ok' => true, 'server_time' => now()->toIso8601String(), 'stamp' => $u && $c ? BootstrapController::stamp($c, $u) : null];
    }
}
