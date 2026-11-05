<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Support client (gratuit) : chat en direct entre une société et l'éditeur.
 * La société désigne les personnes autorisées à contacter le support ; les administrateurs le sont toujours.
 */
class SupportController extends Controller
{
    public const CATEGORIES = ['question' => 'Question', 'bug' => 'Problème / bug', 'billing' => 'Abonnement et paiement', 'feature' => 'Suggestion', 'training' => 'Formation'];

    public static function contacts(): array
    {
        return array_values(array_map('intval', (array) data_get(app('tenant')->settings, 'support_contacts', [])));
    }

    public static function allowed(User $u): bool
    {
        return $u->is_super_admin || $u->hasPermission('core.settings.manage') || in_array($u->id, self::contacts(), true);
    }

    protected function guard(Request $r): void
    {
        abort_unless(self::allowed($r->user()), 403, 'Votre administrateur ne vous a pas désigné pour contacter le support.');
    }

    protected function conv(int $id)
    {
        $c = DB::table('support_conversations')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($c, 404);

        return $c;
    }

    public static function row(object $c, bool $platform = false): array
    {
        $last = DB::table('support_messages')->where('conversation_id', $c->id)->orderByDesc('id')->first(['body', 'from_platform', 'created_at']);

        return [
            'id' => $c->id, 'subject' => $c->subject, 'category' => $c->category, 'category_label' => self::CATEGORIES[$c->category] ?? $c->category,
            'priority' => $c->priority, 'status' => $c->status, 'rating' => $c->rating,
            'unread' => (int) ($platform ? $c->unread_platform : $c->unread_company),
            'last_message' => $last ? mb_substr($last->body, 0, 140) : null, 'last_from_platform' => (bool) ($last->from_platform ?? false),
            'last_message_at' => $c->last_message_at ? \Illuminate\Support\Carbon::parse($c->last_message_at)->toIso8601String() : null,
            'created_at' => \Illuminate\Support\Carbon::parse($c->created_at)->toIso8601String(),
            'user' => $c->user_id ? User::withoutGlobalScopes()->whereKey($c->user_id)->value('name') : null,
        ];
    }

    public static function messages(int $convId, int $after = 0): array
    {
        $rows = DB::table('support_messages')->where('conversation_id', $convId)->where('id', '>', $after)->orderBy('id')->limit(500)->get();
        $names = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'from_platform' => (bool) $m->from_platform,
            'author' => $m->from_platform ? 'Support Yomara' : ($names[$m->user_id] ?? 'Utilisateur'),
            'mine_user_id' => $m->user_id, 'created_at' => \Illuminate\Support\Carbon::parse($m->created_at)->toIso8601String()])->all();
    }

    /** Badge et droits, appelé régulièrement par le bouton Support. */
    public function status(Request $r)
    {
        $ok = self::allowed($r->user());

        return [
            'allowed' => $ok,
            'unread' => $ok ? (int) DB::table('support_conversations')->where('company_id', app('tenant')->id)->sum('unread_company') : 0,
            'open' => $ok ? DB::table('support_conversations')->where('company_id', app('tenant')->id)->where('status', '!=', 'closed')->count() : 0,
            'contacts' => self::contacts(),
            'can_manage' => $r->user()->hasPermission('core.settings.manage'),
            'categories' => self::CATEGORIES,
            'hours' => \App\Models\PlatformSetting::get('support.hours', 'Lundi au vendredi, 8 h – 18 h (GMT)'),
        ];
    }

    public function index(Request $r)
    {
        $this->guard($r);
        $rows = DB::table('support_conversations')->where('company_id', app('tenant')->id)->orderByRaw("status = 'closed'")->orderByDesc('last_message_at')->limit(100)->get();

        return ['data' => $rows->map(fn ($c) => self::row($c))->values()];
    }

    public function store(Request $r)
    {
        $this->guard($r);
        $d = $r->validate(['subject' => 'required|string|max:160', 'body' => 'required|string|max:5000', 'category' => 'nullable|in:'.implode(',', array_keys(self::CATEGORIES)),
            'priority' => 'nullable|in:low,normal,high,urgent', 'context' => 'nullable|array']);
        $id = DB::table('support_conversations')->insertGetId(['company_id' => app('tenant')->id, 'user_id' => $r->user()->id, 'subject' => strip_tags($d['subject']),
            'category' => $d['category'] ?? 'question', 'priority' => $d['priority'] ?? 'normal', 'status' => 'open', 'unread_platform' => 1,
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->insert($id, $r->user()->id, false, $d['body'], ['context' => array_slice((array) ($d['context'] ?? []), 0, 10)]);

        return response()->json(['data' => self::row($this->conv($id)), 'messages' => self::messages($id)], 201);
    }

    public function show(Request $r, int $id)
    {
        $this->guard($r);
        $c = $this->conv($id);
        $after = (int) $r->query('after', 0);
        if ($c->unread_company > 0) {
            DB::table('support_conversations')->where('id', $id)->update(['unread_company' => 0]);
        }

        return ['data' => self::row($this->conv($id)), 'messages' => self::messages($id, $after)];
    }

    public function reply(Request $r, int $id)
    {
        $this->guard($r);
        $c = $this->conv($id);
        $d = $r->validate(['body' => 'required|string|max:5000']);
        $this->insert($id, $r->user()->id, false, $d['body']);
        DB::table('support_conversations')->where('id', $id)->update(['status' => 'open', 'unread_platform' => DB::raw('unread_platform + 1'), 'last_message_at' => now(), 'updated_at' => now()]);

        return ['data' => self::row($this->conv($id)), 'messages' => self::messages($id, (int) $r->input('after', 0))];
    }

    public function close(Request $r, int $id)
    {
        $this->guard($r);
        $this->conv($id);
        $d = $r->validate(['rating' => 'nullable|integer|min:1|max:5']);
        DB::table('support_conversations')->where('id', $id)->update(['status' => 'closed', 'rating' => $d['rating'] ?? null, 'updated_at' => now()]);

        return ['data' => self::row($this->conv($id))];
    }

    public function setContacts(Request $r)
    {
        abort_unless($r->user()->hasPermission('core.settings.manage'), 403);
        $d = $r->validate(['user_ids' => 'present|array|max:50', 'user_ids.*' => 'integer']);
        $ids = User::where('company_id', app('tenant')->id)->whereIn('id', $d['user_ids'])->pluck('id')->map(fn ($x) => (int) $x)->values()->all();
        $c = app('tenant');
        $c->settings = array_merge((array) $c->settings, ['support_contacts' => $ids]);
        $c->save();

        return ['contacts' => $ids];
    }

    public static function insert(int $convId, ?int $userId, bool $platform, string $body, array $meta = []): int
    {
        $companyId = DB::table('support_conversations')->where('id', $convId)->value('company_id');

        return DB::table('support_messages')->insertGetId(['conversation_id' => $convId, 'company_id' => $companyId, 'user_id' => $userId, 'from_platform' => $platform,
            'body' => trim(strip_tags($body)), 'meta' => $meta ? json_encode($meta) : null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
