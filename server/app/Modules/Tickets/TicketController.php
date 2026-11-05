<?php

namespace App\Modules\Tickets;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Modules\Tickets\Models\Department;
use App\Modules\Tickets\Models\Event;
use App\Modules\Tickets\Models\File;
use App\Modules\Tickets\Models\Member;
use App\Modules\Tickets\Models\Report;
use App\Modules\Tickets\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function __construct(protected TicketService $service) {}

    protected function access(Request $r): TicketAccess
    {
        return new TicketAccess($r->user());
    }

    protected function fail(\DomainException $e)
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    // ---------- Organisation ----------

    public function setup(Request $r)
    {
        $a = $this->access($r);
        $deps = Department::with(['members.user:id,name'])->orderBy('parent_id')->orderBy('name')->get();

        return [
            'departments' => $deps->map(fn (Department $d) => $d->toArray() + [
                'head_name' => $d->head_user_id ? User::find($d->head_user_id)?->name : null,
                'members' => $d->members->map(fn ($m) => ['user_id' => $m->user_id, 'name' => $m->user?->name, 'role' => $m->role])->values(),
                'sla' => array_replace(Department::DEFAULT_SLA, (array) $d->sla_hours),
            ]),
            'me' => [
                'memberships' => $a->memberships,
                'managed' => $a->managed,
                'all' => $a->all,
                'is_manager' => $a->isManager(),
                'can_configure' => $r->user()->hasPermission('tickets.manage'),
            ],
            'users' => User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => Ticket::STATUSES,
            'urgencies' => Ticket::URGENCIES,
            'member_roles' => Member::ROLES,
        ];
    }

    protected function departmentRules(?Department $d = null): array
    {
        $c = app('tenant')->id;

        return [
            'name' => [$d ? 'sometimes' : 'required', 'string', 'max:120'],
            'code' => 'nullable|string|max:20',
            'parent_id' => ['nullable', 'integer', Rule::exists('tk_departments', 'id')->where('company_id', $c), $d ? Rule::notIn([$d->id]) : 'integer'],
            'head_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $c)],
            'supervisor_ids' => 'nullable|array',
            'supervisor_ids.*' => ['integer', Rule::exists('users', 'id')->where('company_id', $c)],
            'accepts_tickets' => 'sometimes|boolean',
            'categories' => 'nullable|array|max:40',
            'categories.*' => 'string|max:100',
            'sla_hours' => 'nullable|array',
            'sla_hours.*' => 'integer|min:1|max:8760',
            'color' => 'nullable|string|max:9',
            'active' => 'sometimes|boolean',
        ];
    }

    public function storeDepartment(Request $r)
    {
        $d = Department::create($r->validate($this->departmentRules()));
        $this->syncHead($d);
        ActivityLog::record('tickets.department.created', $d, ['name' => $d->name]);

        return response()->json($d, 201);
    }

    public function updateDepartment(Request $r, int $id)
    {
        $d = Department::findOrFail($id);
        $data = $r->validate($this->departmentRules($d));
        // Pas de boucle dans la hiérarchie
        if (! empty($data['parent_id'])) {
            $p = Department::find($data['parent_id']);
            for ($i = 0; $p && $i < 50; $i++) {
                if ($p->id === $d->id) {
                    return response()->json(['message' => 'Un service ne peut pas dépendre de lui-même.'], 422);
                }
                $p = $p->parent_id ? Department::find($p->parent_id) : null;
            }
        }
        $d->update($data);
        $this->syncHead($d);

        return $d->fresh();
    }

    /** Le chef désigné est membre (rôle chef) de son service. */
    protected function syncHead(Department $d): void
    {
        if ($d->head_user_id) {
            Member::updateOrCreate(['department_id' => $d->id, 'user_id' => $d->head_user_id], ['role' => 'head']);
        }
    }

    public function destroyDepartment(int $id)
    {
        $d = Department::findOrFail($id);
        if (Ticket::where('department_id', $d->id)->exists()) {
            $d->update(['active' => false, 'accepts_tickets' => false]);

            return ['ok' => true, 'archived' => true];
        }
        Department::where('parent_id', $d->id)->update(['parent_id' => $d->parent_id]);
        Member::where('department_id', $d->id)->delete();
        $d->delete();

        return ['ok' => true];
    }

    public function members(Request $r, int $id)
    {
        $d = Department::findOrFail($id);
        $data = $r->validate([
            'members' => 'present|array',
            'members.*.user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('company_id', app('tenant')->id)],
            'members.*.role' => ['required', Rule::in(array_keys(Member::ROLES))],
        ]);
        $keep = [];
        foreach ($data['members'] as $m) {
            Member::updateOrCreate(['department_id' => $d->id, 'user_id' => $m['user_id']], ['role' => $m['role']]);
            $keep[] = $m['user_id'];
            if ($m['role'] === 'head' && $d->head_user_id !== $m['user_id']) {
                $d->update(['head_user_id' => $m['user_id']]);
            }
        }
        Member::where('department_id', $d->id)->whereNotIn('user_id', $keep)->delete();
        if ($d->head_user_id && ! in_array($d->head_user_id, $keep, true)) {
            $d->update(['head_user_id' => null]);
        }

        return $this->setup($r);
    }

    // ---------- Tickets ----------

    public function index(Request $r)
    {
        $a = $this->access($r);
        $q = $a->scope(Ticket::query())->with(['department:id,name,color', 'requester:id,name', 'assignee:id,name', 'requesterDepartment:id,name']);
        $uid = $r->user()->id;
        match ($r->query('box', 'all')) {
            'mine' => $q->where('requester_id', $uid),
            'todo' => $q->where(fn ($w) => $w->where('assignee_id', $uid)->orWhere(fn ($x) => $x->whereNull('assignee_id')->whereIn('department_id', $a->memberOf())))
                ->whereIn('status', Ticket::ACTIVE),
            'department' => $q->where(fn ($w) => $w->whereIn('department_id', $a->all ? Department::pluck('id') : $a->managed)
                ->orWhereIn('requester_department_id', $a->all ? Department::pluck('id') : $a->managed)),
            default => null,
        };
        if ($s = $r->query('status')) {
            $s === 'active' ? $q->whereIn('status', Ticket::ACTIVE) : $q->where('status', $s);
        }
        if ($d = $r->query('department_id')) {
            $q->where(fn ($w) => $w->where('department_id', $d)->orWhere('requester_department_id', $d));
        }
        if ($u = $r->query('urgency')) {
            $q->where('urgency', $u);
        }
        if ($term = $r->query('q')) {
            $q->where(fn ($w) => $w->where('subject', 'like', "%$term%")->orWhere('number', 'like', "%$term%"));
        }
        $rows = $q->orderByRaw("CASE WHEN status IN ('done','closed','cancelled','rejected') THEN 1 ELSE 0 END")
            ->orderByRaw("CASE urgency WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByDesc('submitted_at')->limit(300)->get();

        return ['data' => $rows->map->toApi()];
    }

    protected function ticket(Request $r, int $id): array
    {
        $a = $this->access($r);
        $t = Ticket::findOrFail($id);
        abort_unless($a->canSee($t), 403, 'Ce ticket ne fait pas partie de votre périmètre.');

        return [$t, $a];
    }

    public function show(Request $r, int $id)
    {
        [$t, $a] = $this->ticket($r, $id);
        $handler = $a->canHandle($t);
        $events = $t->events()->with('user:id,name')->get()->filter(fn (Event $e) => $e->type !== 'note' || $handler)->values();
        $members = Member::where('department_id', $t->department_id)->with('user:id,name')->get()
            ->map(fn ($m) => ['user_id' => $m->user_id, 'name' => $m->user?->name, 'role' => $m->role, 'role_label' => Member::ROLES[$m->role] ?? $m->role]);

        return [
            'ticket' => $t->toApi(),
            'events' => $events,
            'files' => $t->files()->latest('id')->get(),
            'members' => $members,
            'can' => [
                'handle' => $handler,
                'manage' => $a->canManage($t),
                'requester' => $t->requester_id === $r->user()->id,
            ],
        ];
    }

    public function store(Request $r)
    {
        $c = app('tenant')->id;
        $data = $r->validate([
            'department_id' => ['required', 'integer', Rule::exists('tk_departments', 'id')->where('company_id', $c)],
            'subject' => 'required|string|max:200',
            'description' => 'nullable|string|max:10000',
            'category' => 'nullable|string|max:100',
            'urgency' => ['nullable', Rule::in(array_keys(Ticket::URGENCIES))],
            'assignee_id' => ['nullable', 'integer'],
            'requester_department_id' => ['nullable', 'integer', Rule::exists('tk_departments', 'id')->where('company_id', $c)],
        ]);
        try {
            $t = $this->service->create($r->user(), $data);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['ticket' => $t->toApi()], 201);
    }

    public function act(Request $r, int $id, string $action)
    {
        [$t, $a] = $this->ticket($r, $id);
        $data = $r->validate([
            'reason' => 'nullable|string|max:2000',
            'resolution' => 'nullable|string|max:5000',
            'comment' => 'nullable|string|max:2000',
            'purchase' => 'nullable|boolean',
            'rating' => 'nullable|integer|min:1|max:5',
            'assignee_id' => 'nullable|integer',
        ]);
        try {
            $this->service->act($t, $r->user(), $a, $action, $data);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return $this->show($r, $id);
    }

    public function comment(Request $r, int $id)
    {
        [$t, $a] = $this->ticket($r, $id);
        $data = $r->validate(['body' => 'required|string|max:5000', 'internal' => 'nullable|boolean']);
        $this->service->comment($t, $r->user(), $a, $data['body'], (bool) ($data['internal'] ?? false));

        return $this->show($r, $id);
    }

    public function upload(Request $r, int $id)
    {
        [$t] = $this->ticket($r, $id);
        $r->validate(['file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,zip']);
        $f = $r->file('file');
        $path = $f->storeAs('tickets/'.app('tenant')->id.'/'.$t->id, Str::random(12).'.'.$this->safeExt($f), 'local');
        $file = File::create(['ticket_id' => $t->id, 'user_id' => $r->user()->id, 'name' => mb_substr($f->getClientOriginalName(), 0, 200), 'path' => $path, 'size' => $f->getSize()]);
        Event::create(['ticket_id' => $t->id, 'user_id' => $r->user()->id, 'type' => 'comment', 'body' => 'Pièce jointe : '.$file->name, 'created_at' => now()]);

        return response()->json($file, 201);
    }

    /** Extension enregistrée : déduite du contenu et limitée à la liste autorisée (jamais .html, .svg ou .php du client). */
    protected function safeExt(\Illuminate\Http\UploadedFile $f): string
    {
        $ok = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];
        $ext = strtolower((string) $f->guessExtension());
        if (in_array($ext, $ok, true)) {
            return $ext;
        }
        $client = strtolower($f->getClientOriginalExtension());

        return in_array($client, $ok, true) ? $client : 'bin';
    }

    public function download(Request $r, int $id, int $fileId)
    {
        [$t] = $this->ticket($r, $id);
        $file = File::where('ticket_id', $t->id)->findOrFail($fileId);

        return Storage::disk('local')->download($file->path, $file->name);
    }

    // ---------- Statistiques et rapports ----------

    public function stats(Request $r, TicketStats $stats)
    {
        $a = $this->access($r);
        $allowed = $a->statsDepartments();
        if ($allowed !== null && $allowed === []) {
            // Collaborateur : ses propres chiffres sur les services dont il est membre
            $allowed = $a->memberOf();
        }
        $deps = $allowed;
        if ($d = $r->query('department_id')) {
            $d = (int) $d;
            abort_unless($allowed === null || in_array($d, $allowed, true), 403);
            $deps = [$d];
        }
        $from = Carbon::parse($r->query('from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($r->query('to', now()->toDateString()))->endOfDay();
        $data = $stats->compute($deps, $from, $to);
        if (! $a->isManager()) {
            $data['ranking'] = collect($data['ranking'])->where('user_id', $r->user()->id)->values();
        }

        return $data + ['departments' => Department::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: [0]))->orderBy('name')->get(['id', 'name'])];
    }

    public function reports(Request $r)
    {
        $a = $this->access($r);
        $q = Report::orderByDesc('from')->orderBy('department_id');
        if (! $a->all) {
            $q->whereIn('department_id', $a->managed ?: [0]);
        }

        return ['data' => $q->limit(100)->get(['id', 'department_id', 'period', 'from', 'to', 'summary', 'created_at'])
            ->map(fn ($rep) => $rep->toArray() + ['department' => $rep->department_id ? Department::find($rep->department_id)?->name : 'Toute l\'entreprise'])];
    }

    public function report(Request $r, int $id)
    {
        $a = $this->access($r);
        $rep = Report::findOrFail($id);
        abort_unless($a->all || in_array($rep->department_id, $a->managed, true), 403);

        return $rep->toArray() + ['department' => $rep->department_id ? Department::find($rep->department_id)?->name : 'Toute l\'entreprise'];
    }

    public function generate(Request $r, TicketReports $reports)
    {
        $a = $this->access($r);
        abort_unless($a->all || $r->user()->hasPermission('tickets.manage'), 403);
        $period = $r->input('period', 'week') === 'month' ? 'month' : 'week';
        $done = $reports->generate(app('tenant'), $period);

        return ['generated' => count($done)];
    }
}
