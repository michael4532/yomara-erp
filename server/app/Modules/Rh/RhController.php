<?php

namespace App\Modules\Rh;

use App\Http\Controllers\Controller;
use App\Modules\Paie\Models\Payslip;
use App\Modules\Rh\Models\Employee;
use App\Modules\Rh\Models\Leave;
use App\Services\EntityWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Salariés et congés. L'application passe par la synchronisation (hors ligne) ;
 * ces routes servent aux intégrations et à l'espace salarié (bulletins).
 */
class RhController extends Controller
{
    public function __construct(protected EntityWriter $writer) {}

    // ---------- Salariés ----------

    public function employees(Request $r)
    {
        $q = Employee::query()->orderBy('last_name')->orderBy('first_name');
        if (! $r->boolean('all')) {
            $q->where('active', true);
        }
        if ($s = $r->query('q')) {
            $q->where(fn ($w) => $w->where('last_name', 'like', "%$s%")->orWhere('first_name', 'like', "%$s%")->orWhere('matricule', 'like', "%$s%"));
        }
        $employees = $q->get();
        $balances = self::balances($employees);

        return [
            'employees' => $employees->map(fn ($e) => $e->toArray() + ['leave_balance' => $balances[$e->id] ?? 0]),
            'departments' => Employee::whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
            'leave_types' => Leave::TYPES,
        ];
    }

    public function employee($id)
    {
        $e = Employee::findOrFail($id);

        return $e->toArray() + [
            'leave_balance' => self::balances(collect([$e]))[$e->id] ?? 0,
            'leaves' => $e->leaves()->orderByDesc('start_date')->get(),
        ];
    }

    /** Dossier complet d'un salarié : fiche, documents, contrats, bulletins et salaires versés, congés, notes de frais. */
    public function dossier($id)
    {
        $c = app('tenant');
        $e = Employee::findOrFail($id);
        $db = \Illuminate\Support\Facades\DB::class;
        $docs = $db::table('rh_documents')->where('company_id', $c->id)->where('employee_id', $e->id)->whereNull('deleted_at')->orderByDesc('date')
            ->get(['id', 'type', 'title', 'number', 'date', 'status']);
        $slips = collect();
        if ($c->hasModule('paie') && class_exists(Payslip::class)) {
            $slips = Payslip::with('run:id,period,label,status,payment_date')->where('employee_id', $e->id)->get()
                ->sortByDesc(fn ($p) => $p->run?->period)->values()
                ->map(fn ($p) => ['id' => $p->id, 'period' => $p->run?->period, 'label' => $p->run?->label, 'status' => $p->run?->status,
                    'payment_date' => $p->run?->payment_date, 'gross' => (float) $p->gross, 'net' => (float) $p->net, 'cost' => (float) $p->cost]);
        }
        $done = $slips->whereIn('status', ['validated', 'paid']);
        $years = $done->groupBy(fn ($p) => substr((string) $p['period'], 0, 4))->map(fn ($g, $y) => ['year' => (string) $y, 'months' => $g->count(),
            'gross' => round($g->sum('gross'), 2), 'net' => round($g->sum('net'), 2), 'cost' => round($g->sum('cost'), 2)])->sortKeysDesc()->values();
        $expenses = $c->hasModule('notes_frais') && \Illuminate\Support\Facades\Schema::hasTable('nf_expenses')
            ? $db::table('nf_expenses')->where('company_id', $c->id)->where('employee_id', $e->id)->whereNull('deleted_at')->orderByDesc('date')->limit(50)
                ->get(['id', 'date', 'amount', 'status', 'description'])
            : collect();

        return [
            'employee' => $e->toArray(),
            'seniority_months' => $e->hire_date ? (int) \Illuminate\Support\Carbon::parse($e->hire_date)->diffInMonths($e->end_date ?? now()) : null,
            'leave_balance' => self::balances(collect([$e]))[$e->id] ?? 0,
            'leaves' => $e->leaves()->orderByDesc('start_date')->get(['id', 'type', 'start_date', 'end_date', 'days', 'status', 'reason']),
            'documents' => $docs,
            'contracts' => $c->hasModule('juridique') && request()->user()->hasPermission('rh.contracts') ? \App\Modules\Juridique\JuridiqueHooks::forEmployee($c->id, (string) $e->id) : [],
            'can_contracts' => request()->user()->hasPermission('rh.contracts'),
            'payslips' => $slips->values(),
            'salary_years' => $years,
            'totals' => ['net_paid' => round($slips->where('status', 'paid')->sum('net'), 2), 'gross' => round($done->sum('gross'), 2), 'cost' => round($done->sum('cost'), 2), 'months' => $done->count()],
            'expenses' => $expenses,
            'expenses_total' => round((float) $expenses->whereIn('status', ['approved', 'paid'])->sum('amount'), 2),
        ];
    }

    // ---------- Demandes des salariés ----------

    public function requestSettings(Request $r)
    {
        $c = app('tenant');

        return [
            'types' => \App\Modules\Rh\Models\EmployeeRequest::TYPES,
            'enabled' => \App\Modules\Rh\Models\EmployeeRequest::enabledTypes(),
            'email' => data_get($c->settings, 'rh_requests.email'),
            'statuses' => \App\Modules\Rh\Models\EmployeeRequest::STATUSES,
            'can_request' => $r->user()->hasPermission('rh.request'),
            'email_ready' => (bool) app(\App\Messaging\MessagingService::class)->usable($c, 'email'),
            'cc_options' => $this->ccOptions($r->user()),
        ];
    }

    public function updateRequestSettings(Request $r)
    {
        $v = $r->validate([
            'enabled' => 'required|array|min:1',
            'enabled.*' => 'in:'.implode(',', array_keys(\App\Modules\Rh\Models\EmployeeRequest::TYPES)),
            'email' => ['nullable', 'string', 'max:500', function ($a, $val, $fail) {
                foreach (array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $val))) as $m) {
                    if (! filter_var($m, FILTER_VALIDATE_EMAIL)) {
                        $fail('Adresse e-mail invalide : '.$m);
                    }
                }
            }],
        ]);
        $c = app('tenant');
        $c->forceFill(['settings' => array_merge($c->settings ?? [], ['rh_requests' => ['types' => array_values($v['enabled']), 'email' => trim((string) ($v['email'] ?? '')) ?: null]])])->save();

        return $this->requestSettings($r);
    }

    /** Personnes que le salarié peut mettre en copie : l'équipe RH et la direction. */
    protected function ccOptions(\App\Models\User $me): array
    {
        $out = [];
        foreach (\App\Models\User::where('company_id', app('tenant')->id)->where('is_active', true)->where('id', '!=', $me->id)->with('role')->get() as $u) {
            $isDir = in_array('*', (array) $u->role?->permissions, true) || str_contains(mb_strtolower((string) $u->role?->name), 'direction');
            $isHr = ! $u->is_super_admin && $u->hasPermission('rh.manage');
            if ($isDir || $isHr) {
                $out[] = ['user_id' => $u->id, 'name' => $u->name, 'group' => $isHr ? 'Service RH' : 'Direction', 'role' => $u->role?->name];
            }
        }
        usort($out, fn ($a, $b) => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);

        return $out;
    }

    /** Mon équipe : collaborateurs et stagiaires du chef de département (ou d'un département pour la RH). */
    public function team(Request $r, \App\Modules\Rh\TeamService $svc)
    {
        return $svc->team(app('tenant'), $r->user(), $r->query('department'));
    }

    public function teamMember(Request $r, string $id, \App\Modules\Rh\TeamService $svc)
    {
        return $svc->member(app('tenant'), $r->user(), Employee::findOrFail($id));
    }

    protected function requestAccess(Request $r, \App\Modules\Rh\Models\EmployeeRequest $req): string
    {
        $user = $r->user();
        $me = Employee::where('user_id', $user->id)->value('id');
        if ($user->hasPermission('rh.view|rh.manage')) {
            return 'hr';
        }
        if ($me && $req->employee_id === $me) {
            return 'employee';
        }
        if ($me && $req->manager_id === $me) {
            return 'manager';
        }
        $emp = Employee::find($req->employee_id);
        abort_unless($emp && ($rights = app(\App\Modules\Rh\TeamService::class)->rightsOn($user, $emp)) && ($rights['requests'] ?? false), 403, 'Cette demande ne vous concerne pas.');

        return 'manager';
    }

    /** Justificatif (certificat médical, attestation…) joint à une demande. */
    public function attachRequest(Request $r, string $id)
    {
        $r->validate(['file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx']);
        $req = \App\Modules\Rh\Models\EmployeeRequest::findOrFail($id);
        $this->requestAccess($r, $req);
        abort_if(count((array) $req->attachments) >= 10, 422, '10 pièces jointes au maximum.');
        $f = $r->file('file');
        $aid = (string) Str::uuid();
        $ext = strtolower($f->getClientOriginalExtension() ?: $f->extension() ?: 'bin');
        $path = 'rh-requests/'.app('tenant')->id.'/'.$req->id.'/'.$aid.'.'.$ext;
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, file_get_contents($f->getRealPath()));
        $meta = ['id' => $aid, 'name' => mb_substr($f->getClientOriginalName() ?: 'justificatif.'.$ext, 0, 150), 'mime' => $f->getMimeType() ?: 'application/octet-stream',
            'size' => $f->getSize(), 'path' => $path, 'at' => now()->toIso8601String(), 'by' => $r->user()->name];
        $req->forceFill(['attachments' => array_merge((array) $req->attachments, [$meta])])->save();
        app(\App\Modules\Rh\RequestNotifier::class)->attached($req, $r->user(), $meta, \Illuminate\Support\Facades\Storage::disk('local')->get($path));

        return ['data' => $req->fresh()->toSyncArray()];
    }

    public function requestAttachment(Request $r, string $id, string $aid)
    {
        $req = \App\Modules\Rh\Models\EmployeeRequest::findOrFail($id);
        $who = $this->requestAccess($r, $req);
        if ($who === 'manager') {
            $emp = Employee::find($req->employee_id);
            $rights = $emp ? app(\App\Modules\Rh\TeamService::class)->rightsOn($r->user(), $emp) : null;
            abort_unless(($rights['documents'] ?? false) || $req->manager_id === Employee::where('user_id', $r->user()->id)->value('id'), 403, 'Les justificatifs ne vous sont pas ouverts.');
        }
        $a = collect((array) $req->attachments)->firstWhere('id', $aid);
        abort_unless($a && \Illuminate\Support\Facades\Storage::disk('local')->exists($a['path']), 404, 'Pièce jointe introuvable.');

        return response(\Illuminate\Support\Facades\Storage::disk('local')->get($a['path']), 200, ['Content-Type' => $a['mime'], 'Content-Disposition' => 'inline; filename="'.addslashes($a['name']).'"']);
    }

    /** Message ajouté au fil d'une demande : le salarié, son responsable ou la RH. */
    public function commentRequest(Request $r, string $id)
    {
        $v = $r->validate(['text' => 'required|string|max:5000']);
        $req = \App\Modules\Rh\Models\EmployeeRequest::findOrFail($id);
        $user = $r->user();
        abort_if($req->status === 'cancelled', 422, 'Demande annulée.');
        $role = $this->requestAccess($r, $req);
        $req->forceFill(['messages' => array_merge($req->messages ?? [], [['at' => now()->toIso8601String(), 'user_id' => $user->id, 'name' => $user->name, 'role' => $role, 'text' => $v['text']]])])->save();
        app(\App\Modules\Rh\RequestNotifier::class)->commented($req, $user, $v['text']);

        return ['data' => $req->fresh()->toSyncArray()];
    }

    /** Décision du service RH : en cours d'examen, acceptée, refusée. */
    public function decideRequest(Request $r, string $id)
    {
        $v = $r->validate(['status' => 'required|in:in_review,approved,rejected', 'note' => 'nullable|string|max:1000']);
        \App\Modules\Rh\Models\EmployeeRequest::findOrFail($id);

        return $this->write(\App\Modules\Rh\Models\EmployeeRequest::class, $id, ['status' => $v['status'], 'decision_note' => $v['note'] ?? null]);
    }

    /**
     * Contrats de travail de tous les salariés : registre du juriste (contrats « travail ») et contrats émis par la RH.
     * Lecture seule, réservée au droit rh.contracts.
     */
    public function contracts()
    {
        $c = app('tenant');
        $db = \Illuminate\Support\Facades\DB::class;
        $employees = Employee::withTrashed()->get(['id', 'first_name', 'last_name', 'matricule', 'contract_type', 'hire_date', 'end_date', 'active', 'job_title', 'department'])->keyBy('id');
        $name = fn ($id) => isset($employees[$id]) ? $employees[$id]->full_name : null;
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();
        $rows = collect();

        if ($c->hasModule('juridique') && \Illuminate\Support\Facades\Schema::hasTable('jr_contracts')) {
            $statuses = \App\Modules\Juridique\JuridiqueHooks::CONTRACT_STATUSES;
            foreach ($db::table('jr_contracts')->where('company_id', $c->id)->where('kind', 'employment')->orderByDesc('start_date')->get() as $x) {
                $rows->push([
                    'source' => 'juridique', 'id' => (string) $x->id, 'title' => $x->title, 'reference' => $x->reference,
                    'employee_id' => $x->employee_id, 'employee' => $name($x->employee_id) ?? $x->counterparty,
                    'status' => $x->status, 'status_label' => $statuses[$x->status][0] ?? $x->status, 'status_color' => $statuses[$x->status][1] ?? '#64748B',
                    'date' => $x->signed_on ?? $x->start_date, 'start_date' => $x->start_date, 'end_date' => $x->end_date,
                    'file_url' => $x->file_url, 'has_text' => filled($x->body),
                    'alert' => $x->end_date && $x->status === 'active' ? ($x->end_date < $today ? 'expired' : ($x->end_date <= $soon ? 'soon' : null)) : null,
                ]);
            }
        }
        $docStatuses = ['draft' => ['Brouillon', '#64748B'], 'issued' => ['Émis', '#16A34A'], 'requested' => ['Demandé', '#D97706'], 'cancelled' => ['Annulé', '#94A3B8']];
        foreach ($db::table('rh_documents')->where('company_id', $c->id)->where('type', 'contract')->whereNull('deleted_at')->orderByDesc('date')->get() as $x) {
            $rows->push([
                'source' => 'rh', 'id' => (string) $x->id, 'title' => $x->title, 'reference' => $x->number,
                'employee_id' => $x->employee_id, 'employee' => $name($x->employee_id),
                'status' => $x->status, 'status_label' => $docStatuses[$x->status][0] ?? $x->status, 'status_color' => $docStatuses[$x->status][1] ?? '#64748B',
                'date' => $x->date, 'start_date' => null, 'end_date' => null, 'file_url' => null, 'has_text' => filled($x->body), 'alert' => null,
            ]);
        }

        // Points de vigilance : CDD qui se terminent, salariés en poste sans aucun contrat enregistré
        $covered = $rows->whereNotIn('status', ['cancelled', 'terminated'])->pluck('employee_id')->filter()->unique()->all();
        $active = $employees->filter(fn ($e) => $e->active && ! $e->trashed());
        $endingCdd = $active->filter(fn ($e) => $e->contract_type === 'CDD' && $e->end_date && $e->end_date->toDateString() <= $soon)
            ->map(fn ($e) => ['employee_id' => $e->id, 'employee' => $e->full_name, 'end_date' => $e->end_date->toDateString(), 'expired' => $e->end_date->toDateString() < $today])
            ->sortBy('end_date')->values();
        $without = $active->reject(fn ($e) => in_array($e->id, $covered, true))
            ->map(fn ($e) => ['employee_id' => $e->id, 'employee' => $e->full_name, 'contract_type' => $e->contract_type, 'job_title' => $e->job_title])
            ->sortBy('employee')->values();

        return [
            'data' => $rows->sortByDesc('date')->values(),
            'ending_cdd' => $endingCdd,
            'without_contract' => $without,
            'juridique' => $c->hasModule('juridique'),
        ];
    }

    /** Texte d'un contrat (lecture seule). */
    public function contract(string $source, string $id)
    {
        $c = app('tenant');
        $db = \Illuminate\Support\Facades\DB::class;
        if ($source === 'juridique') {
            abort_unless($c->hasModule('juridique') && \Illuminate\Support\Facades\Schema::hasTable('jr_contracts'), 404);
            $x = $db::table('jr_contracts')->where('company_id', $c->id)->where('kind', 'employment')->where('id', $id)->first();
        } else {
            $x = $db::table('rh_documents')->where('company_id', $c->id)->where('type', 'contract')->whereNull('deleted_at')->where('id', $id)->first();
        }
        abort_unless($x, 404, 'Contrat introuvable.');

        return ['data' => ['title' => $x->title, 'body' => $x->body, 'file_url' => $x->file_url ?? null, 'notes' => $x->notes ?? $x->note ?? null]];
    }

    protected function write(string $class, ?string $id, array $data, int $created = 200)
    {
        $r = $this->writer->upsert($class, $id ?? (string) Str::uuid(), $data);
        if ($r['status'] !== EntityWriter::APPLIED) {
            return response()->json(['message' => $r['message'], 'errors' => $r['errors']], 422);
        }

        return response()->json($r['record'], $created);
    }

    public function storeEmployee(Request $request)
    {
        return $this->write(Employee::class, null, $request->all(), 201);
    }

    public function updateEmployee(Request $request, $id)
    {
        Employee::findOrFail($id);

        return $this->write(Employee::class, $id, $request->all());
    }

    public function deleteEmployee($id)
    {
        $e = Employee::findOrFail($id);
        // Un salarié payé reste dans l'historique de paie : on le sort seulement des effectifs.
        $this->writer->upsert(Employee::class, $e->id, ['active' => false, 'end_date' => $e->end_date?->toDateString() ?? now()->toDateString()]);

        return ['ok' => true];
    }

    /** Comptes de la société, pour relier une fiche à la personne qui se connecte. */
    public function users()
    {
        return ['data' => \App\Models\User::where('company_id', app('tenant')->id)->orderBy('name')->get(['id', 'name', 'email', 'is_active'])];
    }

    // ---------- Documents RH ----------

    public function docSettings()
    {
        $s = app('tenant')->settings ?? [];

        return ['data' => ['rh_signatory' => $s['rh_signatory'] ?? null, 'rh_signatory_title' => $s['rh_signatory_title'] ?? 'Le Gérant']];
    }

    public function updateDocSettings(Request $r)
    {
        $v = $r->validate(['rh_signatory' => 'required|string|max:120', 'rh_signatory_title' => 'required|string|max:120']);
        $c = app('tenant');
        $c->forceFill(['settings' => array_merge($c->settings ?? [], $v)])->save();

        return $this->docSettings();
    }

    // ---------- Congés ----------

    public function leaves(Request $r)
    {
        $q = Leave::with('employee:id,first_name,last_name,matricule')->orderByDesc('start_date');
        foreach (['status', 'employee_id', 'type'] as $f) {
            if ($v = $r->query($f)) {
                $q->where($f, $v);
            }
        }
        if ($from = $r->query('from')) {
            $q->whereDate('end_date', '>=', $from);
        }
        if ($to = $r->query('to')) {
            $q->whereDate('start_date', '<=', $to);
        }

        return ['leaves' => $q->limit(500)->get(), 'types' => Leave::TYPES];
    }

    public function storeLeave(Request $request)
    {
        $data = $request->except('approve') + ['status' => $request->boolean('approve') ? 'approved' : 'requested'];

        return $this->write(Leave::class, null, $data, 201);
    }

    public function updateLeave(Request $request, $id)
    {
        Leave::findOrFail($id);

        return $this->write(Leave::class, $id, $request->all());
    }

    public function decideLeave(Request $request, $id, string $decision)
    {
        Leave::findOrFail($id);
        $status = ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'][$decision];

        return $this->write(Leave::class, $id, ['status' => $status, 'decision_note' => $request->input('note')]);
    }

    // ---------- Espace salarié ----------

    protected function me(Request $request): Employee
    {
        $e = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($e, 404, 'Votre compte n\'est relié à aucune fiche salarié : demandez au service RH de le faire.');

        return $e;
    }

    public function self(Request $request)
    {
        // Compte sans fiche salarié : réponse normale (l'écran « Mon espace » explique quoi faire)
        if (! Employee::where('user_id', $request->user()->id)->exists()) {
            return ['employee' => null, 'linked' => false, 'leave_balance' => 0, 'payslips' => [],
                'message' => 'Votre compte n\'est relié à aucune fiche salarié : demandez au service RH de le faire.'];
        }
        $e = $this->me($request);
        $slips = class_exists(Payslip::class) && app('tenant')->hasModule('paie')
            ? Payslip::with('run:id,period,label,status,payment_date')->where('employee_id', $e->id)
                ->whereHas('run', fn ($q) => $q->whereIn('status', ['validated', 'paid']))->get()
                ->sortByDesc(fn ($p) => $p->run->period)->values()
                ->map(fn ($p) => ['id' => $p->id, 'period' => $p->run->period, 'label' => $p->run->label, 'status' => $p->run->status, 'payment_date' => $p->run->payment_date, 'gross' => $p->gross, 'net' => $p->net])
            : collect();

        return ['employee' => $e, 'leave_balance' => self::balances(collect([$e]))[$e->id] ?? 0, 'payslips' => $slips];
    }

    /** Bulletin du salarié connecté (données complètes pour le PDF). */
    public function selfPayslip(Request $request, int $id)
    {
        $e = $this->me($request);
        $slip = Payslip::with('run')->where('employee_id', $e->id)->findOrFail($id);
        abort_unless(in_array($slip->run->status, ['validated', 'paid'], true), 404);

        return $slip;
    }

    /** Coordonnées corrigées par le salarié lui-même. */
    public function updateSelf(Request $request)
    {
        $e = $this->me($request);
        $data = $request->validate([
            'phone' => 'sometimes|nullable|string|max:40', 'email' => 'sometimes|nullable|email|max:255',
            'address' => 'sometimes|nullable|string|max:255', 'emergency_contact' => 'sometimes|nullable|string|max:255',
        ]);
        $e->fill(array_intersect_key($data, array_flip(Employee::SELF_FIELDS)))->save();

        return $e->fresh();
    }

    /** Jours ouvrables (lundi → samedi) entre deux dates incluses. */
    public static function workingDays(string $from, string $to): float
    {
        return Leave::workingDays($from, $to);
    }

    /**
     * Solde de congés payés de l'année : report au 1er janvier + droits acquis depuis le 1er janvier
     * (ou l'embauche) en mois complets × jours/mois − congés annuels approuvés cette année.
     * Même calcul dans l'application (hors ligne).
     */
    public static function balances($employees): array
    {
        $yearStart = now()->startOfYear();
        $taken = Leave::where('status', 'approved')->where('type', 'annual')
            ->whereIn('employee_id', $employees->pluck('id'))->whereDate('start_date', '>=', $yearStart->toDateString())
            ->selectRaw('employee_id, SUM(days) as d')->groupBy('employee_id')->pluck('d', 'employee_id');
        $out = [];
        foreach ($employees as $e) {
            $from = $e->hire_date && $e->hire_date->gt($yearStart) ? $e->hire_date : $yearStart;
            $months = (int) floor($from->diffInMonths(now()));
            $out[$e->id] = round((float) $e->leave_opening_balance + max(0, $months) * (float) $e->leave_days_per_month - (float) ($taken[$e->id] ?? 0), 1);
        }

        return $out;
    }

    /**
     * Échéances RH à surveiller : fins de CDD et de stage, fins de période d'essai, anniversaires d'embauche
     * et de naissance du mois, entretiens en retard, parcours d'intégration non terminés.
     */
    public function deadlines(Request $request)
    {
        $days = max(7, min(180, (int) $request->query('days', 60)));
        $today = now()->startOfDay();
        $limit = $today->copy()->addDays($days);
        $out = [];
        $employees = Employee::where('active', true)->get()->keyBy('id');
        $push = function (string $kind, $date, ?Employee $e, string $label, string $level = 'info', array $extra = []) use (&$out, $today) {
            $d = $date ? \Carbon\Carbon::parse($date)->startOfDay() : null;
            $out[] = ['kind' => $kind, 'date' => $d?->toDateString(), 'days' => $d ? (int) $today->diffInDays($d, false) : null, 'employee_id' => $e?->id,
                'employee' => $e?->full_name, 'department' => $e?->department, 'label' => $label, 'level' => $level] + $extra;
        };
        // Contrats à durée déterminée
        $ending = \App\Modules\Rh\Models\Contract::where('status', 'active')->whereNotNull('end_date')->whereDate('end_date', '<=', $limit)->get();
        $seen = [];
        foreach ($ending as $c) {
            $e = $employees[$c->employee_id] ?? null;
            if (! $e) {
                continue;
            }
            $seen[$e->id] = true;
            $push('contract_end', $c->end_date, $e, ($c->type ?: 'Contrat').' : fin le '.$c->end_date->format('d/m/Y'), $c->end_date->lt($today->copy()->addDays(15)) ? 'danger' : 'warning', ['contract_id' => $c->id]);
        }
        foreach ($employees as $e) {
            if (! isset($seen[$e->id]) && $e->end_date && $e->end_date->lte($limit) && $e->end_date->gte($today->copy()->subDays(7))) {
                $push('contract_end', $e->end_date, $e, ($e->contract_type ?: 'Contrat').' : fin le '.$e->end_date->format('d/m/Y'), $e->end_date->lt($today->copy()->addDays(15)) ? 'danger' : 'warning');
            }
        }
        // Périodes d'essai
        foreach (\App\Modules\Rh\Models\Contract::where('status', 'active')->whereNotNull('trial_end_date')->whereDate('trial_end_date', '>=', $today->copy()->subDays(7))->whereDate('trial_end_date', '<=', $today->copy()->addDays(min($days, 45)))->get() as $c) {
            if ($e = $employees[$c->employee_id] ?? null) {
                $push('trial_end', $c->trial_end_date, $e, 'Fin de période d\'essai le '.$c->trial_end_date->format('d/m/Y').' : confirmer, renouveler ou arrêter', 'warning', ['contract_id' => $c->id]);
            }
        }
        // Anniversaires du mois (embauche et naissance)
        foreach ($employees as $e) {
            if ($e->hire_date && $e->hire_date->month === $today->month && ($y = $today->year - $e->hire_date->year) >= 1) {
                $push('work_anniversary', $e->hire_date->copy()->year($today->year), $e, "$y an".($y > 1 ? 's' : '').' dans l\'entreprise');
            }
            if ($e->birth_date && $e->birth_date->month === $today->month) {
                $push('birthday', $e->birth_date->copy()->year($today->year), $e, 'Anniversaire');
            }
        }
        // Talents : entretiens en retard, parcours ouverts
        if (app('tenant')->hasModule('talents') && class_exists(\App\Modules\Talents\Models\Review::class)) {
            foreach (\App\Modules\Talents\Models\Review::whereIn('status', ['planned', 'self', 'manager'])->whereNotNull('scheduled_on')->whereDate('scheduled_on', '<=', $limit)->get() as $r) {
                if ($e = $employees[$r->employee_id] ?? null) {
                    $late = $r->scheduled_on->lt($today);
                    $push('review', $r->scheduled_on, $e, (\App\Modules\Talents\Models\Review::TYPES[$r->type] ?? 'Entretien').($late ? ' en retard' : ' prévu'), $late ? 'warning' : 'info', ['review_id' => $r->id]);
                }
            }
            foreach (\App\Modules\Talents\Models\Checklist::where('status', 'open')->get() as $c) {
                if ($e = $employees[$c->employee_id] ?? null) {
                    $done = count(array_filter((array) $c->items, fn ($i) => ! empty($i['done'])));
                    $push('checklist', $c->due_date ?? $c->start_date, $e, \App\Modules\Talents\Models\Checklist::KINDS[$c->kind].' : '.$done.'/'.count((array) $c->items).' tâches faites', $c->due_date && $c->due_date->lt($today) ? 'warning' : 'info', ['checklist_id' => $c->id]);
                }
            }
        }
        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => [$rank[$a['level']], $a['date'] ?? '9999'] <=> [$rank[$b['level']], $b['date'] ?? '9999']);

        return ['days' => $days, 'items' => $out, 'counts' => array_count_values(array_column($out, 'kind'))];
    }

    // ---------- Solde de tout compte ----------

    protected function settlementInput(Request $request): array
    {
        return $request->validate([
            'end_date' => 'required|date',
            'reason' => 'required|in:'.implode(',', array_keys(SettlementService::REASONS)),
            'notice_worked' => 'nullable|boolean',
            'notice_months' => 'nullable|numeric|min:0|max:12',
            'leave_days' => 'nullable|numeric|min:0|max:200',
            'average' => 'nullable|numeric|min:0',
            'lines' => 'nullable|array|max:30',
            'lines.*.label' => 'required|string|max:160',
            'lines.*.amount' => 'required|numeric|min:0',
            'lines.*.code' => 'nullable|string|max:30',
            'lines.*.taxable' => 'nullable|boolean',
            'lines.*.social' => 'nullable|boolean',
            'lines.*.detail' => 'nullable|string|max:300',
        ]);
    }

    public function settlementPreview(Request $request, string $id, SettlementService $svc)
    {
        $e = Employee::findOrFail($id);

        return $svc->compute(app('tenant'), $e, $this->settlementInput($request)) + ['reasons' => SettlementService::REASONS, 'current' => $e->settlement];
    }

    public function settlementApply(Request $request, string $id, SettlementService $svc)
    {
        $e = Employee::findOrFail($id);
        $in = $this->settlementInput($request);
        $r = $svc->apply(app('tenant'), $e, $in, $in['lines'] ?? []);
        \App\Models\ActivityLog::record('rh.settlement.validated', $e, ['total' => $r['total'], 'reason' => $r['reason']]);

        return $r;
    }

    public function settlementCancel(string $id)
    {
        $e = Employee::findOrFail($id);
        $period = $e->settlement['period'] ?? null;
        $e->forceFill(['settlement' => null])->save();
        if ($period && ($run = \App\Modules\Paie\Models\PayrollRun::where('period', $period)->where('status', 'draft')->first())) {
            app(\App\Modules\Paie\PayrollService::class)->refresh(app('tenant'), $run);
        }

        return ['ok' => true];
    }
}
