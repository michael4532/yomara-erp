<?php

namespace App\Modules\Archives;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ArchivesController extends Controller
{
    public function __construct(protected ArchiveService $svc) {}

    protected function company()
    {
        return app('tenant');
    }

    /** Niveaux de confidentialité visibles par l'utilisateur. */
    protected function levels(Request $r): array
    {
        return $r->user()->hasPermission('archives.confidential') ? array_keys(ArchiveService::LEVELS) : ['public', 'internal'];
    }

    protected function record(Request $r, string $id): object
    {
        $rec = DB::table('ar_records')->where('company_id', $this->company()->id)->where('id', $id)->first();
        abort_unless($rec && in_array($rec->confidentiality, $this->levels($r), true), 404, 'Archive introuvable.');

        return $rec;
    }

    protected function present(object $x, array $series = []): array
    {
        $s = $series[$x->series_id] ?? null;

        return [
            'id' => $x->id, 'cote' => $x->cote, 'title' => $x->title, 'description' => $x->description, 'doc_date' => $x->doc_date,
            'series_id' => $x->series_id, 'series' => $s ? $s->code.' '.$s->name : null, 'source_type' => $x->source_type, 'source_id' => $x->source_id,
            'file_name' => $x->file_name, 'mime' => $x->mime, 'size' => (int) $x->size, 'has_content' => $x->file_path !== null || $x->content !== null,
            'sha256' => $x->sha256, 'sealed_at' => $x->sealed_at, 'retention_until' => $x->retention_until, 'final_action' => $x->final_action,
            'status' => $x->status, 'confidentiality' => $x->confidentiality, 'legal_hold' => (bool) $x->legal_hold, 'location' => $x->location,
            'keywords' => $x->keywords, 'department' => $x->department, 'transfer_ref' => $x->transfer_ref, 'created_at' => $x->created_at,
        ];
    }

    public function dashboard(Request $r)
    {
        $c = $this->company();
        $this->svc->ensurePlan($c);
        $q = DB::table('ar_records')->where('company_id', $c->id);
        $active = (clone $q)->where('status', '!=', 'disposed');

        return ['data' => [
            'total' => (clone $active)->count(),
            'bytes' => (int) (clone $active)->sum('size'),
            'this_year' => (clone $q)->whereYear('created_at', now()->year)->count(),
            'to_dispose' => $this->svc->eligibleForDisposal($c)->count(),
            'to_review' => (clone $active)->where('status', 'active')->where('final_action', 'review')->whereNotNull('retention_until')->where('retention_until', '<', now()->toDateString())->count(),
            'on_loan' => DB::table('ar_loans')->where('company_id', $c->id)->where('status', 'approved')->count(),
            'loan_requests' => DB::table('ar_loans')->where('company_id', $c->id)->where('status', 'pending')->count(),
            'overdue_loans' => DB::table('ar_loans')->where('company_id', $c->id)->where('status', 'approved')->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->count(),
            'legal_hold' => (clone $active)->where('legal_hold', true)->count(),
            'unsealed' => (clone $active)->whereNull('sha256')->count(),
            // Objet JSON même vide (un tableau PHP vide serait sérialisé en [] et ferait planter l'écran)
            'by_year' => (object) (clone $active)->whereNotNull('doc_date')->selectRaw('substr(doc_date, 1, 4) y, COUNT(*) n')->groupBy('y')->orderBy('y')->pluck('n', 'y')->map(fn ($n) => (int) $n)->all(),
            'final_actions' => ArchiveService::FINAL_ACTIONS, 'levels' => ArchiveService::LEVELS, 'sources' => ArchiveService::SOURCES,
        ]];
    }

    // ---------- Plan de classement ----------

    public function series()
    {
        return ['data' => $this->svc->series($this->company())];
    }

    public function saveSeries(Request $r, ?int $id = null)
    {
        $c = $this->company();
        $d = $r->validate([
            'code' => 'required|string|max:30', 'name' => 'required|string|max:190', 'description' => 'nullable|string|max:2000',
            'parent_id' => ['nullable', 'integer', Rule::exists('ar_series', 'id')->where('company_id', $c->id)],
            'retention_years' => 'required|integer|min:0|max:200', 'final_action' => 'required|in:'.implode(',', array_keys(ArchiveService::FINAL_ACTIONS)),
            'confidentiality' => 'required|in:'.implode(',', array_keys(ArchiveService::LEVELS)),
            'sources' => 'nullable|array', 'sources.*' => 'in:'.implode(',', array_keys(ArchiveService::SOURCES)),
        ]);
        abort_if($id && (int) ($d['parent_id'] ?? 0) === $id, 422, 'Une série ne peut pas être sa propre parente.');
        // Une source n'est archivée que dans une seule série
        if (! empty($d['sources'])) {
            foreach (DB::table('ar_series')->where('company_id', $c->id)->when($id, fn ($q) => $q->where('id', '!=', $id))->get() as $s) {
                $left = array_values(array_diff(json_decode((string) $s->sources, true) ?: [], $d['sources']));
                DB::table('ar_series')->where('id', $s->id)->update(['sources' => json_encode($left)]);
            }
        }
        $vals = ['code' => $d['code'], 'name' => $d['name'], 'description' => $d['description'] ?? null, 'parent_id' => $d['parent_id'] ?? null,
            'retention_years' => $d['retention_years'], 'final_action' => $d['final_action'], 'confidentiality' => $d['confidentiality'],
            'sources' => json_encode(array_values($d['sources'] ?? [])), 'updated_at' => now()];
        if ($id) {
            abort_unless(DB::table('ar_series')->where('company_id', $c->id)->where('id', $id)->update($vals), 404);
        } else {
            $id = DB::table('ar_series')->insertGetId($vals + ['company_id' => $c->id, 'sort' => (int) DB::table('ar_series')->where('company_id', $c->id)->max('sort') + 1, 'created_at' => now()]);
        }
        $this->svc->log($c, null, 'series', 'Série '.$d['code'].' '.$d['name'].' enregistrée');

        return ['data' => $this->svc->series($c)];
    }

    public function deleteSeries(int $id)
    {
        $c = $this->company();
        abort_if(DB::table('ar_records')->where('company_id', $c->id)->where('series_id', $id)->exists(), 422, 'Cette série contient des archives : reclassez-les d\'abord.');
        abort_if(DB::table('ar_series')->where('company_id', $c->id)->where('parent_id', $id)->exists(), 422, 'Cette série a des sous-séries.');
        DB::table('ar_series')->where('company_id', $c->id)->where('id', $id)->delete();

        return ['data' => $this->svc->series($c)];
    }

    // ---------- Archives ----------

    public function index(Request $r)
    {
        $c = $this->company();
        $f = $r->validate(['q' => 'nullable|string|max:120', 'series_id' => 'nullable|integer', 'year' => 'nullable|integer', 'status' => 'nullable|string|max:24',
            'confidentiality' => 'nullable|string|max:20', 'expired' => 'nullable|boolean', 'legal_hold' => 'nullable|boolean', 'page' => 'nullable|integer|min:1']);
        $series = DB::table('ar_series')->where('company_id', $c->id)->get()->keyBy('id');
        $q = DB::table('ar_records')->where('company_id', $c->id)->whereIn('confidentiality', $this->levels($r));
        if (! empty($f['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $q->where(fn ($w) => $w->where('cote', 'like', $like)->orWhere('title', 'like', $like)->orWhere('keywords', 'like', $like)
                ->orWhere('description', 'like', $like)->orWhere('location', 'like', $like)->orWhere('file_name', 'like', $like));
        }
        if (! empty($f['series_id'])) {
            // Série et ses sous-séries
            $ids = [$f['series_id']];
            for ($i = 0; $i < 4; $i++) {
                $ids = array_unique(array_merge($ids, $series->whereIn('parent_id', $ids)->keys()->all()));
            }
            $q->whereIn('series_id', $ids);
        }
        if (! empty($f['year'])) {
            $q->whereYear('doc_date', $f['year']);
        }
        $q->when($f['status'] ?? null, fn ($w, $s) => $w->where('status', $s), fn ($w) => $w->where('status', '!=', 'disposed'));
        $q->when($f['confidentiality'] ?? null, fn ($w, $l) => $w->where('confidentiality', $l));
        if (! empty($f['expired'])) {
            $q->whereNotNull('retention_until')->where('retention_until', '<', now()->toDateString());
        }
        if (! empty($f['legal_hold'])) {
            $q->where('legal_hold', true);
        }
        $page = $q->orderByDesc('doc_date')->orderByDesc('created_at')->paginate(50, ['*'], 'page', $f['page'] ?? 1);

        return ['data' => collect($page->items())->map(fn ($x) => $this->present($x, $series->all()))->all(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }

    public function show(Request $r, string $id)
    {
        $c = $this->company();
        $x = $this->record($r, $id);
        $this->svc->log($c, $id, 'viewed');
        $users = DB::table('users')->where('company_id', $c->id)->pluck('name', 'id');
        $events = DB::table('ar_events')->where('company_id', $c->id)->where('record_id', $id)->orderByDesc('id')->limit(100)->get()
            ->map(fn ($e) => ['action' => $e->action, 'details' => $e->details, 'user' => $users[$e->user_id] ?? null, 'at' => $e->created_at, 'ip' => $e->ip]);
        $loans = DB::table('ar_loans')->where('company_id', $c->id)->where('record_id', $id)->orderByDesc('id')->get();

        return ['data' => $this->present($x, DB::table('ar_series')->where('company_id', $c->id)->get()->keyBy('id')->all()) + [
            'snapshot' => $x->content ? json_decode($x->content, true) : null, 'events' => $events, 'loans' => $loans,
        ]];
    }

    /** Versement : un ou plusieurs fichiers (scans, PDF…) avec leurs métadonnées. */
    public function store(Request $r)
    {
        $c = $this->company();
        $d = $r->validate([
            'files' => 'required|array|min:1|max:50', 'files.*' => 'file|max:204800',
            'title' => 'nullable|string|max:250', 'description' => 'nullable|string|max:5000', 'doc_date' => 'nullable|date',
            'series_id' => ['nullable', 'integer', Rule::exists('ar_series', 'id')->where('company_id', $c->id)],
            'location' => 'nullable|string|max:190', 'keywords' => 'nullable|string|max:500', 'department' => 'nullable|string|max:120',
            'transfer_ref' => 'nullable|string|max:60', 'confidentiality' => 'nullable|in:'.implode(',', array_keys(ArchiveService::LEVELS)),
        ]);
        // Espace partagé de la société (Documents + Archives)
        $u = app(\App\Modules\Documents\DocumentService::class)->usage($c);
        $incoming = array_sum(array_map(fn ($f) => $f->getSize(), $r->file('files')));
        abort_if($u['used'] + $incoming > $u['quota'], 422, 'Espace de stockage de la société plein : ajoutez de l\'espace (Documents › Espace de stockage) ou éliminez les archives arrivées à terme.');
        $ids = [];
        foreach ($r->file('files') as $i => $file) {
            $title = ($d['title'] ?? null) ? (count($r->file('files')) > 1 ? $d['title'].' ('.($i + 1).')' : $d['title']) : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $ids[] = $this->svc->create($c, ['title' => $title] + array_intersect_key($d, array_flip(['description', 'doc_date', 'series_id', 'location', 'keywords', 'department', 'transfer_ref', 'confidentiality'])),
                $file->get(), $file->getClientOriginalName(), $file->getMimeType(), null, $r->user()->id);
        }

        return response()->json(['data' => ['ids' => $ids, 'count' => count($ids)]], 201);
    }

    /** Archiver un fichier de la GED (copie scellée, le document reste dans la GED). */
    public function fromGed(Request $r)
    {
        $c = $this->company();
        $d = $r->validate(['file_id' => 'required|uuid', 'series_id' => ['nullable', 'integer', Rule::exists('ar_series', 'id')->where('company_id', $c->id)],
            'location' => 'nullable|string|max:190', 'keywords' => 'nullable|string|max:500']);
        $f = \App\Modules\Documents\Models\DocFile::findOrFail($d['file_id']);
        abort_unless(\App\Modules\Documents\DocumentAccess::canSee($r->user(), $f->folder_id), 404);
        $v = \App\Modules\Documents\Models\DocVersion::where('file_id', $f->id)->where('number', $f->current_version)->first();
        $bytes = app(\App\Storage\StoragePool::class)->get($v?->backend_id, $v?->path ?? $f->path);
        $id = $this->svc->create($c, ['title' => pathinfo($f->name, PATHINFO_FILENAME), 'series_id' => $d['series_id'] ?? null, 'doc_date' => $f->created_at?->toDateString(),
            'location' => $d['location'] ?? null, 'keywords' => $d['keywords'] ?? null, 'source_type' => 'ged', 'source_id' => $f->id], $bytes, $f->name, $f->mime, null, $r->user()->id);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    /** Métadonnées de gestion (le contenu scellé ne change jamais). */
    public function update(Request $r, string $id)
    {
        $c = $this->company();
        $x = $this->record($r, $id);
        abort_if($x->status === 'disposed', 422, 'Archive éliminée : seule sa notice est conservée.');
        $d = $r->validate([
            'title' => 'sometimes|required|string|max:250', 'description' => 'nullable|string|max:5000', 'location' => 'nullable|string|max:190',
            'keywords' => 'nullable|string|max:500', 'department' => 'nullable|string|max:120', 'transfer_ref' => 'nullable|string|max:60',
            'series_id' => ['nullable', 'integer', Rule::exists('ar_series', 'id')->where('company_id', $c->id)],
            'confidentiality' => 'sometimes|in:'.implode(',', array_keys(ArchiveService::LEVELS)),
            'final_action' => 'sometimes|in:'.implode(',', array_keys(ArchiveService::FINAL_ACTIONS)),
            'retention_until' => 'nullable|date', 'legal_hold' => 'sometimes|boolean',
        ]);
        // Séparation des rôles : lever un gel juridique, avancer le terme ou passer à l'élimination demande le droit d'éliminer
        $sensitive = (array_key_exists('legal_hold', $d) && ! $d['legal_hold'] && $x->legal_hold)
            || (array_key_exists('retention_until', $d) && ($d['retention_until'] === null ? false : ($x->retention_until === null || $d['retention_until'] < $x->retention_until)))
            || (($d['final_action'] ?? null) === 'destroy' && $x->final_action !== 'destroy');
        abort_if($sensitive && ! $r->user()->hasPermission('archives.dispose'), 403, 'Seul un responsable habilité aux éliminations peut lever un gel, avancer le terme ou décider une élimination.');
        if (array_key_exists('series_id', $d) && $d['series_id'] && (int) $d['series_id'] !== (int) $x->series_id && ! array_key_exists('retention_until', $d)) {
            // Reclassement : durée et sort de la nouvelle série
            $s = DB::table('ar_series')->where('id', $d['series_id'])->first();
            $d['retention_until'] = $this->svc->retentionUntil($x->doc_date, (int) $s->retention_years);
            $d['final_action'] ??= $s->final_action;
        }
        DB::table('ar_records')->where('id', $id)->update($d + ['updated_at' => now()]);
        $changes = collect($d)->map(fn ($v, $k) => $k.' : '.(is_bool($v) ? ($v ? 'oui' : 'non') : ($v ?? '—')))->implode(', ');
        $this->svc->log($c, $id, isset($d['legal_hold']) ? ($d['legal_hold'] ? 'hold_on' : 'hold_off') : 'updated', $changes);

        return ['data' => $this->present(DB::table('ar_records')->where('id', $id)->first(), DB::table('ar_series')->where('company_id', $c->id)->get()->keyBy('id')->all())];
    }

    public function download(Request $r, string $id)
    {
        $c = $this->company();
        $x = $this->record($r, $id);
        abort_if($x->status === 'disposed', 410, 'Archive éliminée.');
        $bytes = $this->svc->bytes($x);
        abort_if($bytes === null, 404, 'Aucun contenu archivé.');
        $this->svc->log($c, $id, 'downloaded');
        $name = $x->file_name ?: $x->cote.'.json';

        return response($bytes, 200, [
            'Content-Type' => $x->mime ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="'.str_replace(['"', "\r", "\n"], '', $name).'"', 'X-Archive-Sha256' => (string) $x->sha256,
        ]);
    }

    public function verify(Request $r, string $id)
    {
        return ['data' => $this->svc->verify($this->company(), $this->record($r, $id), $r->user()->id)];
    }

    /** Contrôle d'intégrité de tout le fonds (par lots). */
    public function verifyAll(Request $r)
    {
        $c = $this->company();
        $ok = $bad = [];
        foreach (DB::table('ar_records')->where('company_id', $c->id)->where('status', '!=', 'disposed')->whereNotNull('sha256')->orderBy('created_at')->limit(500)->get() as $x) {
            $res = $this->svc->verify($c, $x, $r->user()->id);
            $res['ok'] ? $ok[] = $x->cote : $bad[] = ['cote' => $x->cote, 'message' => $res['message']];
        }

        return ['data' => ['checked' => count($ok) + count($bad), 'ok' => count($ok), 'altered' => $bad]];
    }

    // ---------- Prêts et consultations ----------

    public function loans(Request $r)
    {
        $c = $this->company();
        $users = DB::table('users')->where('company_id', $c->id)->pluck('name', 'id');

        return ['data' => DB::table('ar_loans as l')->join('ar_records as a', 'a.id', '=', 'l.record_id')->where('l.company_id', $c->id)
            ->whereIn('a.confidentiality', $this->levels($r))->orderByRaw("CASE l.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderByDesc('l.id')->limit(300)
            ->get(['l.*', 'a.cote', 'a.title', 'a.location'])
            ->map(fn ($l) => (array) $l + ['requested_by_name' => $users[$l->requested_by] ?? null, 'decided_by_name' => $users[$l->decided_by] ?? null,
                'overdue' => $l->status === 'approved' && $l->due_date && $l->due_date < now()->toDateString()])];
    }

    public function requestLoan(Request $r, string $id)
    {
        $c = $this->company();
        $x = $this->record($r, $id);
        abort_if($x->status !== 'active' && $x->status !== 'on_loan', 422, 'Cette archive n\'est pas communicable.');
        $d = $r->validate(['borrower' => 'nullable|string|max:190', 'purpose' => 'nullable|string|max:500', 'kind' => 'nullable|in:consultation,pret', 'due_date' => 'nullable|date|after_or_equal:today']);
        $lid = DB::table('ar_loans')->insertGetId([
            'company_id' => $c->id, 'record_id' => $id, 'requested_by' => $r->user()->id, 'borrower' => $d['borrower'] ?? $r->user()->name,
            'purpose' => $d['purpose'] ?? null, 'kind' => $d['kind'] ?? 'consultation', 'status' => 'pending', 'due_date' => $d['due_date'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->svc->log($c, $id, 'loan_requested', ($d['kind'] ?? 'consultation').' — '.($d['borrower'] ?? $r->user()->name));

        return response()->json(['data' => ['id' => $lid]], 201);
    }

    public function decideLoan(Request $r, int $id)
    {
        $c = $this->company();
        $d = $r->validate(['action' => 'required|in:approve,reject,return']);
        $l = DB::table('ar_loans')->where('company_id', $c->id)->where('id', $id)->first();
        abort_unless($l, 404);
        $next = ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned'][$d['action']];
        abort_unless(($d['action'] === 'return' && $l->status === 'approved') || ($d['action'] !== 'return' && $l->status === 'pending'), 422, 'Action impossible dans l\'état actuel de la demande.');
        DB::table('ar_loans')->where('id', $id)->update(['status' => $next, 'updated_at' => now()] + ($d['action'] === 'return' ? ['returned_at' => now()] : ['decided_by' => $r->user()->id, 'decided_at' => now()]));
        // Un prêt physique sort l'archive du rayon ; elle y revient au retour
        if ($l->kind === 'pret' && $d['action'] !== 'reject') {
            DB::table('ar_records')->where('id', $l->record_id)->update(['status' => $d['action'] === 'approve' ? 'on_loan' : 'active', 'updated_at' => now()]);
        }
        $this->svc->log($c, $l->record_id, 'loan_'.$next, $l->borrower);

        return ['data' => ['status' => $next]];
    }

    // ---------- Éliminations ----------

    public function disposals(Request $r)
    {
        $c = $this->company();
        $users = DB::table('users')->where('company_id', $c->id)->pluck('name', 'id');

        return ['data' => [
            'eligible' => $this->svc->eligibleForDisposal($c),
            'disposals' => DB::table('ar_disposals')->where('company_id', $c->id)->orderByDesc('id')->limit(100)->get()->map(fn ($d) => [
                'id' => $d->id, 'number' => $d->number, 'status' => $d->status, 'notes' => $d->notes, 'records' => json_decode($d->records, true),
                'proposed_by' => $users[$d->proposed_by] ?? null, 'approved_by' => $users[$d->approved_by] ?? null, 'approved_at' => $d->approved_at,
                'executed_by' => $users[$d->executed_by] ?? null, 'executed_at' => $d->executed_at, 'created_at' => $d->created_at,
            ]),
        ]];
    }

    public function propose(Request $r)
    {
        $d = $r->validate(['ids' => 'required|array|min:1|max:1000', 'ids.*' => 'uuid', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => ['id' => $this->svc->propose($this->company(), $d['ids'], $d['notes'] ?? null, $r->user()->id)]], 201);
    }

    /** Approbation (visa) puis exécution : deux étapes distinctes, tracées. */
    public function decideDisposal(Request $r, int $id)
    {
        $c = $this->company();
        $d = $r->validate(['action' => 'required|in:approve,execute,cancel']);
        $x = DB::table('ar_disposals')->where('company_id', $c->id)->where('id', $id)->first();
        abort_unless($x, 404);
        $ids = collect(json_decode($x->records, true))->pluck('id');
        switch ($d['action']) {
            case 'approve':
                abort_unless($x->status === 'proposed', 422, 'Bordereau déjà traité.');
                DB::table('ar_disposals')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
                break;
            case 'execute':
                abort_unless($x->status === 'approved', 422, 'Le bordereau doit d\'abord être approuvé.');
                $this->svc->execute($c, $x, $r->user()->id);
                break;
            case 'cancel':
                abort_unless(in_array($x->status, ['proposed', 'approved'], true), 422, 'Bordereau déjà exécuté.');
                DB::table('ar_disposals')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                DB::table('ar_records')->where('company_id', $c->id)->whereIn('id', $ids)->where('status', 'pending_disposal')->update(['status' => 'active', 'updated_at' => now()]);
                break;
        }
        $this->svc->log($c, null, 'disposal_'.$d['action'], 'Bordereau '.$x->number);

        return $this->disposals($r);
    }

    public function journal(Request $r)
    {
        $c = $this->company();
        $users = DB::table('users')->where('company_id', $c->id)->pluck('name', 'id');

        return ['data' => DB::table('ar_events as e')->leftJoin('ar_records as a', 'a.id', '=', 'e.record_id')->where('e.company_id', $c->id)
            ->where(fn ($w) => $w->whereNull('a.id')->orWhereIn('a.confidentiality', $this->levels($r)))
            ->orderByDesc('e.id')->limit(500)->get(['e.*', 'a.cote', 'a.title'])
            ->map(fn ($e) => ['action' => $e->action, 'details' => $e->details, 'user' => $users[$e->user_id] ?? null, 'at' => $e->created_at, 'ip' => $e->ip, 'cote' => $e->cote, 'title' => $e->title, 'record_id' => $e->record_id])];
    }
}
