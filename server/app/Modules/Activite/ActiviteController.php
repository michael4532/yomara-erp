<?php

namespace App\Modules\Activite;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActiviteController extends Controller
{
    public function __construct(private ReportService $svc) {}

    protected function run(callable $fn)
    {
        try {
            return DB::transaction($fn);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function period(Request $r): array
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        if (empty($d['from'])) {
            return ReportService::week();
        }

        return [substr($d['from'], 0, 10), substr($d['to'] ?? $d['from'], 0, 10)];
    }

    /** Rapport de la personne connectée pour une période (semaine en cours par défaut). */
    public function current(Request $r)
    {
        [$from, $to] = $this->period($r);
        $c = app('tenant');

        return ['data' => $this->svc->toApi($c, $this->svc->find($c, $r->user(), $from, $to), $r->user(), $from, $to, $r->user())];
    }

    public function save(Request $r)
    {
        [$from, $to] = $this->period($r);
        $d = $r->validate(['summary' => 'nullable|string|max:20000', 'difficulties' => 'nullable|string|max:20000', 'next_steps' => 'nullable|string|max:20000', 'excluded' => 'nullable|array', 'excluded.*' => 'string|max:80']);

        return $this->run(function () use ($r, $from, $to, $d) {
            $c = app('tenant');
            $rep = $this->svc->save($c, $r->user(), $from, $to, $d);

            return ['data' => $this->svc->toApi($c, $rep, $r->user(), $from, $to, $r->user())];
        });
    }

    protected function load(Request $r, int $id): array
    {
        $c = app('tenant');
        $rep = DB::table('ra_reports')->where('company_id', $c->id)->where('id', $id)->first();
        abort_unless($rep, 404);
        $u = $r->user();
        abort_unless((int) $rep->user_id === $u->id || (int) $rep->supervisor_id === $u->id || $u->hasPermission('activite.view_all'), 403, 'Accès refusé.');

        return [$c, $rep, User::find($rep->user_id)];
    }

    public function show(Request $r, int $id)
    {
        [$c, $rep, $author] = $this->load($r, $id);

        return ['data' => $this->svc->toApi($c, $rep, $author, $rep->period_start, $rep->period_end, $r->user())];
    }

    public function index(Request $r)
    {
        $c = app('tenant');
        $u = $r->user();
        $box = $r->query('box', 'mine');
        $q = DB::table('ra_reports')->where('company_id', $c->id);
        if ($box === 'team') {
            $q->where('status', '!=', 'draft')->when(! $u->hasPermission('activite.view_all'), fn ($w) => $w->where('supervisor_id', $u->id));
        } else {
            $q->where('user_id', $u->id);
        }
        $rows = $q->orderByRaw("CASE WHEN status = 'sent' THEN 0 ELSE 1 END")->orderByDesc('period_start')->limit(200)->get();
        $names = User::whereIn('id', $rows->pluck('user_id')->merge($rows->pluck('supervisor_id'))->filter()->unique())->pluck('name', 'id');

        return ['data' => $rows->map(fn ($x) => ['id' => $x->id, 'title' => $x->title, 'author' => $names[$x->user_id] ?? '', 'user_id' => $x->user_id, 'supervisor' => $names[$x->supervisor_id] ?? null,
            'period_start' => $x->period_start, 'period_end' => $x->period_end, 'period_label' => ReportService::periodLabel($x->period_start, $x->period_end),
            'status' => $x->status, 'status_label' => ReportService::STATUSES[$x->status] ?? $x->status, 'sent_at' => $x->sent_at, 'reviewed_at' => $x->reviewed_at,
            'count' => $x->items ? count(json_decode($x->items, true) ?: []) : null])->values()];
    }

    public function send(Request $r, int $id)
    {
        $d = $r->validate(['supervisor_id' => 'required|integer', 'message' => 'nullable|string|max:2000']);

        return $this->run(function () use ($r, $id, $d) {
            $c = app('tenant');
            $rep = $this->svc->send($c, $r->user(), $id, (int) $d['supervisor_id'], $d['message'] ?? null);

            return ['data' => $this->svc->toApi($c, $rep, $r->user(), $rep->period_start, $rep->period_end, $r->user())];
        });
    }

    public function review(Request $r, int $id)
    {
        $d = $r->validate(['decision' => 'required|in:approved,returned', 'comment' => 'nullable|string|max:5000']);

        return $this->run(function () use ($r, $id, $d) {
            $c = app('tenant');
            $rep = $this->svc->review($c, $r->user(), $id, $d['decision'], $d['comment'] ?? null);

            return ['data' => $this->svc->toApi($c, $rep, User::find($rep->user_id), $rep->period_start, $rep->period_end, $r->user())];
        });
    }

    public function file(Request $r, int $id, string $format)
    {
        [$c, $rep] = $this->load($r, $id);
        $name = \Illuminate\Support\Str::slug($rep->title);

        return $format === 'pdf'
            ? response($this->svc->pdf($c, $rep), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"'])
            : response($this->svc->word($c, $rep), 200, ['Content-Type' => 'application/msword', 'Content-Disposition' => 'attachment; filename="'.$name.'.doc"']);
    }

    /** Brouillon non encore enregistré : on l'enregistre pour pouvoir produire le fichier. */
    public function draftFile(Request $r, string $format)
    {
        [$from, $to] = $this->period($r);
        $rep = $this->svc->save(app('tenant'), $r->user(), $from, $to, []);

        return $this->file($r, $rep->id, $format);
    }

    public function supervisors(Request $r)
    {
        $c = app('tenant');

        return ['data' => [
            // destinataire habituel, sinon le responsable direct (Équipe & accès)
            'current' => \Illuminate\Support\Facades\Schema::hasColumn('users', 'supervisor_id') ? (DB::table('users')->where('id', $r->user()->id)->value('supervisor_id') ?: $r->user()->manager_id) : $r->user()->manager_id,
            'users' => User::where('company_id', $c->id)->where('is_active', true)->where('id', '!=', $r->user()->id)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]];
    }

    // ------------------------------------------------------------------ activités ajoutées à la main

    public function storeEntry(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:255', 'details' => 'nullable|string|max:5000', 'date' => 'nullable|date', 'minutes' => 'nullable|integer|min:0|max:1440',
            'source_module' => 'nullable|string|max:40', 'source_type' => 'nullable|string|max:40', 'source_id' => 'nullable|string|max:40']);
        $c = app('tenant');
        $id = DB::table('ra_entries')->insertGetId([
            'company_id' => $c->id, 'user_id' => $r->user()->id, 'date' => substr($d['date'] ?? now()->toDateString(), 0, 10), 'title' => $d['title'], 'details' => $d['details'] ?? null,
            'minutes' => $d['minutes'] ?? null, 'source_module' => $d['source_module'] ?? null, 'source_type' => $d['source_type'] ?? null, 'source_id' => $d['source_id'] ?? null,
            'created_at' => now(), 'updated_at' => now()]);
        $sent = DB::table('ra_reports')->where('user_id', $r->user()->id)->where('period_start', '<=', $d['date'] ?? now()->toDateString())->where('period_end', '>=', $d['date'] ?? now()->toDateString())
            ->whereIn('status', ['sent', 'approved'])->exists();

        return response()->json(['data' => (array) DB::table('ra_entries')->find($id) + ['report_already_sent' => $sent]], 201);
    }

    public function updateEntry(Request $r, int $id)
    {
        $d = $r->validate(['title' => 'sometimes|required|string|max:255', 'details' => 'nullable|string|max:5000', 'date' => 'sometimes|date', 'minutes' => 'nullable|integer|min:0|max:1440']);
        $q = DB::table('ra_entries')->where('company_id', app('tenant')->id)->where('user_id', $r->user()->id)->where('id', $id);
        abort_unless($q->exists(), 404);
        $q->update($d + ['updated_at' => now()]);

        return ['data' => (array) $q->first()];
    }

    public function deleteEntry(Request $r, int $id)
    {
        DB::table('ra_entries')->where('company_id', app('tenant')->id)->where('user_id', $r->user()->id)->where('id', $id)->delete();

        return response()->noContent();
    }
}
