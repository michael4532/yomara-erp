<?php

namespace App\Modules\Visio;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Visio\Models\Meeting;
use App\Modules\Visio\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Salle de réunion (navigateur, sans compte) : chaque participant est identifié par son lien personnel.
 * Le canal temps réel (conversation, présence, consignes, sondages, signalisation pair-à-pair) passe par le
 * serveur et ne dépend donc pas du fournisseur vidéo : il survit à une bascule.
 */
class RoomController extends Controller
{
    public function __construct(protected VisioService $svc) {}

    protected function meeting(string $code): array
    {
        $m = Meeting::withoutGlobalScopes()->where('code', $code)->first();
        abort_unless($m, 404, 'Réunion introuvable.');
        $c = Company::find($m->company_id);
        abort_unless($c && $c->hasModule('visio') && ! $c->isSuspended(), 404, 'Réunion indisponible.');

        return [$c, $m];
    }

    protected function me(Request $r, Meeting $m, bool $required = true): ?Participant
    {
        $t = (string) ($r->header('X-Visio-Token') ?: $r->input('t'));
        $p = $t !== '' ? Participant::where('meeting_id', $m->id)->where('token', $t)->first() : null;
        abort_if($required && ! $p, 403, 'Lien de participation invalide.');

        return $p;
    }

    protected function host(Request $r, Meeting $m): Participant
    {
        $p = $this->me($r, $m);
        abort_unless($p->isHost(), 403, 'Réservé à l\'organisateur.');

        return $p;
    }

    /** Page de la salle. */
    public function page(Request $r, string $code)
    {
        [$c, $m] = $this->meeting($code);
        $p = $this->me($r, $m, false);
        if ($m->isWebinar() && ! $p && $m->slug) {
            return redirect('/webinaire/'.$m->slug);
        }

        return response()->view('visio.room', ['c' => $c, 'm' => $m, 'token' => $p?->token, 'name' => $p?->name, 'ver' => config('erp.version'),
            'stores' => \App\Http\Controllers\LandingController::welcomeStoreLinks(), 'deeplinks' => (bool) config('erp.app_deeplinks')])
            ->header('Permissions-Policy', 'camera=(self), microphone=(self), display-capture=(self), fullscreen=(self)');
    }

    public function info(Request $r, string $code)
    {
        [$c, $m] = $this->meeting($code);
        $p = $this->me($r, $m, false);
        $host = $m->host_id ? DB::table('users')->where('id', $m->host_id)->value('name') : null;

        return [
            'title' => $m->title, 'type' => $m->type, 'status' => $m->status, 'company' => $c->name, 'logo' => $c->logo && (str_starts_with($c->logo, 'http') || str_starts_with($c->logo, 'data:image')) ? $c->logo : null, 'host' => $host,
            'starts_at' => $m->starts_at?->toIso8601String(), 'duration' => $m->duration_minutes, 'description' => $m->description,
            'password' => (bool) $m->password && ! $p, 'guests' => (bool) $m->opt('guests', true), 'locked' => $m->locked,
            'me' => $p ? ['name' => $p->name, 'role' => $p->role, 'status' => $p->status] : null,
            // Qui est déjà dans la salle (écran d'accueil « Awa et 2 autres sont déjà là »)
            'present' => Participant::where('meeting_id', $m->id)->where('status', 'admitted')->where('last_seen_at', '>', now()->subSeconds(25))
                ->orderBy('joined_at')->limit(6)->pluck('name')->all(),
            'features' => ['chat' => (bool) $m->opt('chat', true), 'screen' => (bool) $m->opt('screen', true), 'transcribe' => (bool) $m->opt('transcribe', true),
                'reactions' => (bool) $m->opt('reactions', true), 'record_auto' => (bool) $m->opt('record_auto', false)],
        ];
    }

    public function join(Request $r, string $code)
    {
        [$c, $m] = $this->meeting($code);
        $d = $r->validate(['name' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:190'], 'password' => ['nullable', 'string', 'max:40'], 'client' => ['nullable', 'string', 'max:80']]);
        abort_if($m->status === 'cancelled', 410, 'Cette réunion a été annulée.');
        $p = $this->me($r, $m, false);
        if (! $p) {
            abort_if($m->isWebinar(), 403, 'Inscrivez-vous au webinaire pour recevoir votre lien personnel.');
            abort_unless($m->opt('guests', true), 403, 'Cette réunion est réservée aux personnes invitées.');
            abort_if($m->locked, 423, 'L\'organisateur a verrouillé la réunion.');
            abort_if($m->password && ! hash_equals((string) $m->password, (string) ($d['password'] ?? '')), 422, 'Code d\'accès incorrect.');
            abort_unless(trim((string) ($d['name'] ?? '')) !== '', 422, 'Indiquez votre nom.');
            $p = Participant::create(['meeting_id' => $m->id, 'company_id' => $m->company_id, 'name' => trim($d['name']), 'email' => $d['email'] ?? null,
                'role' => 'guest', 'token' => VisioService::token(), 'status' => 'invited']);
        }
        abort_if(in_array($p->status, ['removed', 'denied'], true), 403, 'L\'organisateur ne vous a pas admis dans cette réunion.');
        if ($m->status === 'ended') {
            // Une réunion terminée se rouvre quand son organisateur revient (réunion récurrente, reprise)
            abort_unless($p->isHost(), 410, 'Cette réunion est terminée.');
            $this->svc->reopen($m);
        }
        if (! empty($d['name']) && $p->role === 'guest' && ! $p->user_id) {
            $p->name = mb_substr(trim($d['name']), 0, 120);
        }
        $p->client = $d['client'] ?? $p->client;
        $p->save();
        if ($p->status !== 'admitted') {
            $mustWait = $p->role === 'guest' && ($m->opt('lobby', false) || $m->locked);
            if ($mustWait) {
                if ($p->status !== 'lobby') {
                    $p->forceFill(['status' => 'lobby', 'last_seen_at' => now()])->save();
                    $this->svc->system($m, 'lobby', $p);
                }
            } else {
                $this->svc->admit($m, $p);
            }
        } else {
            $p->forceFill(['last_seen_at' => now(), 'left_at' => null])->save();
        }
        $rtc = null;
        if ($p->status === 'admitted') {
            try {
                $this->svc->ensureProvider($c, $m);
                $rtc = $this->svc->credentials($c, $m->refresh(), $p);
            } catch (\DomainException $e) {
                abort(503, $e->getMessage());
            }
        }

        return ['token' => $p->token, 'me' => $this->meRow($p), 'rtc' => $rtc, 'cursor' => (int) DB::table('vis_feed')->where('meeting_id', $m->id)->max('id')];
    }

    public function rtc(Request $r, string $code)
    {
        [$c, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        abort_unless($p->status === 'admitted', 409, 'Vous n\'êtes pas encore admis.');
        try {
            $this->svc->ensureProvider($c, $m);
        } catch (\DomainException $e) {
            abort(503, $e->getMessage());
        }

        return $this->svc->credentials($c, $m->refresh(), $p);
    }

    protected function meRow(Participant $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'role' => $p->role, 'status' => $p->status, 'mic' => $p->mic, 'cam' => $p->cam, 'hand' => (bool) $p->hand_at, 'publish' => $p->canPublish()];
    }

    protected function row(Participant $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'role' => $p->role, 'mic' => $p->mic, 'cam' => $p->cam, 'screen' => $p->screen, 'hand' => $p->hand_at?->getTimestamp(),
            'internal' => (bool) $p->user_id, 'org' => $p->organization, 'since' => $p->joined_at?->getTimestamp()];
    }

    /** Battement : état de la salle et nouveautés depuis le curseur. */
    public function poll(Request $r, string $code)
    {
        [$c, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        $this->svc->heartbeat($c, $m, $p);
        $m->refresh();
        $since = max(0, (int) $r->input('since'));
        $isHost = $p->isHost();

        $online = Participant::where('meeting_id', $m->id)->where('status', 'admitted')->where('last_seen_at', '>', now()->subSeconds(25));
        $count = (clone $online)->count();
        $list = $m->isWebinar() && ! $isHost
            ? (clone $online)->where(fn ($q) => $q->whereIn('role', ['host', 'cohost', 'panelist'])->orWhereNotNull('hand_at'))->limit(60)->get()
            : (clone $online)->orderByRaw("case role when 'host' then 0 when 'cohost' then 1 when 'panelist' then 2 else 3 end")->orderBy('joined_at')->limit(500)->get();
        $feed = DB::table('vis_feed')->where('meeting_id', $m->id)->where('id', '>', $since)->whereIn('kind', ['chat', 'caption', 'reaction', 'system', 'control', 'signal'])
            ->where(fn ($q) => $q->whereNull('to_participant_id')->orWhere('to_participant_id', $p->id)->orWhere(fn ($w) => $w->where('kind', 'chat')->where('participant_id', $p->id)))
            ->orderBy('id')->limit(300)->get();
        $names = Participant::where('meeting_id', $m->id)->whereIn('id', $feed->pluck('participant_id')->filter()->unique())->pluck('name', 'id');

        $out = [
            'status' => $m->status, 'epoch' => $m->epoch, 'provider' => $m->provider, 'locked' => $m->locked, 'recording' => $m->recording_state === 'recording',
            'spotlight' => $m->spotlight_id, 'chat' => (bool) $m->opt('chat', true), 'me' => $this->meRow($p), 'count' => $count,
            'started_at' => $m->started_at?->getTimestamp(), 'now' => now()->getTimestamp(),
            'participants' => $list->map(fn ($x) => $this->row($x))->values(),
            'feed' => $feed->map(fn ($f) => ['id' => $f->id, 'kind' => $f->kind, 'from' => $f->participant_id, 'name' => $names[$f->participant_id] ?? null, 'to' => $f->to_participant_id,
                'body' => $f->body, 'data' => $f->data ? json_decode($f->data, true) : null, 'at' => substr((string) $f->created_at, 11, 5)])->values(),
            'cursor' => (int) ($feed->max('id') ?? $since),
            'interval' => $m->provider === 'p2p' ? 1000 : ($count <= 12 ? 1500 : ($count <= 60 ? 2500 : 4000)),
        ];
        if ($isHost) {
            $out['lobby'] = Participant::where('meeting_id', $m->id)->where('status', 'lobby')->where('last_seen_at', '>', now()->subSeconds(40))->get(['id', 'name', 'email'])->values();
        }
        if ($m->isWebinar() || DB::table('vis_questions')->where('meeting_id', $m->id)->exists()) {
            $out['questions'] = $this->questionsFor($m, $p);
        }
        $out['polls'] = $this->pollsFor($m, $p);

        return $out;
    }

    protected function questionsFor(Meeting $m, Participant $p): array
    {
        $q = DB::table('vis_questions')->leftJoin('vis_participants as x', 'x.id', '=', 'vis_questions.participant_id')->where('vis_questions.meeting_id', $m->id);
        if (! $p->isHost() && $p->role !== 'panelist') {
            $q->where('vis_questions.status', '!=', 'hidden');
        }

        return $q->orderByRaw("case vis_questions.status when 'live' then 0 when 'open' then 1 else 2 end")->orderByDesc('vis_questions.votes')->orderBy('vis_questions.id')->limit(100)
            ->get(['vis_questions.*', 'x.name'])->map(fn ($x) => ['id' => $x->id, 'body' => $x->body, 'by' => $x->anonymous ? 'Anonyme' : $x->name, 'votes' => (int) $x->votes,
                'voted' => in_array($p->id, (array) json_decode((string) $x->voters, true), true), 'answer' => $x->answer, 'status' => $x->status, 'mine' => (int) $x->participant_id === $p->id])->all();
    }

    protected function pollsFor(Meeting $m, Participant $p): array
    {
        return DB::table('vis_polls')->where('meeting_id', $m->id)->whereIn('status', ['open', 'closed'])->orderByDesc('id')->limit(10)->get()->map(function ($x) use ($p) {
            $opts = json_decode($x->options, true) ?: [];
            $votes = (array) json_decode((string) $x->votes, true);
            $counts = array_fill(0, count($opts), 0);
            foreach ($votes as $choices) {
                foreach ((array) $choices as $i) {
                    if (isset($counts[$i])) {
                        $counts[$i]++;
                    }
                }
            }
            $mine = $votes[(string) $p->id] ?? null;
            $show = $x->status === 'closed' || $mine !== null || $p->isHost();

            return ['id' => $x->id, 'question' => $x->question, 'options' => $opts, 'multiple' => (bool) $x->multiple, 'status' => $x->status,
                'mine' => $mine, 'counts' => $show ? $counts : null, 'voters' => count($votes)];
        })->all();
    }

    public function state(Request $r, string $code)
    {
        [, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        $d = $r->validate(['mic' => 'nullable|boolean', 'cam' => 'nullable|boolean', 'screen' => 'nullable|boolean', 'hand' => 'nullable|boolean']);
        foreach (['mic', 'cam', 'screen'] as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null) {
                $p->{$k} = $p->canPublish() ? (bool) $d[$k] : false;
            }
        }
        if (array_key_exists('screen', $d) && $d['screen'] && ! $m->opt('screen', true) && ! $p->isHost()) {
            $p->screen = false;
        }
        if (array_key_exists('hand', $d) && $d['hand'] !== null) {
            $p->hand_at = $d['hand'] ? ($p->hand_at ?? now()) : null;
            if ($d['hand']) {
                $this->svc->system($m, 'hand', $p);
            }
        }
        $p->save();

        return $this->meRow($p);
    }

    public function chat(Request $r, string $code)
    {
        [, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        $d = $r->validate(['body' => 'required|string|max:2000', 'to' => 'nullable|integer']);
        abort_unless($p->status === 'admitted', 403);
        abort_unless($m->opt('chat', true) || $p->isHost(), 403, 'La conversation est désactivée par l\'organisateur.');
        $to = ! empty($d['to']) ? Participant::where('meeting_id', $m->id)->find($d['to'])?->id : null;
        $id = DB::table('vis_feed')->insertGetId(['meeting_id' => $m->id, 'participant_id' => $p->id, 'to_participant_id' => $to, 'kind' => 'chat', 'body' => trim($d['body']), 'created_at' => now()]);

        return ['id' => $id];
    }

    public function react(Request $r, string $code)
    {
        [, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        $d = $r->validate(['emoji' => 'required|string|in:👍,👏,❤️,😂,😮,🎉,🙏,🔥']);
        DB::table('vis_feed')->insert(['meeting_id' => $m->id, 'participant_id' => $p->id, 'kind' => 'reaction', 'body' => $d['emoji'], 'created_at' => now()]);

        return response()->noContent();
    }

    /** Sous-titre final (reconnaissance vocale du navigateur du participant qui parle). */
    public function caption(Request $r, string $code)
    {
        [, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        $d = $r->validate(['text' => 'required|string|max:1500', 'lang' => 'nullable|string|max:10']);
        abort_unless($p->status === 'admitted' && $p->canPublish(), 403);
        DB::table('vis_feed')->insert(['meeting_id' => $m->id, 'participant_id' => $p->id, 'kind' => 'caption', 'body' => trim($d['text']),
            'data' => json_encode(['lang' => $d['lang'] ?? 'fr-FR']), 'created_at' => now()]);

        return response()->noContent();
    }

    /** Signalisation WebRTC du mode pair-à-pair (offre, réponse, candidats). */
    public function signal(Request $r, string $code)
    {
        [, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        $d = $r->validate(['to' => 'required|integer', 'data' => 'required|array']);
        abort_unless(Participant::where('meeting_id', $m->id)->where('id', $d['to'])->exists(), 404);
        $json = json_encode($d['data']);
        abort_if(strlen($json) > 60000, 413);
        DB::table('vis_feed')->insert(['meeting_id' => $m->id, 'participant_id' => $p->id, 'to_participant_id' => $d['to'], 'kind' => 'signal', 'data' => $json, 'created_at' => now()]);

        return response()->noContent();
    }

    /** Le navigateur signale un problème du fournisseur vidéo : bascule automatique si nécessaire. */
    public function failure(Request $r, string $code)
    {
        [$c, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        $d = $r->validate(['provider' => 'required|string|in:zego,agora,p2p', 'epoch' => 'required|integer', 'kind' => 'required|string|in:join,unstable,disconnected,media', 'detail' => 'nullable|string|max:300']);
        $m = $this->svc->reportFailure($c, $m, $p, $d['provider'], (int) $d['epoch'], $d['kind'], $d['detail'] ?? null);
        $m->refresh();

        return ['epoch' => $m->epoch, 'provider' => $m->provider, 'rtc' => $p->status === 'admitted' ? $this->svc->credentials($c, $m, $p) : null];
    }

    public function healthy(Request $r, string $code)
    {
        [$c, $m] = $this->meeting($code);
        $this->me($r, $m);
        $d = $r->validate(['provider' => 'required|string|in:zego,agora,p2p']);
        if ($d['provider'] !== 'p2p') {
            $this->svc->markHealthy($c, $d['provider']);
        }

        return response()->noContent();
    }

    public function leave(Request $r, string $code)
    {
        [, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        $this->svc->leave($m, $p);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Consignes de l'organisateur

    public function control(Request $r, string $code)
    {
        [$c, $m] = $this->meeting($code);
        $me = $this->host($r, $m);
        $d = $r->validate(['action' => 'required|string|max:20', 'target' => 'nullable|integer', 'value' => 'nullable']);
        $target = ! empty($d['target']) ? Participant::where('meeting_id', $m->id)->find($d['target']) : null;
        $send = fn (string $action, ?Participant $to = null, array $data = []) => DB::table('vis_feed')->insert(['meeting_id' => $m->id, 'participant_id' => $me->id,
            'to_participant_id' => $to?->id, 'kind' => 'control', 'body' => $action, 'data' => json_encode($data), 'created_at' => now()]);
        $needTarget = fn () => abort_unless($target, 404, 'Participant introuvable.');
        switch ($d['action']) {
            case 'admit':
                $needTarget();
                if ($target->status === 'lobby') {
                    $this->svc->admit($m, $target);
                    $send('admitted', $target);
                }
                break;
            case 'admit_all':
                foreach (Participant::where('meeting_id', $m->id)->where('status', 'lobby')->get() as $x) {
                    $this->svc->admit($m, $x);
                    $send('admitted', $x);
                }
                break;
            case 'deny':
                $needTarget();
                $target->forceFill(['status' => 'denied'])->save();
                $send('denied', $target);
                break;
            case 'mute':
                $needTarget();
                $target->forceFill(['mic' => false])->save();
                $send('mute', $target);
                break;
            case 'mute_all':
                Participant::where('meeting_id', $m->id)->where('id', '!=', $me->id)->whereNotIn('role', ['host', 'cohost'])->update(['mic' => false]);
                $send('mute_all', null, ['except' => $me->id]);
                break;
            case 'cam_off':
                $needTarget();
                $target->forceFill(['cam' => false])->save();
                $send('cam_off', $target);
                break;
            case 'stop_screen':
                $needTarget();
                $target->forceFill(['screen' => false])->save();
                $send('stop_screen', $target);
                break;
            case 'remove':
                $needTarget();
                abort_if($target->role === 'host', 422, 'Impossible de retirer l\'organisateur.');
                $target->forceFill(['status' => 'removed', 'left_at' => now(), 'screen' => false])->save();
                $send('removed', $target);
                $this->svc->system($m, 'removed', $target);
                break;
            case 'promote': // spectateur → intervenant
                $needTarget();
                $target->forceFill(['role' => 'panelist', 'hand_at' => null])->save();
                $send('role', $target, ['role' => 'panelist']);
                $this->svc->system($m, 'promoted', $target);
                break;
            case 'demote':
                $needTarget();
                abort_if($target->role === 'host', 422);
                $target->forceFill(['role' => $m->isWebinar() ? 'attendee' : 'guest', 'mic' => false, 'cam' => false, 'screen' => false])->save();
                $send('role', $target, ['role' => $target->role]);
                break;
            case 'cohost':
                $needTarget();
                abort_if($target->role === 'host', 422);
                $target->forceFill(['role' => $d['value'] ? 'cohost' : ($target->user_id ? 'member' : ($m->isWebinar() ? 'panelist' : 'guest'))])->save();
                $send('role', $target, ['role' => $target->role]);
                break;
            case 'lower_hand':
                $needTarget();
                $target->forceFill(['hand_at' => null])->save();
                break;
            case 'lower_all':
                Participant::where('meeting_id', $m->id)->update(['hand_at' => null]);
                break;
            case 'lock':
                $m->forceFill(['locked' => (bool) $d['value']])->save();
                $this->svc->system($m, $m->locked ? 'locked' : 'unlocked', $me);
                break;
            case 'chat':
                $m->forceFill(['options' => array_merge($m->options ?? [], ['chat' => (bool) $d['value']])])->save();
                break;
            case 'spotlight':
                $m->forceFill(['spotlight_id' => $target?->id])->save();
                break;
            case 'record':
                $m->forceFill(['recording_state' => $d['value'] ? 'recording' : null])->save();
                $this->svc->system($m, $d['value'] ? 'record_on' : 'record_off', $me);
                break;
            case 'end':
                $this->svc->end($c, $m, $me);
                $send('ended');
                if ($c->hasModule('visio')) {
                    dispatch(fn () => RoomController::afterEnd($m->id))->afterResponse();
                }
                break;
            default:
                abort(422, 'Action inconnue.');
        }

        return ['ok' => true];
    }

    /** Après la fin : compte rendu et envoi (après la réponse HTTP, pour ne pas faire attendre l'organisateur). */
    public static function afterEnd(int $meetingId): void
    {
        $m = Meeting::withoutGlobalScopes()->find($meetingId);
        $c = $m ? Company::find($m->company_id) : null;
        if (! $m || ! $c) {
            return;
        }
        rescue(function () use ($c, $m) {
            $reports = app(ReportService::class);
            $reports->generate($c, $m);
            if ($m->opt('send_report', true)) {
                $reports->send($c, $m, true, $m->host_id);
            }
        }, null, true);
    }

    // ---------------------------------------------------------------- Questions-réponses et sondages

    public function ask(Request $r, string $code)
    {
        [, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        $d = $r->validate(['body' => 'required|string|max:1000', 'anonymous' => 'nullable|boolean']);
        $id = DB::table('vis_questions')->insertGetId(['meeting_id' => $m->id, 'participant_id' => $p->id, 'body' => trim($d['body']), 'anonymous' => (bool) ($d['anonymous'] ?? false),
            'status' => 'open', 'votes' => 0, 'voters' => '[]', 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $id];
    }

    public function vote(Request $r, string $code, int $id)
    {
        [, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        DB::transaction(function () use ($m, $p, $id) {
            $q = DB::table('vis_questions')->where('meeting_id', $m->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($q, 404);
            $voters = (array) json_decode((string) $q->voters, true);
            $voters = in_array($p->id, $voters, true) ? array_values(array_diff($voters, [$p->id])) : array_merge($voters, [$p->id]);
            DB::table('vis_questions')->where('id', $id)->update(['voters' => json_encode($voters), 'votes' => count($voters), 'updated_at' => now()]);
        });

        return response()->noContent();
    }

    public function answer(Request $r, string $code, int $id)
    {
        [, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        abort_unless($p->isHost() || $p->role === 'panelist', 403);
        $d = $r->validate(['answer' => 'nullable|string|max:2000', 'status' => 'nullable|string|in:open,answered,live,hidden']);
        $row = ['updated_at' => now()];
        if (array_key_exists('answer', $d)) {
            $row['answer'] = $d['answer'];
            $row['status'] = $d['answer'] ? 'answered' : 'open';
        }
        if (! empty($d['status'])) {
            $row['status'] = $d['status'];
            if ($d['status'] === 'live') {
                DB::table('vis_questions')->where('meeting_id', $m->id)->where('status', 'live')->update(['status' => 'answered']);
            }
        }
        DB::table('vis_questions')->where('meeting_id', $m->id)->where('id', $id)->update($row);

        return response()->noContent();
    }

    public function createPoll(Request $r, string $code)
    {
        [, $m] = $this->meeting($code);
        $p = $this->host($r, $m);
        $d = $r->validate(['question' => 'required|string|max:255', 'options' => 'required|array|min:2|max:8', 'options.*' => 'required|string|max:120', 'multiple' => 'nullable|boolean']);
        $id = DB::table('vis_polls')->insertGetId(['meeting_id' => $m->id, 'question' => $d['question'], 'options' => json_encode(array_values($d['options']), JSON_UNESCAPED_UNICODE),
            'multiple' => (bool) ($d['multiple'] ?? false), 'status' => 'open', 'votes' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->svc->system($m, 'poll', $p, ['poll' => $id]);

        return ['id' => $id];
    }

    public function votePoll(Request $r, string $code, int $id)
    {
        [, $m] = $this->meeting($code);
        $p = $this->me($r, $m);
        $d = $r->validate(['choices' => 'required|array|min:1', 'choices.*' => 'integer|min:0']);
        DB::transaction(function () use ($m, $p, $id, $d) {
            $poll = DB::table('vis_polls')->where('meeting_id', $m->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($poll && $poll->status === 'open', 422, 'Ce sondage est clos.');
            $n = count(json_decode($poll->options, true) ?: []);
            $choices = array_values(array_unique(array_filter(array_map('intval', $d['choices']), fn ($i) => $i < $n)));
            abort_unless($choices, 422);
            if (! $poll->multiple) {
                $choices = [$choices[0]];
            }
            $votes = (array) json_decode((string) $poll->votes, true);
            $votes[(string) $p->id] = $choices;
            DB::table('vis_polls')->where('id', $id)->update(['votes' => json_encode($votes), 'updated_at' => now()]);
        });

        return response()->noContent();
    }

    public function closePoll(Request $r, string $code, int $id)
    {
        [, $m] = $this->meeting($code);
        $this->host($r, $m);
        DB::table('vis_polls')->where('meeting_id', $m->id)->where('id', $id)->update(['status' => 'closed', 'updated_at' => now()]);

        return response()->noContent();
    }

    // ---------------------------------------------------------------- Enregistrement (composé dans le navigateur de l'organisateur)

    public function startRecording(Request $r, string $code)
    {
        [, $m] = $this->meeting($code);
        $p = $this->host($r, $m);
        $mime = in_array($r->input('mime'), ['video/webm', 'video/mp4'], true) ? $r->input('mime') : 'video/webm';
        $ext = $mime === 'video/mp4' ? 'mp4' : 'webm';
        $id = DB::table('vis_recordings')->insertGetId(['meeting_id' => $m->id, 'company_id' => $m->company_id, 'participant_id' => $p->id, 'path' => '', 'mime' => $mime,
            'status' => 'recording', 'token' => VisioService::token(), 'created_at' => now(), 'updated_at' => now()]);
        $path = 'visio/'.$m->company_id.'/'.$m->id.'-'.$id.'.'.$ext;
        DB::table('vis_recordings')->where('id', $id)->update(['path' => $path]);
        Storage::disk('local')->put($path, '');
        $m->forceFill(['recording_state' => 'recording'])->save();
        $this->svc->system($m, 'record_on', $p);

        return ['id' => $id];
    }

    public function chunk(Request $r, string $code, int $id)
    {
        [, $m] = $this->meeting($code);
        $this->host($r, $m);
        $rec = DB::table('vis_recordings')->where('meeting_id', $m->id)->where('id', $id)->first();
        abort_unless($rec && $rec->status === 'recording', 409, 'Enregistrement clos.');
        $seq = (int) $r->query('seq', $rec->chunks);
        abort_if($seq < $rec->chunks, 409, 'Morceau déjà reçu.');
        $bytes = $r->getContent();
        abort_if(strlen($bytes) > 40 * 1024 * 1024, 413);
        $fh = fopen(Storage::disk('local')->path($rec->path), 'ab');
        fwrite($fh, $bytes);
        fclose($fh);
        DB::table('vis_recordings')->where('id', $id)->update(['size' => $rec->size + strlen($bytes), 'chunks' => $seq + 1, 'updated_at' => now()]);

        return ['received' => $seq + 1];
    }

    public function stopRecording(Request $r, string $code, int $id)
    {
        [, $m] = $this->meeting($code);
        $p = $this->host($r, $m);
        DB::table('vis_recordings')->where('meeting_id', $m->id)->where('id', $id)->update(['status' => 'ready', 'duration_seconds' => max(0, (int) $r->input('duration')), 'updated_at' => now()]);
        $m->forceFill(['recording_state' => null])->save();
        $this->svc->system($m, 'record_off', $p);

        return response()->noContent();
    }

    /** Compte rendu partagé par lien. */
    public function report(string $token)
    {
        $r = DB::table('vis_reports')->where('token', $token)->first();
        abort_unless($r, 404);
        $m = Meeting::withoutGlobalScopes()->find($r->meeting_id);
        $c = Company::find($r->company_id);
        abort_unless($m && $c, 404);

        return response('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Compte rendu — '.e($m->title).'</title>'
            .'<meta name="robots" content="noindex"></head><body style="background:#F1F5F9;margin:0;padding:24px 14px">'.app(ReportService::class)->html($c, $m, $r).'</body></html>');
    }
}
