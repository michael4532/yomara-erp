<?php

namespace App\Modules\Analytique;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Analytique\Models\AnLine;
use App\Modules\Analytique\Models\Budget;
use App\Modules\Analytique\Models\BudgetLine;
use App\Modules\Analytique\Models\Section;
use App\Modules\Compta\Chart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalytiqueController extends Controller
{
    public function __construct(private AnalyticService $an, private BudgetService $bg) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function period(Request $r): array
    {
        $v = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        return [$v['from'] ?? now()->startOfYear()->toDateString(), $v['to'] ?? now()->endOfYear()->toDateString()];
    }

    // ---------------- États ----------------

    public function report(Request $r)
    {
        [$from, $to] = $this->period($r);

        return ['data' => $this->an->report(app('tenant'), $from, $to, $r->query('axis_id')) + ['from' => $from, 'to' => $to]];
    }

    public function section(Request $r, string $id)
    {
        [$from, $to] = $this->period($r);
        $s = Section::withTrashed()->findOrFail($id);
        $d = $this->an->sectionDetail(app('tenant'), $s, $from, $to);
        // Numéros des documents d'origine
        $docIds = collect($d['lines'])->where('source_type', 'document')->pluck('source_id')->unique()->all();
        $numbers = DB::table('trade_documents')->whereIn('id', $docIds)->get(['id', 'number', 'direction', 'type'])->keyBy('id');
        $d['lines'] = collect($d['lines'])->map(fn ($l) => $l->toArray() + ['document' => $numbers->get($l->source_id)])->values();

        return ['data' => $d + ['from' => $from, 'to' => $to]];
    }

    public function accounts()
    {
        $names = Chart::ACCOUNTS;
        if (Schema::hasTable('compta_accounts')) {
            $names = DB::table('compta_accounts')->where('company_id', app('tenant')->id)->pluck('name', 'code')->all() + $names;
        }
        $out = [];
        foreach ($names as $code => $name) {
            if (in_array(substr((string) $code, 0, 1), ['6', '7'], true)) {
                $out[] = ['code' => (string) $code, 'name' => $name];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return ['data' => $out];
    }

    public function users()
    {
        return ['data' => User::where('is_active', true)->orderBy('name')->get(['id', 'name'])];
    }

    // ---------------- Saisies ----------------

    public function storeManual(Request $r)
    {
        $v = $r->validate([
            'date' => 'required|date', 'label' => 'required|string|max:255', 'amount' => 'required|numeric|not_in:0',
            'account_code' => 'nullable|string|max:20', 'distribution' => 'required|array|min:1',
        ]);

        return $this->guard(function () use ($v) {
            $this->an->manual($v['date'], $v['label'], (float) $v['amount'], $v['account_code'] ?? null, $v['distribution']);
            app(BudgetService::class)->checkAlerts(app('tenant'), array_keys($v['distribution']));

            return response()->json(['ok' => true], 201);
        });
    }

    public function destroyManual(string $id)
    {
        $line = AnLine::where('source_type', 'manual')->findOrFail($id);
        AnLine::where('source_type', 'manual')->where('source_id', $line->source_id)->delete();

        return ['ok' => true];
    }

    public function rebuild(Request $r)
    {
        $from = $r->validate(['from' => 'nullable|date'])['from'] ?? null;

        return ['data' => $this->an->rebuild(app('tenant'), $from)];
    }

    // ---------------- Budgets ----------------

    public function budgets()
    {
        $out = [];
        foreach (Budget::with('lines')->orderByDesc('start_date')->get() as $b) {
            $s = $this->bg->summary(app('tenant'), $b);
            $out[] = collect($s)->except(['lines', 'monthly'])->all() + ['lines_count' => count($s['lines']), 'over' => collect($s['lines'])->where('kind', 'expense')->filter(fn ($l) => ($l['percent'] ?? 0) >= 100)->count()];
        }

        return ['data' => $out];
    }

    public function showBudget(string $id)
    {
        return ['data' => $this->bg->summary(app('tenant'), Budget::with('lines')->findOrFail($id))];
    }

    public function storeBudget(Request $r)
    {
        $v = $r->validate([
            'name' => 'required|string|max:255', 'start_date' => 'required|date', 'end_date' => 'required|date|after:start_date',
            'warn_percent' => 'integer|min:1|max:100', 'notes' => 'nullable|string|max:2000',
            'copy_from' => 'nullable|date', 'copy_percent' => 'integer|min:-90|max:500', 'axis_id' => 'nullable|uuid',
        ]);

        return $this->guard(function () use ($v) {
            if (\Illuminate\Support\Carbon::parse($v['start_date'])->diffInMonths(\Illuminate\Support\Carbon::parse($v['end_date'])) > 35) {
                throw new \DomainException('Un budget couvre au plus 36 mois.');
            }
            $b = new Budget(collect($v)->only(['name', 'start_date', 'end_date', 'warn_percent', 'notes'])->all());
            $b->company_id = app('tenant')->id;
            $b->created_by = auth()->id();
            $b->save();
            if (! empty($v['copy_from'])) {
                $this->bg->fromActual(app('tenant'), $b->fresh('lines'), $v['copy_from'], (int) ($v['copy_percent'] ?? 0), $v['axis_id'] ?? null);
            }

            return response()->json(['data' => $this->bg->summary(app('tenant'), $b->fresh('lines'))], 201);
        });
    }

    public function updateBudget(Request $r, string $id)
    {
        $b = Budget::with('lines')->findOrFail($id);
        $v = $r->validate([
            'name' => 'sometimes|string|max:255', 'warn_percent' => 'sometimes|integer|min:1|max:100', 'notes' => 'nullable|string|max:2000',
            'lines' => 'sometimes|array', 'lines.*.kind' => 'in:expense,revenue', 'lines.*.section_id' => 'nullable|uuid',
            'lines.*.account_prefix' => 'nullable|string|max:20', 'lines.*.label' => 'nullable|string|max:255', 'lines.*.owner_id' => 'nullable|integer',
            'lines.*.amounts' => 'nullable|array', 'lines.*.total' => 'nullable|numeric|min:0',
        ]);

        return $this->guard(function () use ($b, $v) {
            $b->fill(collect($v)->only(['name', 'warn_percent', 'notes'])->all())->save();
            if (array_key_exists('lines', $v)) {
                $this->bg->saveLines(app('tenant'), $b, $v['lines']);
                if ($b->status === 'approved') {
                    $this->bg->checkAlerts(app('tenant'));
                }
            }

            return ['data' => $this->bg->summary(app('tenant'), $b->fresh('lines'))];
        });
    }

    public function budgetAction(string $id, string $action)
    {
        $b = Budget::with('lines')->findOrFail($id);

        return $this->guard(function () use ($b, $action) {
            match ($action) {
                'approve' => $b->status === 'draft' && $b->lines->isNotEmpty()
                    ? $b->forceFill(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()])->save()
                    : throw new \DomainException($b->lines->isEmpty() ? 'Ajoutez au moins une ligne avant d\'approuver.' : 'Seul un brouillon peut être approuvé.'),
                'close' => $b->status === 'approved' ? $b->forceFill(['status' => 'closed'])->save() : throw new \DomainException('Seul un budget approuvé peut être clôturé.'),
                'reopen' => $b->status !== 'draft' ? $b->forceFill(['status' => 'draft', 'approved_by' => null, 'approved_at' => null])->save() : null,
                default => abort(404),
            };
            if ($b->status === 'approved') {
                $this->bg->checkAlerts(app('tenant'));
            }

            return ['data' => $this->bg->summary(app('tenant'), $b->fresh('lines'))];
        });
    }

    public function destroyBudget(string $id)
    {
        $b = Budget::findOrFail($id);
        if ($b->status === 'approved') {
            return response()->json(['message' => 'Repassez le budget en brouillon avant de le supprimer.'], 422);
        }
        BudgetLine::where('budget_id', $b->id)->delete();
        $b->delete();

        return ['ok' => true];
    }

    /** Budget restant des sections choisies (saisie d'un achat ou d'une note de frais). */
    public function budgetStatus(Request $r)
    {
        $ids = array_values(array_filter((array) $r->query('sections', [])));

        return ['data' => $ids ? $this->bg->status(app('tenant'), $ids) : []];
    }
}
