<?php

namespace App\Modules\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    public function stats(Request $request, RestaurantService $svc)
    {
        $tz = app('tenant')->settings['timezone'] ?? 'UTC';
        $v = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return response()->json(['data' => $svc->stats(app('tenant'), $v['from'] ?? now($tz)->toDateString(), $v['to'] ?? now($tz)->toDateString())]);
    }
}
