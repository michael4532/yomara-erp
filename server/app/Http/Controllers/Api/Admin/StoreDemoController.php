<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Platform\StoreDemo;
use Illuminate\Http\Request;

/** Super admin : compte de démonstration pour la relecture App Store / Google Play. */
class StoreDemoController extends Controller
{
    public function show(StoreDemo $demo)
    {
        return ['data' => $demo->status()];
    }

    public function store(Request $request, StoreDemo $demo)
    {
        $d = $request->validate([
            'email' => 'required|email:rfc|max:190',
            'company_name' => 'nullable|string|max:120',
        ]);
        @set_time_limit(180);

        return response()->json(['data' => $demo->create($d['email'], trim((string) ($d['company_name'] ?? '')) ?: 'Yomara Démo')], 201);
    }

    public function password(StoreDemo $demo)
    {
        return ['data' => $demo->resetPassword()];
    }

    public function disable(StoreDemo $demo)
    {
        return ['data' => $demo->disable()];
    }

    public function forget(StoreDemo $demo)
    {
        return ['data' => $demo->forget()];
    }
}
