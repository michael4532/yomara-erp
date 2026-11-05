<?php

namespace App\Modules\Visio;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Visio\Models\Meeting;
use App\Modules\Visio\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VisioController extends Controller
{
    public function __construct(protected VisioService $svc, protected ReportService $reports) {}

    protected function visible(Request $r)
    {
        $u = $r->user();
        $q = Meeting::query();
        if (! $u->hasPermission('visio.manage')) {
            $q->where(fn ($w) => $w->where('host_id', $u->id)->orWhereIn('id', Participant::where('user_id', $u->id)->select('meeting_id')));
        }

        return $q;
    }

    protected function find(Request $r, int $id, bool $manage = false): Meeting
    {
        $m = $this->visible($r)->findOrFail($id);
        if ($manage) {
            abort_unless($m->host_id === $r->user()->id || $r->user()->hasPermission('visio.manage')
                || Participant::where('meeting_id', $m->id)->where('user_id', $r->user()->id)->where('role', 'cohost')->exists(), 403, 'Seul l\'organisateur peut modifier cette réunion.');
        }

        return $m;
    }

    protected function row(Meeting $m): array
    {
        $counts = Participant::where('meeting_id', $m->id)->selectRaw("count(*) as invited, sum(case when first_joined_at is not null then 1 else 0 end) as present, sum(case when status = 'admitted' and last_seen_at > ? then 1 else 0 end) as online", [now()->subSeconds(25)])->first();

        return [
            'id' => $m->id, 'type' => $m->type, 'code' => $m->code, 'title' => $m->title, 'description' => $m->description, 'status' => $m->status,
            'starts_at' => $m->starts_at?->format('Y-m-d H:i:s'), 'duration_minutes' => $m->duration_minutes, 'started_at' => $m->started_at?->format('Y-m-d H:i:s'),
            'ended_at' => $m->ended_at?->format('Y-m-d H:i:s'), 'host_id' => $m->host_id, 'host' => $m->host_id ? DB::table('users')->where('id', $m->host_id)->value('name') : null,
            'options' => array_merge(VisioService::DEFAULTS, $m->options ?? []), 'password' => $m->password, 'url' => $m->roomUrl(), 'provider' => $m->provider, 'switches' => $m->switches,
            'slug' => $m->slug, 'registration_url' => $m->registrationUrl(), 'capacity' => $m->capacity, 'registration_open' => $m->registration_open, 'replay_public' => $m->replay_public,
            'create_leads' => $m->create_leads, 'color' => $m->color,
            'invited' => (int) ($counts->invited ?? 0), 'present' => (int) ($counts->present ?? 0), 'online' => (int) ($counts->online ?? 0),
            'has_report' => DB::table('vis_reports')->where('meeting_id', $m->id)->exists(),
            'recordings' => DB::table('vis_recordings')->where('meeting_id', $m->id)->where('size', '>', 0)->count(),
        ];
    }

    public function index(Request $r)
    {
        $u = $r->user();
        $c = app('tenant');
        $scope = $r->input('scope', 'upcoming');
        $q = $this->visible($r)->where('type', $r->input('type') === 'webinar' ? 'webinar' : 'meeting');
        match ($scope) {
            'past' => $q->whereIn('status', ['ended', 'cancelled'])->orderByDesc(DB::raw('coalesce(ended_at, starts_at)')),
            'all' => $q->orderByDesc('id'),
            default => $q->whereIn('status', ['scheduled', 'live'])->orderByRaw("case status when 'live' then 0 else 1 end")->orderBy('starts_at'),
        };
        if ($s = trim((string) $r->input('q'))) {
            $q->where('title', 'like', '%'.$s.'%');
        }
        $meetings = $q->limit(200)->get()->map(fn ($m) => $this->row($m));
        $settings = $this->svc->settings($c);
        $month = now()->startOfMonth();

        return [
            'meetings' => $meetings,
            'stats' => [
                'upcoming' => $this->visible($r)->where('status', 'scheduled')->where('starts_at', '>=', now())->count(),
                'live' => $this->visible($r)->where('status', 'live')->count(),
                'month' => $this->visible($r)->where('status', 'ended')->where('ended_at', '>=', $month)->count(),
                'minutes' => (int) round(DB::table('vis_usage')->where('company_id', $c->id)->where('month', now()->format('Y-m'))->sum('seconds') / 60),
                'webinar_registrants' => (int) Participant::where('company_id', $c->id)->where('role', 'attendee')->where('created_at', '>=', $month)->count(),
            ],
            'users' => User::where('company_id', $c->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            // Sans compte ZEGOCLOUD / Agora, les petites réunions passent en pair-à-pair (jusqu'à 6 personnes)
            'cloud' => $this->svc->configured($settings, 'zego') || $this->svc->configured($settings, 'agora'),
            'configured' => $this->svc->configured($settings, 'zego') || $this->svc->configured($settings, 'agora') || $this->svc->configured($settings, 'p2p'),
            'defaults' => $settings['defaults'],
            'quota' => $this->svc->quota($c),
            'can_buy' => $u->hasPermission('core.settings.manage'),
            'can_webinar' => $u->hasPermission('visio.webinar') || $u->hasPermission('visio.manage'),
            'can_manage' => $u->hasPermission('visio.manage'),
        ];
    }

    protected function rules(?Meeting $m = null): array
    {
        return [
            'type' => ['nullable', Rule::in(['meeting', 'webinar'])],
            'title' => [$m ? 'sometimes' : 'required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'between:5,720'],
            'options' => ['nullable', 'array'],
            'password' => ['nullable', 'string', 'max:40'],
            'user_ids' => ['nullable', 'array'], 'user_ids.*' => ['integer'],
            'guests' => ['nullable', 'array', 'max:500'], 'guests.*.name' => ['nullable', 'string', 'max:120'], 'guests.*.email' => ['nullable', 'email', 'max:190'],
            'guests.*.organization' => ['nullable', 'string', 'max:120'],
            'send_invites' => ['nullable', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'registration_open' => ['nullable', 'boolean'], 'replay_public' => ['nullable', 'boolean'], 'create_leads' => ['nullable', 'boolean'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'], 'color' => ['nullable', 'string', 'max:9'],
            'partner_id' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->rules());
        $c = app('tenant');
        $type = $d['type'] ?? 'meeting';
        abort_if($type === 'webinar' && ! ($r->user()->hasPermission('visio.webinar') || $r->user()->hasPermission('visio.manage')), 403, 'Droit « webinaires » requis.');
        $defaults = $this->svc->settings($c)['defaults'];
        $m = new Meeting;
        $m->fill([
            'type' => $type, 'code' => VisioService::code(), 'title' => $d['title'], 'description' => $d['description'] ?? null,
            'starts_at' => $d['starts_at'] ?? now(), 'duration_minutes' => $d['duration_minutes'] ?? ($type === 'webinar' ? 90 : 60),
            'options' => array_merge($defaults, array_intersect_key((array) ($d['options'] ?? []), VisioService::DEFAULTS), $type === 'webinar' ? ['lobby' => false, 'guests' => false] : []),
            'password' => $d['password'] ?? null, 'host_id' => $r->user()->id, 'partner_id' => $d['partner_id'] ?? null,
            'capacity' => $d['capacity'] ?? 0, 'registration_open' => $d['registration_open'] ?? true, 'replay_public' => $d['replay_public'] ?? true,
            'create_leads' => $d['create_leads'] ?? false, 'color' => $d['color'] ?? null,
        ]);
        if ($type === 'webinar') {
            $m->slug = $this->slug($d['slug'] ?? $d['title']);
        }
        $m->save();
        $new = $this->svc->syncInvitees($m, $d['user_ids'] ?? [], $d['guests'] ?? []);
        $sent = ($d['send_invites'] ?? true) ? $this->svc->sendInvitations($c, $m, $new, $r->user()->id) : 0;
        $this->svc->syncAgenda($c, $m);
        $join = $this->svc->participantFor($m, $r->user());

        return response()->json(['data' => $this->row($m), 'sent' => $sent, 'join_url' => $m->roomUrl($join->token)], 201);
    }

    /** Réunion instantanée : salle ouverte tout de suite, lien à partager. */
    public function instant(Request $r)
    {
        $r->merge(['title' => $r->input('title') ?: 'Réunion de '.$r->user()->name, 'starts_at' => now()->toDateTimeString(), 'send_invites' => true]);

        return $this->store($r);
    }

    protected function slug(string $base, ?int $except = null): string
    {
        $slug = Str::slug(mb_substr($base, 0, 60)) ?: 'webinaire';
        $try = $slug;
        $i = 2;
        while (DB::table('vis_meetings')->where('slug', $try)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            $try = $slug.'-'.$i++;
        }

        return $try;
    }

    public function show(Request $r, int $id)
    {
        $m = $this->find($r, $id);
        $report = DB::table('vis_reports')->where('meeting_id', $m->id)->first();
        $me = Participant::where('meeting_id', $m->id)->where('user_id', $r->user()->id)->first();

        return [
            'data' => $this->row($m),
            'participants' => Participant::where('meeting_id', $m->id)->orderByRaw("case role when 'host' then 0 when 'cohost' then 1 when 'panelist' then 2 when 'member' then 3 when 'guest' then 4 else 5 end")
                ->orderBy('name')->limit(2000)->get()->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'email' => $p->email, 'phone' => $p->phone, 'organization' => $p->organization, 'role' => $p->role, 'status' => $p->status,
                    'internal' => (bool) $p->user_id, 'online' => $p->online(), 'minutes' => (int) round($p->seconds_total / 60),
                    'joined' => $p->first_joined_at?->format('Y-m-d H:i'), 'invited_at' => $p->invited_at?->format('Y-m-d H:i'), 'registered_at' => $p->created_at?->format('Y-m-d H:i'),
                    'source' => $p->source, 'link' => $m->roomUrl($p->token),
                ]),
            'report' => $report ? [
                'summary' => $report->summary, 'decisions' => json_decode((string) $report->decisions, true) ?: [], 'actions' => json_decode((string) $report->actions, true) ?: [],
                'attendance' => json_decode((string) $report->attendance, true) ?: [], 'stats' => json_decode((string) $report->stats, true) ?: [], 'transcript' => $report->transcript,
                'source' => $report->source, 'sent_at' => $report->sent_at, 'todos_created' => (int) $report->todos_created, 'url' => url('/visio/rapport/'.$report->token),
                'generated_at' => (string) $report->updated_at,
            ] : null,
            'recordings' => DB::table('vis_recordings')->where('meeting_id', $m->id)->orderBy('id')->get()->map(fn ($x) => ['id' => $x->id, 'size' => (int) $x->size,
                'duration' => (int) $x->duration_seconds, 'status' => $x->status, 'mime' => $x->mime, 'created_at' => (string) $x->created_at,
                'public_url' => $m->isWebinar() && $m->slug ? url('/webinaire/'.$m->slug.'/replay') : null])->values(),
            'live' => $m->status === 'live' ? $this->reports->stats($m) : null,
            'join_url' => $m->roomUrl(($me ?? $this->svc->participantFor($m, $r->user()))->token),
            'can_manage' => $m->host_id === $r->user()->id || $r->user()->hasPermission('visio.manage'),
        ];
    }

    public function update(Request $r, int $id)
    {
        $m = $this->find($r, $id, true);
        $d = $r->validate($this->rules($m));
        $c = app('tenant');
        $before = [$m->starts_at?->toDateTimeString(), $m->duration_minutes];
        $fill = array_intersect_key($d, array_flip(['title', 'description', 'starts_at', 'duration_minutes', 'password', 'capacity', 'registration_open', 'replay_public', 'create_leads', 'color', 'partner_id']));
        if (isset($d['options'])) {
            $fill['options'] = array_merge($m->options ?? [], array_intersect_key($d['options'], VisioService::DEFAULTS));
        }
        if ($m->isWebinar() && ! empty($d['slug']) && $d['slug'] !== $m->slug) {
            $fill['slug'] = $this->slug($d['slug'], $m->id);
        }
        $m->fill($fill)->save();
        $new = $this->svc->syncInvitees($m, $d['user_ids'] ?? [], $d['guests'] ?? []);
        $sent = 0;
        if ($d['send_invites'] ?? true) {
            $sent = $this->svc->sendInvitations($c, $m, $new, $r->user()->id);
            // Horaire modifié : on renvoie l'invitation (mise à jour du calendrier) aux invités déjà prévenus
            if ($before !== [$m->starts_at?->toDateTimeString(), $m->duration_minutes]) {
                $sent += $this->svc->sendInvitations($c, $m, Participant::where('meeting_id', $m->id)->whereNotNull('invited_at')->whereNotIn('id', collect($new)->pluck('id'))->get(), $r->user()->id);
            }
        }
        $this->svc->syncAgenda($c, $m);

        return ['data' => $this->row($m), 'sent' => $sent];
    }

    public function destroy(Request $r, int $id)
    {
        $m = $this->find($r, $id, true);
        $c = app('tenant');
        if ($m->status === 'scheduled' && $m->started_at === null) {
            $m->forceFill(['status' => 'cancelled'])->save();
            $this->svc->syncAgenda($c, $m);
            if ($r->boolean('purge')) {
                $this->purge($m);
            }

            return response()->noContent();
        }
        abort_if($m->status === 'live', 422, 'Terminez d\'abord la réunion en cours.');
        $this->purge($m);

        return response()->noContent();
    }

    protected function purge(Meeting $m): void
    {
        foreach (DB::table('vis_recordings')->where('meeting_id', $m->id)->pluck('path') as $p) {
            Storage::disk('local')->delete($p);
        }
        foreach (['vis_recordings', 'vis_reports', 'vis_polls', 'vis_questions', 'vis_feed', 'vis_participants'] as $t) {
            DB::table($t)->where('meeting_id', $m->id)->delete();
        }
        if ($m->agenda_event_id && class_exists(\App\Modules\Agenda\Models\Event::class)) {
            rescue(fn () => \App\Modules\Agenda\Models\Event::find($m->agenda_event_id)?->delete(), null, false);
        }
        $m->delete();
    }

    public function invite(Request $r, int $id)
    {
        $m = $this->find($r, $id, true);
        $d = $r->validate(['user_ids' => 'nullable|array', 'user_ids.*' => 'integer', 'guests' => 'nullable|array|max:500', 'guests.*.name' => 'nullable|string|max:120',
            'guests.*.email' => 'nullable|email|max:190', 'guests.*.role' => 'nullable|in:guest,panelist,cohost,attendee', 'resend' => 'nullable|array', 'resend.*' => 'integer']);
        $c = app('tenant');
        $new = $this->svc->syncInvitees($m, $d['user_ids'] ?? [], $d['guests'] ?? []);
        $again = ! empty($d['resend']) ? Participant::where('meeting_id', $m->id)->whereIn('id', $d['resend'])->get()->all() : [];
        $sent = $this->svc->sendInvitations($c, $m, array_merge($new, $again), $r->user()->id);
        $this->svc->syncAgenda($c, $m);

        return ['added' => count($new), 'sent' => $sent];
    }

    public function removeParticipant(Request $r, int $id, int $pid)
    {
        $m = $this->find($r, $id, true);
        $p = Participant::where('meeting_id', $m->id)->findOrFail($pid);
        abort_if($p->role === 'host', 422, 'Impossible de retirer l\'organisateur.');
        $p->first_joined_at ? $p->forceFill(['status' => 'removed'])->save() : $p->delete();

        return response()->noContent();
    }

    public function join(Request $r, int $id)
    {
        $m = $this->find($r, $id);
        abort_if($m->status === 'cancelled', 422, 'Cette réunion est annulée.');
        $p = $this->svc->participantFor($m, $r->user());
        if ($m->status === 'ended' && $p->isHost()) {
            $this->svc->reopen($m);
        }

        return ['url' => $m->roomUrl($p->token), 'code' => $m->code];
    }

    public function end(Request $r, int $id)
    {
        $m = $this->find($r, $id, true);
        $c = app('tenant');
        $this->svc->end($c, $m);
        DB::table('vis_feed')->insert(['meeting_id' => $m->id, 'kind' => 'control', 'body' => 'ended', 'created_at' => now()]);
        $this->reports->generate($c, $m);

        return ['data' => $this->row($m)];
    }

    public function regenerate(Request $r, int $id)
    {
        $m = $this->find($r, $id, true);
        abort_if($m->status !== 'ended', 422, 'Le compte rendu est produit à la fin de la réunion.');
        $this->reports->generate(app('tenant'), $m, $r->boolean('ai', true));

        return $this->show($r, $id);
    }

    public function sendReport(Request $r, int $id)
    {
        $m = $this->find($r, $id, true);
        abort_if($m->status !== 'ended', 422, 'Le compte rendu est produit à la fin de la réunion.');
        $sent = $this->reports->send(app('tenant'), $m, $r->boolean('externals', true), $r->user()->id);
        abort_if($sent === 0, 422, 'Aucun e-mail envoyé : vérifiez la messagerie (Paramètres › Messagerie) et les adresses des participants.');

        return ['sent' => $sent];
    }

    public function updateReport(Request $r, int $id)
    {
        $m = $this->find($r, $id, true);
        $d = $r->validate(['summary' => 'nullable|string|max:20000', 'decisions' => 'nullable|array', 'decisions.*' => 'string|max:500', 'actions' => 'nullable|array',
            'actions.*.title' => 'required|string|max:250', 'actions.*.owner' => 'nullable|string|max:120', 'actions.*.due' => 'nullable|date']);
        $row = ['updated_at' => now()];
        foreach (['summary'] as $k) {
            if (array_key_exists($k, $d)) {
                $row[$k] = $d[$k];
            }
        }
        foreach (['decisions', 'actions'] as $k) {
            if (array_key_exists($k, $d)) {
                $row[$k] = json_encode(array_values($d[$k] ?? []), JSON_UNESCAPED_UNICODE);
            }
        }
        abort_unless(DB::table('vis_reports')->where('meeting_id', $m->id)->update($row), 404);

        return $this->show($r, $id);
    }

    public function registrants(Request $r, int $id)
    {
        $m = $this->find($r, $id, true);
        $rows = Participant::where('meeting_id', $m->id)->orderBy('created_at')->get();
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Nom', 'E-mail', 'Téléphone', 'Organisation', 'Rôle', 'Inscription', 'Présent', 'Arrivée', 'Minutes', 'Provenance'], ';');
        $roles = ['host' => 'Organisateur', 'cohost' => 'Co-organisateur', 'panelist' => 'Intervenant', 'attendee' => 'Participant', 'guest' => 'Invité', 'member' => 'Membre'];
        foreach ($rows as $p) {
            fputcsv($out, \App\Support\SafeCell::csvRow([$p->name, $p->email, $p->phone, $p->organization, $roles[$p->role] ?? $p->role, $p->created_at?->format('d/m/Y H:i'), $p->first_joined_at ? 'oui' : 'non',
                $p->first_joined_at?->format('d/m/Y H:i'), (int) round($p->seconds_total / 60), $p->source]), ';');
        }
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="participants-'.Str::slug($m->title).'.csv"']);
    }

    public function recording(Request $r, int $id)
    {
        $rec = DB::table('vis_recordings')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($rec && Storage::disk('local')->exists($rec->path), 404);
        $this->find($r, (int) $rec->meeting_id);
        $m = Meeting::find($rec->meeting_id);

        return response()->file(Storage::disk('local')->path($rec->path), ['Content-Type' => $rec->mime,
            'Content-Disposition' => ($r->boolean('download') ? 'attachment' : 'inline').'; filename="'.Str::slug($m->title).'-'.$rec->id.'.'.($rec->mime === 'video/mp4' ? 'mp4' : 'webm').'"']);
    }

    public function deleteRecording(Request $r, int $id)
    {
        $rec = DB::table('vis_recordings')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($rec, 404);
        $this->find($r, (int) $rec->meeting_id, true);
        Storage::disk('local')->delete($rec->path);
        DB::table('vis_recordings')->where('id', $id)->delete();

        return response()->noContent();
    }

    public function ics(Request $r, int $id)
    {
        $m = $this->find($r, $id);

        return response($this->svc->ics($m, app('tenant'), Participant::where('meeting_id', $m->id)->where('user_id', $r->user()->id)->first()), 200,
            ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="reunion.ics"']);
    }

    // ---------------------------------------------------------------- Paramètres

    public function settings(Request $r)
    {
        return $this->svc->publicSettings(app('tenant')) + ['quota' => $this->svc->quota(app('tenant')), 'can_configure_providers' => (bool) $r->user()->is_super_admin];
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate([
            'zego_app_id' => 'nullable|string|max:20', 'zego_secret' => 'nullable|string|max:80', 'zego_server' => 'nullable|string|max:190',
            'agora_app_id' => 'nullable|string|max:40', 'agora_certificate' => 'nullable|string|max:80',
            'zego_budget_minutes' => 'nullable|integer|min:0', 'agora_budget_minutes' => 'nullable|integer|min:0',
            'priority' => 'nullable', 'p2p_fallback' => 'nullable|boolean', 'defaults' => 'nullable|array',
            'turn_url' => 'nullable|string|max:190', 'turn_username' => 'nullable|string|max:120', 'turn_credential' => 'nullable|string|max:190',
        ]);
        // Les comptes ZEGOCLOUD / Agora sont ceux de la plateforme (réglés par le super admin) : la société ne règle que ses options
        if (! $r->user()->is_super_admin) {
            $d = array_intersect_key($d, array_flip(['defaults']));
        }
        try {
            return $this->svc->saveSettings(app('tenant'), $d) + ['quota' => $this->svc->quota(app('tenant')), 'can_configure_providers' => (bool) $r->user()->is_super_admin];
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function testProvider(string $provider)
    {
        abort_unless(in_array($provider, ['zego', 'agora'], true), 404);

        return $this->svc->test(app('tenant'), $provider);
    }

    public function resetProvider(string $provider)
    {
        DB::table('vis_provider_health')->where('company_id', app('tenant')->id)->where('provider', $provider)->update(['down_until' => null, 'failures' => 0, 'updated_at' => now()]);

        return $this->svc->publicSettings(app('tenant'));
    }
}
