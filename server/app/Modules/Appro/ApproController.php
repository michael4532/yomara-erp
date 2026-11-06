<?php

namespace App\Modules\Appro;

use App\Http\Controllers\Controller;
use App\Modules\Appro\Models\Comparison;
use App\Modules\Appro\Models\ImportFile;
use App\Modules\Appro\Models\OfferInvite;
use App\Modules\Appro\Models\Rental;
use App\Modules\Appro\Models\Spec;
use App\Support\TenantRef;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ApproController extends Controller
{
    public function __construct(private ImportService $imports, private SourcingService $sourcing, private RentalService $rentals, private SupplierHistory $suppliers, private OfferService $offers) {}

    protected function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function pdf(string $bytes, string $name)
    {
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'.pdf"']);
    }

    protected function xlsx(array $headers, array $rows, string $name)
    {
        $path = tempnam(sys_get_temp_dir(), 'ap').'.xlsx';
        \App\Modules\ImportExport\Sheet::write($path, $headers, $rows);

        return response()->download($path, $name.'.xlsx')->deleteFileAfterSend();
    }

    public function meta()
    {
        $c = app('tenant');

        return ['data' => [
            'import_statuses' => ImportService::STATUSES, 'transports' => ImportService::TRANSPORTS, 'incoterms' => ImportService::INCOTERMS, 'allocations' => ImportService::ALLOCATIONS,
            'cost_types' => collect(ImportService::COSTS)->map(fn ($v) => $v[0]), 'rental_kinds' => RentalService::KINDS, 'rental_units' => RentalService::UNITS, 'rental_statuses' => RentalService::STATUSES,
            'spec_sections' => SourcingService::SPEC_SECTIONS, 'currency' => $c->currency,
            'currencies' => class_exists(\App\Modules\Devises\FxService::class) ? app(\App\Modules\Devises\FxService::class)->currencies($c) : [['code' => $c->currency, 'rate' => 1]],
        ]];
    }

    // ---------------------------------------------------------------- importations

    public function imports(Request $r)
    {
        $rows = ImportFile::when($r->query('status') === 'open', fn ($q) => $q->whereNotIn('status', ['closed', 'cancelled']))
            ->when($r->query('status') && $r->query('status') !== 'open', fn ($q) => $q->where('status', $r->query('status')))->orderByDesc('id')->limit(300)->get();

        return ['data' => $rows->map(fn ($f) => $this->imports->toApi($f))->values()];
    }

    public function import(int $id)
    {
        return ['data' => $this->imports->toApi(ImportFile::findOrFail($id), true)];
    }

    public function saveImport(Request $r, ?int $id = null)
    {
        $r->validate(['lines.*.product_id' => ['nullable', 'uuid', TenantRef::of('products')]]); // articles de la société uniquement
        $d = $r->validate(['title' => ($id ? 'sometimes' : 'required').'|string|max:200', 'partner_id' => ['nullable', 'uuid', TenantRef::of('partners')], 'origin_country' => 'nullable|string|max:60', 'currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0', 'incoterm' => 'nullable|string|max:10', 'transport' => 'nullable|string|max:10', 'allocation' => 'nullable|string|max:10', 'vat_rate' => 'nullable|numeric|min:0|max:100',
            'order_date' => 'nullable|date', 'eta' => 'nullable|date', 'arrived_at' => 'nullable|date', 'tracking' => 'nullable|string|max:120', 'forwarder' => 'nullable|string|max:160', 'project_id' => 'nullable|string|max:40',
            'notes' => 'nullable|string|max:5000', 'status' => 'nullable|string|max:12', 'lines' => 'sometimes|array|max:300', 'costs' => 'sometimes|array|max:50']);

        return $this->run(fn () => response()->json(['data' => $this->imports->toApi($this->imports->save(app('tenant'), $id ? ImportFile::findOrFail($id) : null, $d, $r->user()->id), true)], $id ? 200 : 201));
    }

    public function importAction(Request $r, int $id, string $action)
    {
        $f = ImportFile::findOrFail($id);

        return $this->run(function () use ($f, $action) {
            $extra = [];
            if ($action === 'apply') {
                $extra['updated'] = $this->imports->apply(app('tenant'), $f);
            } elseif ($action === 'order') {
                $extra['order_id'] = $this->imports->order(app('tenant'), $f)->id;
            }

            return ['data' => $this->imports->toApi($f->fresh(), true)] + $extra;
        });
    }

    public function importPdf(int $id)
    {
        $f = ImportFile::findOrFail($id);

        return $this->pdf($this->imports->pdf(app('tenant'), $f), 'cout-de-revient-'.$f->number);
    }

    public function importXlsx(int $id)
    {
        $f = ImportFile::findOrFail($id);
        $calc = $this->imports->compute($f);

        return $this->xlsx(['Article', 'Code SH', 'Quantité', 'PU devise ('.$f->currency.')', 'Valeur', 'Fret et assurance', 'Valeur CAF', 'Droits de douane', 'Autres frais', 'Coût de revient total', 'Coût unitaire', 'Coefficient', 'Coût actuel', 'Prix de vente', 'Marge %'],
            array_map(fn ($l) => [$l['description'], $l['hs_code'], $l['quantity'], $l['unit_price'], $l['value'], $l['cif_costs'], $l['cif'], $l['duty'], $l['other_costs'], $l['landed'], $l['unit_landed'], $l['coefficient'], $l['current_cost'], $l['sale_price'], $l['margin']], $calc['lines']),
            'cout-de-revient-'.$f->number);
    }

    // ---------------------------------------------------------------- cahiers des charges

    public function specs()
    {
        return ['data' => Spec::orderByDesc('id')->limit(300)->get()->map(fn ($s) => $s->toArray() + ['deadline' => $s->deadline?->toDateString()])->values()];
    }

    public function spec(int $id)
    {
        $s = Spec::findOrFail($id);

        return ['data' => $s->toArray() + ['deadline' => $s->deadline?->toDateString()]];
    }

    public function saveSpec(Request $r, ?int $id = null)
    {
        $d = $r->validate(['title' => ($id ? 'sometimes' : 'required').'|string|max:200', 'site' => 'nullable|string|max:200', 'project_id' => 'nullable|string|max:40', 'context' => 'nullable|string|max:10000',
            'deadline' => 'nullable|date', 'status' => 'nullable|in:draft,published', 'sections' => 'sometimes|array|max:40', 'requirements' => 'sometimes|array|max:300']);

        return $this->run(function () use ($r, $d, $id) {
            $s = $this->sourcing->saveSpec(app('tenant'), $id ? Spec::findOrFail($id) : null, $d, $r->user()->id);

            return response()->json(['data' => $s->toArray() + ['deadline' => $s->deadline?->toDateString()]], $id ? 200 : 201);
        });
    }

    public function specPdf(int $id)
    {
        $s = Spec::findOrFail($id);

        return $this->pdf($this->sourcing->specPdf(app('tenant'), $s), 'cahier-des-charges-'.$s->number);
    }

    public function sendSpec(Request $r, int $id)
    {
        $v = $r->validate(['partner_ids' => 'required|array|min:1|max:50', 'partner_ids.*' => 'uuid', 'message' => 'nullable|string|max:3000']);

        return $this->run(fn () => ['data' => $this->sourcing->sendSpec(app('tenant'), Spec::findOrFail($id), $v['partner_ids'], $v['message'] ?? null)]);
    }

    // ---------------------------------------------------------------- comparatifs

    public function comparisons(Request $r)
    {
        $rows = Comparison::when($r->query('source_type'), fn ($q) => $q->where('source_type', $r->query('source_type'))->where('source_id', (string) $r->query('source_id')))
            ->orderByDesc('id')->limit(300)->get();
        $counts = $this->offers->counts($rows->pluck('id')->all());

        return ['data' => $rows->map(fn ($x) => $this->sourcing->comparisonToApi(app('tenant'), $x) + ['online' => $counts[$x->id] ?? null])->values()];
    }

    public function comparison(int $id)
    {
        return ['data' => $this->full(Comparison::findOrFail($id))];
    }

    protected function full(Comparison $cmp): array
    {
        return $this->sourcing->comparisonToApi(app('tenant'), $cmp, true) + $this->offers->invitesToApi($cmp) + ['source' => $this->offers->sourceOf($cmp)];
    }

    /** « Comparer » depuis une demande d'achat, une demande de prix, un appel de fonds, un appel d'offres ou un cahier des charges. */
    public function compareFrom(Request $r)
    {
        $v = $r->validate(['type' => 'required|in:'.implode(',', array_keys(OfferService::SOURCES)), 'id' => 'required|string|max:40']);

        return $this->run(fn () => ['data' => $this->full($this->offers->fromSource(app('tenant'), $v['type'], $v['id'], $r->user()->id))]);
    }

    public function inviteSuppliers(Request $r, int $id)
    {
        $v = $r->validate(['partner_ids' => 'required|array|min:1|max:30', 'partner_ids.*' => 'uuid', 'message' => 'nullable|string|max:3000', 'channels' => 'nullable|array', 'channels.*' => 'in:email,whatsapp']);
        $cmp = Comparison::findOrFail($id);

        return $this->run(fn () => ['result' => $this->offers->invite(app('tenant'), $cmp, $v['partner_ids'], $v['message'] ?? null, $r->user(), $v['channels'] ?? ['email', 'whatsapp']), 'data' => $this->full($cmp->fresh())]);
    }

    public function offerMessage(Request $r, int $id)
    {
        $v = $r->validate(['invite_id' => 'required|integer', 'body' => 'required|string|max:5000', 'direction' => 'nullable|in:in,out', 'channel' => 'nullable|in:phone,email,whatsapp']);
        $cmp = Comparison::findOrFail($id);
        $inv = OfferInvite::where('comparison_id', $cmp->id)->findOrFail($v['invite_id']);

        return $this->run(function () use ($r, $v, $cmp, $inv) {
            $this->offers->buyerMessage(app('tenant'), $cmp, $inv, $v['body'], $r->user(), $v['direction'] ?? 'out', $v['channel'] ?? 'phone');

            return ['data' => $this->full($cmp->fresh())];
        });
    }

    public function offersRead(int $id)
    {
        $cmp = Comparison::findOrFail($id);
        $this->offers->markRead($cmp);

        return ['data' => $this->full($cmp)];
    }

    public function inviteFile(int $id, int $inviteId)
    {
        $inv = OfferInvite::where('comparison_id', $id)->findOrFail($inviteId);
        abort_unless($inv->file_path && Storage::disk('local')->exists($inv->file_path), 404);

        return Storage::disk('local')->download($inv->file_path, $inv->file_name ?: basename($inv->file_path));
    }

    public function saveComparison(Request $r, ?int $id = null)
    {
        $d = $r->validate(['title' => 'nullable|string|max:200', 'deadline' => 'nullable|date', 'notes' => 'nullable|string|max:3000', 'request_id' => 'nullable|uuid', 'spec_id' => 'nullable|integer',
            'lines' => 'sometimes|array|max:200', 'offers' => 'sometimes|array|max:20', 'instructions' => 'nullable|string|max:3000']);

        return $this->run(fn () => response()->json(['data' => $this->full($this->sourcing->saveComparison(app('tenant'), $id ? Comparison::findOrFail($id) : null, $d, $r->user()->id))], $id ? 200 : 201));
    }

    public function comparisonAction(Request $r, int $id, string $action)
    {
        $cmp = Comparison::findOrFail($id);
        $v = $r->validate(['offer' => 'nullable|string|max:40', 'note' => 'nullable|string|max:2000']);

        return $this->run(fn () => ['data' => $this->full(match ($action) {
            'prefill' => $this->sourcing->prefill(app('tenant'), $cmp),
            'award' => $this->sourcing->award(app('tenant'), $cmp, (string) ($v['offer'] ?? ''), $v['note'] ?? null),
            'cancel' => tap($cmp)->forceFill(['status' => 'cancelled'])->save() ? $cmp->fresh() : $cmp,
        })]);
    }

    public function comparisonPdf(int $id)
    {
        $x = Comparison::findOrFail($id);

        return $this->pdf($this->sourcing->comparisonPdf(app('tenant'), $x), 'comparatif-'.$x->number);
    }

    // ---------------------------------------------------------------- locations

    public function rentals(Request $r)
    {
        $rows = Rental::when($r->query('status') === 'open', fn ($q) => $q->whereIn('status', ['planned', 'active']))
            ->when($r->query('status') && $r->query('status') !== 'open', fn ($q) => $q->where('status', $r->query('status')))
            ->orderByRaw("CASE WHEN status IN ('planned','active') THEN 0 ELSE 1 END")->orderBy('end_date')->limit(400)->get();

        return ['data' => $rows->map(fn ($x) => $this->rentals->toApi($x))->values()];
    }

    public function rental(int $id)
    {
        return ['data' => $this->rentals->toApi(Rental::findOrFail($id), true)];
    }

    public function saveRental(Request $r, ?int $id = null)
    {
        $d = $r->validate(['kind' => 'nullable|string|max:12', 'title' => ($id ? 'sometimes' : 'required').'|string|max:200', 'partner_id' => ['nullable', 'uuid', TenantRef::of('partners')], 'start_date' => ($id ? 'sometimes' : 'required').'|date',
            'end_date' => ($id ? 'sometimes' : 'required').'|date', 'rate' => 'nullable|numeric|min:0', 'rate_unit' => 'nullable|string|max:8', 'quantity' => 'nullable|numeric|min:0', 'deposit' => 'nullable|numeric|min:0',
            'site' => 'nullable|string|max:200', 'project_id' => 'nullable|string|max:40', 'reference' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:3000', 'responsible_id' => ['nullable', 'integer', TenantRef::of('users')]]);

        return $this->run(fn () => response()->json(['data' => $this->rentals->toApi($this->rentals->save(app('tenant'), $id ? Rental::findOrFail($id) : null, $d, $r->user()), true)], $id ? 200 : 201));
    }

    public function rentalAction(Request $r, int $id, string $action)
    {
        $x = Rental::findOrFail($id);
        $v = $r->validate(['date' => 'nullable|date', 'end_date' => 'nullable|date', 'note' => 'nullable|string|max:2000', 'deposit_returned' => 'nullable|boolean']);
        $c = app('tenant');

        return $this->run(fn () => ['data' => $this->rentals->toApi(match ($action) {
            'extend' => $this->rentals->extend($c, $x, (string) ($v['end_date'] ?? ''), $v['note'] ?? null, $r->user()),
            'return' => $this->rentals->returnBack($c, $x, $v['date'] ?? null, $r->boolean('deposit_returned'), $v['note'] ?? null, $r->user()),
            'invoice' => $this->rentals->invoice($c, $x, $r->user()),
            'cancel' => $this->rentals->cancel($c, $x, $v['note'] ?? null, $r->user()),
        }, true)]);
    }

    public function rentalsXlsx()
    {
        $rows = Rental::orderBy('start_date')->get()->map(fn ($x) => $this->rentals->toApi($x));

        return $this->xlsx(['N°', 'Type', 'Objet', 'Fournisseur', 'Chantier', 'Début', 'Fin prévue', 'Retour', 'Jours', 'Tarif', 'Unité', 'Quantité', 'Coût', 'Caution', 'Statut'],
            $rows->map(fn ($x) => [$x['number'], $x['kind_label'], $x['title'], $x['partner'], $x['site'], $x['start_date'], $x['end_date'], $x['returned_at'], $x['days'], $x['rate'], $x['unit_label'], $x['quantity'], $x['amount'], $x['deposit'], $x['status_label']])->all(),
            'locations-et-services-externes');
    }

    // ---------------------------------------------------------------- fournisseurs

    public function scorecard(Request $r)
    {
        return ['data' => $this->suppliers->scorecard(app('tenant'), max(1, min(60, (int) $r->query('months', 12))))];
    }

    public function supplier(string $id)
    {
        return ['data' => $this->suppliers->history(app('tenant'), $id)];
    }

    public function scorecardXlsx(Request $r)
    {
        $rows = $this->suppliers->scorecard(app('tenant'), max(1, min(60, (int) $r->query('months', 12))));

        return $this->xlsx(['Fournisseur', 'Ville', 'Pays', 'Achats (12 mois)', 'Achats (12 mois précédents)', 'Factures', 'Commandes', 'Délai moyen (j)', 'À l\'heure (%)', 'Livraisons complètes (%)', 'Note /5', 'Dernière facture'],
            array_map(fn ($x) => [$x['name'], $x['city'], $x['country'], $x['spend'], $x['spend_prev'], $x['invoices'], $x['orders'], $x['lead_days'], $x['on_time'], $x['complete'], $x['score'], $x['last']], $rows), 'evaluation-fournisseurs');
    }

    // ---------------------------------------------------------------- tableau de bord

    public function dashboard()
    {
        $c = app('tenant');
        $imports = ImportFile::whereNotIn('status', ['cancelled'])->get();
        $open = $imports->whereIn('status', ['draft', 'ordered', 'in_transit', 'customs']);
        $calc = $imports->mapWithKeys(fn ($f) => [$f->id => $this->imports->compute($f)['totals']]);
        $received = $imports->whereIn('status', ['received', 'closed'])->filter(fn ($f) => ($calc[$f->id]['coefficient'] ?? null) !== null);
        $rentals = Rental::whereIn('status', ['planned', 'active'])->get()->map(fn ($x) => $this->rentals->toApi($x));
        $month = Rental::whereNotIn('status', ['cancelled'])->where('start_date', '<=', now()->endOfMonth()->toDateString())
            ->where(fn ($q) => $q->whereNull('returned_at')->where('end_date', '>=', now()->startOfMonth()->toDateString())->orWhere('returned_at', '>=', now()->startOfMonth()->toDateString()))->get();
        $monthCost = $month->sum(function ($x) {
            $from = max($x->start_date, now()->startOfMonth());
            $to = min($x->returned_at ?? max($x->end_date, now()->startOfDay()), now()->endOfMonth());

            return $x->rate_unit === 'fixed' ? 0 : $x->rate * RentalService::units($x->rate_unit, $from, $to, $x->quantity) * $x->quantity;
        });
        $cmps = Comparison::where('created_at', '>=', now()->startOfYear())->get()->map(fn ($x) => $this->sourcing->comparisonToApi($c, $x));
        $awarded = $cmps->where('status', 'awarded');

        return ['data' => [
            'kpis' => [
                'imports_open' => $open->count(), 'imports_value' => round($open->sum(fn ($f) => $calc[$f->id]['landed']), 2),
                'imports_late' => $open->filter(fn ($f) => $f->eta && $f->eta->lt(now()->startOfDay()) && $f->status !== 'draft')->count(),
                'coefficient' => $received->count() ? round($received->avg(fn ($f) => $calc[$f->id]['coefficient']), 3) : null,
                'rentals_active' => $rentals->where('status', 'active')->count(), 'rentals_overdue' => $rentals->where('overdue', true)->count(), 'rentals_month' => round($monthCost, 2),
                'rentals_ending' => $rentals->filter(fn ($x) => $x['days_left'] !== null && $x['days_left'] >= 0 && $x['days_left'] <= 7)->count(),
                'comparisons_open' => $cmps->where('status', 'open')->count(), 'savings' => round($awarded->sum('spread'), 2),
            ],
            'imports' => $open->sortBy('eta')->take(8)->map(fn ($f) => $this->imports->toApi($f))->values(),
            'rentals' => $rentals->sortBy('end_date')->take(8)->values(),
            'suppliers' => array_slice($this->suppliers->scorecard($c), 0, 8),
        ]];
    }
}
