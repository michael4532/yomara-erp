<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $r)
    {
        $q = AppNotification::where('user_id', $r->user()->id)->where('company_id', app('tenant')->id);

        return [
            'unread' => (clone $q)->whereNull('read_at')->count(),
            'data' => $q->latest('id')->limit(40)->get(),
        ];
    }

    public function read(Request $r, ?int $id = null)
    {
        AppNotification::where('user_id', $r->user()->id)->when($id, fn ($q) => $q->where('id', $id))->whereNull('read_at')->update(['read_at' => now()]);

        return ['ok' => true];
    }
}
