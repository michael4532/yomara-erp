<?php

namespace App\Modules\Rapports;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Modules\Rapports\Models\Board;
use App\Modules\Rapports\Models\Report;
use App\Modules\Rapports\Models\Schedule;
use Illuminate\Http\Request;

class RapportsController extends Controller
{
    public function __construct(private ReportEngine $engine, private RapportsService $svc, private Exporter $exporter) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (AccessException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'forbidden'], 403);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function override(Request $r): array
    {
        $v = $r->validate(['period' => 'nullable|string|max:20', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        if (! empty($v['period']) && $v['period'] !== 'report' && ! isset(Period::PRESETS[$v['period']])) {
            abort(response()->json(['message' => 'Période inconnue.'], 422));
        }

        return array_filter($v);
    }

    /** Catalogue pour le constructeur : sources accessibles, périodes, présentations, rôles. */
    public function meta(Request $r)
    {
        $company = app('tenant');
        $sources = [];
        foreach (Sources::accessible($company, $r->user()) as $k => $s) {
            $sources[] = Sources::describe($k, $s);
        }

        return ['data' => [
            'sources' => $sources,
            'periods' => collect(Period::PRESETS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'compare' => collect(Period::COMPARE)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'charts' => collect(ReportEngine::CHARTS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'currency' => $company->currency,
        ]];
    }

    public function templates(Request $r)
    {
        $acc = Sources::accessible(app('tenant'), $r->user());
        $out = [];
        foreach (Templates::all() as $k => $t) {
            if (isset($acc[$t['source']])) {
                $out[] = ['key' => $k, 'source_label' => $acc[$t['source']]['label']] + $t;
            }
        }

        return ['data' => $out];
    }

    /** Aperçu d'une définition (constructeur). */
    public function preview(Request $r)
    {
        $v = $r->validate(['source' => 'required|string|max:40', 'config' => 'present|array']);

        return $this->guard(fn () => ['data' => $this->engine->run(app('tenant'), $r->user(), $v['source'], $v['config'])]);
    }

    public function values(Request $r)
    {
        $v = $r->validate(['source' => 'required|string|max:40', 'field' => 'required|string|max:40', 'q' => 'nullable|string|max:80']);

        return $this->guard(fn () => ['data' => $this->engine->values(app('tenant'), $r->user(), $v['source'], $v['field'], $v['q'] ?? null)]);
    }

    public function run(Request $r, string $id)
    {
        $override = $this->override($r);

        return $this->guard(fn () => ['data' => $this->svc->runReport(app('tenant'), $r->user(), Report::findOrFail($id), $override)]);
    }

    public function export(Request $r, string $id)
    {
        $format = $r->validate(['format' => 'nullable|in:xlsx,pdf'])['format'] ?? 'xlsx';
        $override = $this->override($r);

        return $this->guard(function () use ($r, $id, $format, $override) {
            $report = Report::findOrFail($id);
            $res = $this->svc->runReport(app('tenant'), $r->user(), $report, $override);
            $name = RapportsService::slug($report->name);
            if ($format === 'pdf') {
                return response($this->exporter->pdf(app('tenant'), $report->name, [['title' => $report->name, 'result' => $res]]), 200, [
                    'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
                ]);
            }
            $path = tempnam(sys_get_temp_dir(), 'rp').'.xlsx';
            $this->exporter->xlsx($res, $path);

            return response()->download($path, $name.'.xlsx')->deleteFileAfterSend();
        });
    }

    public function board(Request $r, string $id)
    {
        $override = $this->override($r);

        return $this->guard(fn () => ['data' => $this->svc->runBoard(app('tenant'), $r->user(), Board::findOrFail($id), $override)]);
    }

    public function boardExport(Request $r, string $id)
    {
        $override = $this->override($r);

        return $this->guard(function () use ($r, $id, $override) {
            $b = Board::findOrFail($id);

            return response($this->svc->boardPdf(app('tenant'), $r->user(), $b, $override), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.RapportsService::slug($b->name).'.pdf"',
            ]);
        });
    }

    /** Copie modifiable d'un rapport (ou d'un modèle) dans ses rapports privés. */
    public function duplicate(Request $r)
    {
        $v = $r->validate(['report_id' => 'nullable|uuid', 'template' => 'nullable|string|max:40', 'name' => 'nullable|string|max:150']);

        return $this->guard(function () use ($r, $v) {
            if (! empty($v['template'])) {
                $t = Templates::all()[$v['template']] ?? throw new \DomainException('Modèle inconnu.');
                [$source, $config, $name, $desc, $folder] = [$t['source'], $t['config'], $t['name'], $t['description'], $t['folder']];
            } else {
                $src = Report::findOrFail($v['report_id'] ?? null);
                if (! $src->isVisibleTo($r->user())) {
                    throw new AccessException('Ce rapport n\'est pas partagé avec vous.');
                }
                [$source, $config, $name, $desc, $folder] = [$src->source, $src->config, $src->name.' (copie)', $src->description, $src->folder];
            }
            $this->engine->assertAccess(app('tenant'), $r->user(), $source);
            $id = (string) \Illuminate\Support\Str::uuid();
            $res = app(\App\Services\EntityWriter::class)->upsert(Report::class, $id, [
                'name' => $v['name'] ?? $name, 'description' => $desc, 'source' => $source, 'config' => $config, 'folder' => $folder, 'visibility' => 'private',
            ]);
            if ($res['status'] !== \App\Services\EntityWriter::APPLIED) {
                throw new \DomainException($res['message'] ?? 'Rapport refusé.');
            }

            return response()->json(['data' => Report::find($id)->toSyncArray()], 201);
        });
    }

    // ------------------------------------------------------------ envois programmés

    private function scheduleArray(Schedule $s): array
    {
        $target = $s->report_id ? Report::withTrashed()->find($s->report_id) : Board::withTrashed()->find($s->dashboard_id);

        return $s->toArray() + ['target_name' => $target?->name ?? '—', 'target_kind' => $s->report_id ? 'report' : 'dashboard',
            'owner_name' => \App\Models\User::find($s->owner_id)?->name, 'frequency_label' => Schedule::FREQUENCIES[$s->frequency] ?? $s->frequency];
    }

    public function schedules()
    {
        return ['data' => Schedule::orderBy('id')->get()->map(fn ($s) => $this->scheduleArray($s))];
    }

    private function validateSchedule(Request $r, bool $creating): array
    {
        $v = $r->validate([
            'report_id' => 'nullable|uuid', 'dashboard_id' => 'nullable|uuid',
            'frequency' => ($creating ? 'required' : 'sometimes').'|in:daily,weekly,monthly',
            'weekday' => 'integer|min:1|max:7', 'monthday' => 'integer|min:1|max:28', 'hour' => 'integer|min:0|max:23',
            'recipients' => ($creating ? 'required' : 'sometimes').'|array|min:1|max:20', 'recipients.*' => 'email',
            'format' => 'in:pdf,xlsx', 'period' => 'nullable|string|max:20', 'message' => 'nullable|string|max:2000', 'active' => 'boolean',
        ]);
        if (! empty($v['period']) && ! isset(Period::PRESETS[$v['period']]) || ($v['period'] ?? null) === 'custom') {
            throw new \DomainException('Période inconnue.');
        }
        if ($creating && empty($v['report_id']) === empty($v['dashboard_id'])) {
            throw new \DomainException('Choisissez un rapport ou un tableau de bord.');
        }
        if (! empty($v['report_id']) && ! Report::find($v['report_id'])?->isVisibleTo($r->user())) {
            throw new \DomainException('Rapport introuvable.');
        }
        if (! empty($v['dashboard_id']) && ! Board::find($v['dashboard_id'])?->isVisibleTo($r->user())) {
            throw new \DomainException('Tableau de bord introuvable.');
        }
        if (isset($v['recipients'])) {
            $v['recipients'] = array_values(array_unique(array_map('strtolower', $v['recipients'])));
        }

        return $v;
    }

    public function storeSchedule(Request $r)
    {
        return $this->guard(function () use ($r) {
            $v = $this->validateSchedule($r, true);
            $s = new Schedule($v + ['owner_id' => $r->user()->id, 'active' => true]);
            $s->next_run_at = $this->svc->nextRun($s);
            $s->save();

            return response()->json(['data' => $this->scheduleArray($s)], 201);
        });
    }

    public function updateSchedule(Request $r, int $id)
    {
        return $this->guard(function () use ($r, $id) {
            $s = Schedule::findOrFail($id);
            $v = $this->validateSchedule($r, false);
            unset($v['report_id'], $v['dashboard_id']);
            $s->fill($v);
            $s->next_run_at = $s->active ? $this->svc->nextRun($s) : null;
            $s->save();

            return ['data' => $this->scheduleArray($s)];
        });
    }

    public function destroySchedule(int $id)
    {
        Schedule::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function sendSchedule(int $id)
    {
        $s = Schedule::findOrFail($id);
        $ok = $this->svc->send(app('tenant'), $s);

        return $ok ? ['data' => $this->scheduleArray($s->fresh())] : response()->json(['message' => 'Envoi impossible : '.$s->fresh()->last_error], 422);
    }
}
