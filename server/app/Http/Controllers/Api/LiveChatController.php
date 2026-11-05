<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Live chat du site de présentation : un visiteur écrit, l'éditeur répond depuis son espace super admin.
 * Côté visiteur, la conversation est identifiée par un jeton gardé dans son navigateur.
 */
class LiveChatController extends Controller
{
    protected function chat(string $token)
    {
        $c = DB::table('live_chats')->where('token', $token)->first();
        abort_unless($c, 404);

        return $c;
    }

    protected function messages(int $chatId, int $after = 0)
    {
        return DB::table('live_chat_messages')->where('live_chat_id', $chatId)->where('id', '>', $after)->orderBy('id')->limit(200)->get(['id', 'sender', 'body', 'created_at'])
            ->map(fn ($m) => ['id' => $m->id, 'from' => $m->sender, 'body' => $m->body, 'at' => \Carbon\Carbon::parse($m->created_at)->toIso8601String()]);
    }

    public function start(Request $r)
    {
        abort_unless(config('erp.mode') === 'saas', 404);
        $d = $r->validate(['name' => 'nullable|string|max:120', 'email' => 'nullable|email|max:160', 'phone' => 'nullable|string|max:60', 'page' => 'nullable|string|max:190', 'message' => 'required|string|min:1|max:4000']);
        $ua = (string) $r->userAgent();
        $id = DB::table('live_chats')->insertGetId([
            'token' => Str::random(48), 'name' => $d['name'] ?? null, 'email' => $d['email'] ?? null, 'phone' => $d['phone'] ?? null, 'page' => $d['page'] ?? null,
            'status' => 'open', 'ip' => $r->ip(), 'device' => preg_match('/mobi|android|iphone/i', $ua) ? 'mobile' : 'ordinateur',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->add($id, 'visitor', $d['message']);
        if (! empty($d['email']) || ! empty($d['phone'])) {
            \App\Services\SiteTraffic::capture('contact', ['name' => $d['name'] ?? '', 'email' => $d['email'] ?? null, 'phone' => $d['phone'] ?? null, 'message' => $d['message'], 'label' => 'Live chat', 'tags' => ['live_chat']]);
        }
        $c = DB::table('live_chats')->find($id);

        return response()->json(['token' => $c->token, 'messages' => $this->messages($id), 'welcome' => self::welcome()], 201);
    }

    public function show(Request $r, string $token)
    {
        $c = $this->chat($token);
        DB::table('live_chats')->where('id', $c->id)->update(['unread_visitor' => 0, 'visitor_seen_at' => now()]);

        return ['status' => $c->status, 'messages' => $this->messages($c->id, (int) $r->query('after', 0)), 'online' => self::agentOnline()];
    }

    public function send(Request $r, string $token)
    {
        $c = $this->chat($token);
        $d = $r->validate(['message' => 'required|string|min:1|max:4000']);
        if ($c->status === 'closed') {
            DB::table('live_chats')->where('id', $c->id)->update(['status' => 'open']);
        }
        $this->add($c->id, 'visitor', $d['message']);

        return ['messages' => $this->messages($c->id, (int) $r->input('after', 0))];
    }

    public static function welcome(): string
    {
        return (string) (\App\Models\PlatformSetting::get('livechat.welcome') ?: 'Bonjour 👋 Une question sur '.config('erp.name').' ? Écrivez-nous, nous répondons en direct.');
    }

    /** Un super admin a été actif dans les 5 dernières minutes. */
    public static function agentOnline(): bool
    {
        return User::where('is_super_admin', true)->where('last_seen_at', '>', now()->subMinutes(5))->exists();
    }

    public static function add(int $chatId, string $from, string $body, ?int $userId = null): void
    {
        DB::table('live_chat_messages')->insert(['live_chat_id' => $chatId, 'sender' => $from, 'user_id' => $userId, 'body' => trim($body), 'created_at' => now()]);
        $chat = DB::table('live_chats')->find($chatId);
        DB::table('live_chats')->where('id', $chatId)->update([
            'last_message_at' => now(), 'updated_at' => now(),
            $from === 'visitor' ? 'unread_admin' : 'unread_visitor' => DB::raw(($from === 'visitor' ? 'unread_admin' : 'unread_visitor').' + 1'),
        ]);
        // Prévenir l'éditeur au premier message non lu
        if ($from === 'visitor' && (int) $chat->unread_admin === 0) {
            rescue(function () use ($chat, $body) {
                $admins = User::where('is_super_admin', true)->where('is_active', true)->get();
                foreach ($admins->groupBy('company_id') as $companyId => $users) {
                    \App\Models\AppNotification::send((int) $companyId, $users->pluck('id')->all(), 'livechat', 'Live chat : '.($chat->name ?: 'un visiteur du site'), mb_substr($body, 0, 140), 'admin_livechat', (string) $chat->id);
                }
            }, null, false);
        }
    }
}
