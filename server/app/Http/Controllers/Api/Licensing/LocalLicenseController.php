<?php

namespace App\Http\Controllers\Api\Licensing;

use App\Http\Controllers\Controller;
use App\Licensing\LicenseClient;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

/** Licence de cette installation (mode installation unique). */
class LocalLicenseController extends Controller
{
    public function show()
    {
        return ['data' => LicenseClient::info()];
    }

    public function activate(Request $r)
    {
        abort_if(config('erp.mode') === 'saas', 404);
        $d = $r->validate(['key' => 'required_without:email|nullable|string|max:40', 'email' => 'required_without:key|nullable|email', 'password' => 'required_with:email|nullable|string',
            'challenge' => 'nullable|string|size:48', 'code' => 'nullable|string|max:20']);
        try {
            $info = ! empty($d['email'])
                ? LicenseClient::link($d['email'], $d['password'], app('tenant')->name, $d['challenge'] ?? null, $d['code'] ?? null)
                : LicenseClient::activate($d['key'], app('tenant')->name);
        } catch (\App\Licensing\TwoFactorRequired $e) {
            // Transmis à l'application : elle demande le code puis renvoie la demande avec le défi
            return response()->json(['two_factor' => true] + \Illuminate\Support\Arr::only($e->data, ['challenge', 'method', 'email_hint', 'message']), 409);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        ActivityLog::record('license.activated');

        return ['data' => $info];
    }

    public function check()
    {
        abort_if(config('erp.mode') === 'saas', 404);

        return ['data' => LicenseClient::check()];
    }
}
