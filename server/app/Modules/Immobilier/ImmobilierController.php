<?php

namespace App\Modules\Immobilier;

use App\Http\Controllers\Controller;
use App\Modules\Immobilier\Models\Expense;
use App\Modules\Immobilier\Models\Lease;
use App\Modules\Immobilier\Models\Payout;
use App\Modules\Immobilier\Models\Property;
use App\Modules\Immobilier\Models\Rent;
use App\Modules\Immobilier\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImmobilierController extends Controller
{
    public function __construct(protected ImmobilierService $svc) {}

    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function meta(): array
    {
        return ['property_kinds' => ImmobilierService::PROPERTY_KINDS, 'unit_kinds' => ImmobilierService::UNIT_KINDS, 'unit_statuses' => ImmobilierService::UNIT_STATUSES,
            'expense_categories' => ImmobilierService::EXPENSE_CATEGORIES, 'periodicity' => ImmobilierService::PERIODICITY,
            'methods' => ['cash' => 'Espèces', 'mobile_money' => 'Mobile Money', 'bank_transfer' => 'Virement', 'cheque' => 'Chèque', 'card' => 'Carte']];
    }

    public function dashboard(Request $r)
    {
        return ['data' => $this->svc->dashboard(app('tenant'), $r->query('month', now()->format('Y-m'))) + ['meta' => $this->meta()]];
    }

    public function properties()
    {
        $props = Property::orderBy('name')->get();
        $units = Unit::orderBy('reference')->get()->groupBy('property_id');
        $leases = Lease::where('status', 'active')->get()->keyBy('unit_id');
        $partners = DB::table('partners')->whereIn('id', $props->pluck('owner_id')->merge($leases->pluck('tenant_id'))->filter()->unique())->get(['id', 'name', 'phone'])->keyBy('id');

        return ['data' => $props->map(fn ($p) => $p->toArray() + [
            'kind_label' => ImmobilierService::PROPERTY_KINDS[$p->kind] ?? $p->kind, 'owner' => $p->owner_id ? (array) ($partners[$p->owner_id] ?? null) : null,
            'units' => ($units[$p->id] ?? collect())->map(fn ($u) => $u->toArray() + ['kind_label' => ImmobilierService::UNIT_KINDS[$u->kind] ?? $u->kind,
                'status_label' => ImmobilierService::UNIT_STATUSES[$u->status] ?? $u->status,
                'lease' => isset($leases[$u->id]) ? ['id' => $leases[$u->id]->id, 'number' => $leases[$u->id]->number, 'rent' => $leases[$u->id]->rent, 'charges' => $leases[$u->id]->charges,
                    'tenant' => (array) ($partners[$leases[$u->id]->tenant_id] ?? null)] : null])->values(),
        ])->values(), 'meta' => $this->meta()];
    }

    protected function propertyRules(): array
    {
        return ['name' => 'required|string|max:160', 'kind' => 'nullable|in:'.implode(',', array_keys(ImmobilierService::PROPERTY_KINDS)), 'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120', 'district' => 'nullable|string|max:120', 'owner_id' => 'nullable|uuid', 'owner_name' => 'nullable|string|max:160',
            'owner_phone' => 'nullable|string|max:40', 'owner_email' => 'nullable|email', 'fee_rate' => 'nullable|numeric|min:0|max:50', 'notes' => 'nullable|string|max:2000', 'active' => 'nullable|boolean'];
    }

    public function saveProperty(Request $r, ?int $id = null)
    {
        $d = $r->validate($this->propertyRules());

        return $this->guard(fn () => response()->json(['data' => $this->svc->saveProperty($id ? Property::findOrFail($id) : null, $d)], $id ? 200 : 201));
    }

    public function deleteProperty(int $id)
    {
        $p = Property::findOrFail($id);
        if (Rent::where('property_id', $id)->exists() || Lease::whereIn('unit_id', Unit::where('property_id', $id)->pluck('id'))->exists()) {
            return response()->json(['message' => 'Ce bien a des baux ou des loyers : désactivez-le plutôt que de le supprimer.'], 422);
        }
        Unit::where('property_id', $id)->delete();
        $p->delete();

        return response()->noContent();
    }

    public function saveUnit(Request $r, ?int $id = null)
    {
        $d = $r->validate(['property_id' => ($id ? 'sometimes' : 'required').'|integer', 'reference' => ($id ? 'sometimes' : 'required').'|string|max:60',
            'kind' => 'nullable|in:'.implode(',', array_keys(ImmobilierService::UNIT_KINDS)), 'rooms' => 'nullable|integer|min:0|max:50', 'surface' => 'nullable|numeric|min:0',
            'floor' => 'nullable|string|max:20', 'rent' => 'nullable|numeric|min:0', 'charges' => 'nullable|numeric|min:0', 'status' => 'nullable|in:vacant,works', 'notes' => 'nullable|string|max:2000']);

        return $this->guard(fn () => response()->json(['data' => $this->svc->saveUnit($id ? Unit::findOrFail($id) : null, $d)], $id ? 200 : 201));
    }

    public function deleteUnit(int $id)
    {
        $u = Unit::findOrFail($id);
        if (Lease::where('unit_id', $id)->exists()) {
            return response()->json(['message' => 'Ce lot a un historique de baux : il ne peut pas être supprimé.'], 422);
        }
        $u->delete();

        return response()->noContent();
    }

    public function leases(Request $r)
    {
        $q = Lease::query()->orderByDesc('id');
        if ($r->query('status', 'active') !== 'all') {
            $q->where('status', $r->query('status', 'active'));
        }

        return ['data' => $q->get()->map(fn ($l) => $this->svc->leaseToApi($l))->values(), 'meta' => $this->meta()];
    }

    public function lease(int $id)
    {
        $l = Lease::findOrFail($id);
        $rents = Rent::where('lease_id', $id)->orderByDesc('period_start')->get();
        $ctx = $this->svc->context($rents);

        return ['data' => $this->svc->leaseToApi($l) + ['rents' => $rents->map(fn ($x) => $this->svc->rentToApi($x, $ctx))->values()]];
    }

    public function createLease(Request $r)
    {
        $d = $r->validate([
            'unit_id' => 'required|integer', 'tenant_id' => 'nullable|uuid', 'tenant_name' => 'nullable|required_without:tenant_id|string|max:160', 'tenant_phone' => 'nullable|string|max:40',
            'tenant_email' => 'nullable|email', 'usage' => 'nullable|in:habitation,professionnel', 'start_date' => 'required|date', 'end_date' => 'nullable|date|after:start_date',
            'rent' => 'required|numeric|min:1', 'charges' => 'nullable|numeric|min:0', 'tax_rate' => 'nullable|numeric|min:0|max:30', 'months' => 'nullable|in:1,3,6,12',
            'due_day' => 'nullable|integer|min:1|max:28', 'deposit_amount' => 'nullable|numeric|min:0', 'advance_months' => 'nullable|integer|min:0|max:12',
            'revision_rate' => 'nullable|numeric|min:0|max:20', 'next_revision_date' => 'nullable|date', 'guarantor_name' => 'nullable|string|max:160', 'guarantor_phone' => 'nullable|string|max:40',
            'notes' => 'nullable|string|max:2000', 'entry_payment' => 'nullable|array', 'entry_payment.method' => 'nullable|string|max:20', 'entry_payment.reference' => 'nullable|string|max:80',
        ]);

        return $this->guard(fn () => response()->json(['data' => $this->svc->leaseToApi($this->svc->createLease($d))], 201));
    }

    public function updateLease(Request $r, int $id)
    {
        $d = $r->validate(['end_date' => 'nullable|date', 'rent' => 'sometimes|numeric|min:1', 'charges' => 'sometimes|numeric|min:0', 'tax_rate' => 'sometimes|numeric|min:0|max:30',
            'due_day' => 'sometimes|integer|min:1|max:28', 'deposit_amount' => 'sometimes|numeric|min:0', 'revision_rate' => 'sometimes|numeric|min:0|max:20', 'next_revision_date' => 'nullable|date',
            'guarantor_name' => 'nullable|string|max:160', 'guarantor_phone' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:2000', 'usage' => 'sometimes|in:habitation,professionnel']);

        return $this->guard(fn () => ['data' => $this->svc->leaseToApi($this->svc->updateLease(Lease::findOrFail($id), $d))]);
    }

    public function endLease(Request $r, int $id)
    {
        $d = $r->validate(['date' => 'nullable|date', 'reason' => 'nullable|string|max:300', 'deposit_retained' => 'nullable|numeric|min:0', 'deposit_note' => 'nullable|string|max:300', 'works' => 'nullable|boolean']);

        return $this->guard(fn () => ['data' => $this->svc->leaseToApi($this->svc->endLease(Lease::findOrFail($id), $d))]);
    }

    public function deposit(Request $r, int $id)
    {
        $d = $r->validate(['amount' => 'required|numeric|min:1', 'date' => 'nullable|date']);

        return $this->guard(fn () => ['data' => $this->svc->leaseToApi($this->svc->deposit(Lease::findOrFail($id), $d))]);
    }

    public function remind(int $id)
    {
        return $this->guard(function () use ($id) {
            $this->svc->remind(app('tenant'), Lease::findOrFail($id));

            return ['data' => ['sent' => true]];
        });
    }

    public function rents(Request $r)
    {
        $q = Rent::query();
        $status = $r->query('status', 'all');
        $r->validate(['month' => 'nullable|date_format:Y-m']);
        if ($r->filled('month') && $status !== 'overdue') {
            $m = Carbon::parse($r->query('month').'-01');
            $q->where('period_start', '<=', $m->copy()->endOfMonth()->toDateString())->where('period_end', '>=', $m->toDateString());
        }
        if ($status === 'overdue') {
            $q->where('due_date', '<', now()->toDateString());
        }
        if ($r->filled('property_id')) {
            $q->where('property_id', (int) $r->query('property_id'));
        }
        $rents = $q->orderByDesc('period_start')->orderBy('unit_id')->limit(1000)->get();
        $ctx = $this->svc->context($rents);
        $rows = $rents->map(fn ($x) => $this->svc->rentToApi($x, $ctx));
        $rows = match ($status) {
            'overdue', 'open' => $rows->filter(fn ($x) => in_array($x['status'], ['unpaid', 'partial'], true)),
            'paid' => $rows->where('status', 'paid'),
            default => $rows,
        };

        return ['data' => $rows->values(), 'meta' => $this->meta()];
    }

    public function generate(Request $r)
    {
        $d = $r->validate(['until' => 'nullable|date']);
        $res = $this->svc->runDue(app('tenant'), $d['until'] ?? null);

        return ['data' => $res];
    }

    public function pay(Request $r, int $id)
    {
        $d = $r->validate(['amount' => 'nullable|numeric|min:1', 'method' => 'nullable|string|max:20', 'reference' => 'nullable|string|max:80', 'date' => 'nullable|date', 'treasury_account_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('tr_accounts')]]);

        return $this->guard(fn () => ['data' => $this->svc->rentToApi($this->svc->pay(Rent::findOrFail($id), array_filter($d, fn ($v) => $v !== null)))]);
    }

    public function rentPdf(int $id)
    {
        $r = Rent::findOrFail($id);

        return response($this->svc->rentPdf(app('tenant'), $r), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="loyer-'.$r->period_start->format('Y-m').'.pdf"']);
    }

    public function expenses(Request $r)
    {
        $q = Expense::query()->orderByDesc('date');
        if ($r->filled('property_id')) {
            $q->where('property_id', (int) $r->query('property_id'));
        }
        $props = Property::pluck('name', 'id');

        return ['data' => $q->limit(500)->get()->map(fn ($x) => $x->toArray() + ['property_name' => $props[$x->property_id] ?? null,
            'unit_reference' => $x->unit_id ? Unit::whereKey($x->unit_id)->value('reference') : null, 'category_label' => ImmobilierService::EXPENSE_CATEGORIES[$x->category] ?? $x->category])->values()];
    }

    public function saveExpense(Request $r)
    {
        $d = $r->validate(['property_id' => 'required|integer', 'unit_id' => 'nullable|integer', 'date' => 'required|date', 'label' => 'required|string|max:200',
            'category' => 'nullable|in:'.implode(',', array_keys(ImmobilierService::EXPENSE_CATEGORIES)), 'amount' => 'required|numeric|min:1', 'owner_charge' => 'nullable|boolean',
            'supplier' => 'nullable|string|max:160', 'notes' => 'nullable|string|max:2000']);
        Property::findOrFail($d['property_id']);
        if (! empty($d['unit_id']) && ! Unit::whereKey($d['unit_id'])->where('property_id', $d['property_id'])->exists()) {
            return response()->json(['message' => 'Ce lot n\'appartient pas à ce bien.'], 422);
        }

        return response()->json(['data' => Expense::create($d)], 201);
    }

    public function deleteExpense(int $id)
    {
        Expense::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function owners()
    {
        $props = Property::whereNotNull('owner_id')->get()->groupBy('owner_id');
        $partners = DB::table('partners')->whereIn('id', $props->keys())->get(['id', 'name', 'phone', 'email'])->keyBy('id');

        return ['data' => $props->map(fn ($g, $id) => ['id' => $id, 'name' => $partners[$id]->name ?? '—', 'phone' => $partners[$id]->phone ?? null, 'properties' => $g->count(),
            'units' => Unit::whereIn('property_id', $g->pluck('id'))->count(), 'last_payout' => Payout::where('owner_id', $id)->max('paid_at')])->sortBy('name')->values()
            ->prepend(['id' => null, 'name' => 'Biens de la société', 'phone' => null, 'properties' => Property::whereNull('owner_id')->count(),
                'units' => Unit::whereIn('property_id', Property::whereNull('owner_id')->pluck('id'))->count(), 'last_payout' => null])->values()];
    }

    protected function period(Request $r): array
    {
        $d = $r->validate(['owner_id' => 'nullable|uuid', 'from' => 'nullable|date', 'to' => 'nullable|date']);

        return [$d['owner_id'] ?? null, $d['from'] ?? now()->subMonthNoOverflow()->startOfMonth()->toDateString(), $d['to'] ?? now()->subMonthNoOverflow()->endOfMonth()->toDateString()];
    }

    public function statement(Request $r)
    {
        [$o, $f, $t] = $this->period($r);

        return ['data' => $this->svc->statement($o, $f, $t)];
    }

    public function statementPdf(Request $r)
    {
        [$o, $f, $t] = $this->period($r);

        return response($this->svc->statementPdf(app('tenant'), $this->svc->statement($o, $f, $t)), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="releve-gerance.pdf"']);
    }

    public function savePayout(Request $r)
    {
        $d = $r->validate(['owner_id' => ['required', 'uuid', \App\Support\TenantRef::of('partners')], 'period_from' => 'required|date', 'period_to' => 'required|date', 'amount' => 'nullable|numeric|min:1', 'paid_at' => 'nullable|date',
            'method' => 'nullable|string|max:20', 'reference' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:1000']);

        return $this->guard(fn () => response()->json(['data' => $this->svc->savePayout($d)], 201));
    }

    public function deletePayout(int $id)
    {
        Payout::findOrFail($id)->delete();

        return response()->noContent();
    }
}
