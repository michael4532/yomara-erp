<?php

namespace App\Modules\TableauxExcel;

use App\Http\Controllers\Controller;
use App\Modules\TableauxExcel\Models\Workbook;
use App\Support\BranchScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TableauxExcelController extends Controller
{
    public function __construct(protected TableauxExcelService $svc) {}

    public function index(Request $r)
    {
        $c = app('tenant');
        $available = Templates::available($c);
        $branches = $c->hasModule('agences') ? DB::table('branches')->where('company_id', $c->id)->whereNull('deleted_at')
            ->when(BranchScope::allowed(), fn ($q, $a) => $q->whereIn('id', $a))->orderBy('name')->get(['id', 'name']) : collect();
        $first = (int) substr((string) (DB::table('trade_documents')->where('company_id', $c->id)->min('date') ?? now()->toDateString()), 0, 4);

        return [
            'templates' => collect(Templates::catalog())->map(fn ($t, $k) => ['key' => $k, ...$t, 'available' => array_key_exists($k, $available)])->values(),
            'themes' => collect(XlsxDashboard::THEMES)->map(fn ($t, $k) => ['key' => $k, 'name' => $t['name'], 'primary' => $t['primary'], 'deep' => $t['deep'], 'tint' => $t['tint'],
                'dark' => ! empty($t['dark']), 'chart' => $t['chart']])->values(),
            'years' => range(now()->year, max(now()->year - 6, min($first, now()->year))),
            'branches' => $branches,
            'frequencies' => TableauxExcelService::FREQUENCIES,
            'workbooks' => Workbook::orderBy('name')->get()->map(fn ($w) => $this->row($w)),
            'can_manage' => $r->user()->hasPermission('tableaux_excel.manage'),
        ];
    }

    protected function row(Workbook $w): array
    {
        return $w->only(['id', 'name', 'template', 'theme', 'year', 'branch_id', 'protect', 'frequency', 'recipients', 'message', 'last_status', 'last_error', 'downloads'])
            + ['last_generated_at' => $w->last_generated_at?->toIso8601String(), 'last_sent_at' => $w->last_sent_at?->toIso8601String(),
                'owner' => $w->owner_id ? DB::table('users')->where('id', $w->owner_id)->value('name') : null];
    }

    protected function rules(): array
    {
        return [
            'template' => ['required', Rule::in(array_keys(Templates::catalog()))],
            'theme' => ['nullable', Rule::in(array_keys(XlsxDashboard::THEMES))],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'branch_id' => ['nullable', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:120'],
            'protect' => ['nullable', 'boolean'],
        ];
    }

    /** Téléchargement direct (GET pour l'application). */
    public function generate(Request $r)
    {
        $o = $r->validate($this->rules());
        $o['protect'] = $r->boolean('protect');
        try {
            $path = $this->svc->generate(app('tenant'), $o);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }
        $name = $o['title'] ?? (Templates::catalog()[$o['template']]['name']);

        return response()->download($path, TableauxExcelService::filename($name))->deleteFileAfterSend();
    }

    public function download(int $id)
    {
        $w = Workbook::findOrFail($id);
        try {
            $path = $this->svc->generate(app('tenant'), $this->svc->options($w));
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }
        $w->forceFill(['last_generated_at' => now(), 'downloads' => $w->downloads + 1])->save();

        return response()->download($path, TableauxExcelService::filename($w->name))->deleteFileAfterSend();
    }

    public function save(Request $r, ?int $id = null)
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:120'],
            ...array_diff_key($this->rules(), ['title' => 1]),
            'frequency' => ['nullable', Rule::in(array_keys(TableauxExcelService::FREQUENCIES))],
            'recipients' => ['nullable', 'array', 'max:20'],
            'recipients.*' => ['email'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        if (($d['frequency'] ?? 'none') !== 'none' && empty($d['recipients'])) {
            abort(422, 'Ajoutez au moins un destinataire pour l\'envoi automatique.');
        }
        if (! empty($d['branch_id']) && ! BranchScope::canUse($d['branch_id'])) {
            abort(422, 'Agence non autorisée.');
        }
        $w = $id ? Workbook::findOrFail($id) : new Workbook(['owner_id' => $r->user()->id]);
        $w->fill([...$d, 'protect' => $r->boolean('protect'), 'frequency' => $d['frequency'] ?? 'none', 'recipients' => array_values(array_unique($d['recipients'] ?? []))]);
        $w->save();

        return $this->row($w->fresh());
    }

    public function destroy(int $id)
    {
        Workbook::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function sendNow(int $id)
    {
        $w = Workbook::findOrFail($id);
        $ok = $this->svc->send(app('tenant'), $w);
        $w->refresh();
        abort_unless($ok, 422, $w->last_error ?: 'Envoi impossible.');

        return $this->row($w);
    }
}
