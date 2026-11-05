<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\LiveChatController;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Super admin : conversations du live chat du site. */
class LiveChatAdminController extends Controller
{
    public function index(Request $r)
    {
        $q = DB::table('live_chats')->orderByDesc('last_message_at')->limit(200);
        if ($s = $r->query('status')) {
            $q->where('status', $s);
        }
        $rows = $q->get();
        $last = DB::table('live_chat_messages')->whereIn('id', DB::table('live_chat_messages')->selectRaw('max(id)')->whereIn('live_chat_id', $rows->pluck('id'))->groupBy('live_chat_id'))->get()->keyBy('live_chat_id');

        return [
            'data' => $rows->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'page' => $c->page, 'status' => $c->status, 'device' => $c->device,
                'unread' => (int) $c->unread_admin, 'last_message_at' => $c->last_message_at ? \Carbon\Carbon::parse($c->last_message_at)->toIso8601String() : null,
                'visitor_online' => $c->visitor_seen_at && \Carbon\Carbon::parse($c->visitor_seen_at)->gt(now()->subSeconds(40)),
                'last' => isset($last[$c->id]) ? ['from' => $last[$c->id]->sender, 'body' => mb_substr($last[$c->id]->body, 0, 140)] : null,
            ])->values(),
            'unread' => (int) DB::table('live_chats')->sum('unread_admin'),
            'open' => DB::table('live_chats')->where('status', 'open')->count(),
            'welcome' => LiveChatController::welcome(),
            'enabled' => (bool) PlatformSetting::get('livechat.enabled', true),
        ];
    }

    public function show(Request $r, int $id)
    {
        $c = DB::table('live_chats')->find($id);
        abort_unless($c, 404);
        DB::table('live_chats')->where('id', $id)->update(['unread_admin' => 0]);
        $msgs = DB::table('live_chat_messages')->where('live_chat_id', $id)->where('id', '>', (int) $r->input('after', 0))->orderBy('id')->get()
            ->map(fn ($m) => ['id' => $m->id, 'from' => $m->sender, 'body' => $m->body, 'at' => \Carbon\Carbon::parse($m->created_at)->toIso8601String()]);

        return ['chat' => (array) $c + ['visitor_online' => $c->visitor_seen_at && \Carbon\Carbon::parse($c->visitor_seen_at)->gt(now()->subSeconds(40))], 'messages' => $msgs];
    }

    public function reply(Request $r, int $id)
    {
        abort_unless(DB::table('live_chats')->where('id', $id)->exists(), 404);
        $d = $r->validate(['message' => 'required|string|min:1|max:4000']);
        LiveChatController::add($id, 'admin', $d['message'], $r->user()->id);
        DB::table('live_chats')->where('id', $id)->update(['status' => 'open', 'unread_admin' => 0]);

        return $this->show($r, $id);
    }

    public function close(int $id)
    {
        DB::table('live_chats')->where('id', $id)->update(['status' => 'closed', 'unread_admin' => 0, 'updated_at' => now()]);

        return ['ok' => true];
    }

    public function settings(Request $r)
    {
        $d = $r->validate(['welcome' => 'nullable|string|max:300', 'enabled' => 'sometimes|boolean']);
        if (array_key_exists('welcome', $d)) {
            PlatformSetting::put('livechat.welcome', $d['welcome']);
        }
        if (array_key_exists('enabled', $d)) {
            PlatformSetting::put('livechat.enabled', (bool) $d['enabled']);
        }

        return ['ok' => true];
    }
}
