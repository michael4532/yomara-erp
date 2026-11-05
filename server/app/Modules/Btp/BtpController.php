<?php

namespace App\Modules\Btp;

use App\Http\Controllers\Controller;
use App\Modules\Btp\Models\Log;
use App\Modules\Btp\Models\Site;
use App\Modules\Btp\Models\Situation;
use Illuminate\Http\Request;

class BtpController extends Controller
{
    public function __construct(protected BtpService $svc) {}

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
        return ['statuses' => BtpService::STATUSES, 'units' => BtpService::UNITS, 'weather' => BtpService::WEATHER];
    }

    protected function detail(Site $s): array
    {
        return ['data' => $this->svc->summary($s->fresh(), true), 'meta' => $this->meta()];
    }

    public function dashboard()
    {
        return ['data' => $this->svc->dashboard(app('tenant')), 'meta' => $this->meta()];
    }

    public function show(int $id)
    {
        return $this->detail(Site::findOrFail($id));
    }

    public function saveSite(Request $r, ?int $id = null)
    {
        $d = $r->validate([
            'name' => ($id ? 'sometimes' : 'required').'|string|max:160', 'reference' => 'nullable|string|max:40', 'address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:120',
            'client_id' => 'nullable|uuid', 'client_name' => 'nullable|string|max:160', 'client_phone' => 'nullable|string|max:40',
            'tax_rate' => 'nullable|numeric|min:0|max:30', 'retention_rate' => 'nullable|numeric|min:0|max:10', 'advance_rate' => 'nullable|numeric|min:0|max:50',
            'start_date' => 'nullable|date', 'end_date' => 'nullable|date', 'notes' => 'nullable|string|max:3000',
        ]);

        return $this->guard(fn () => response()->json($this->detail($this->svc->saveSite($id ? Site::findOrFail($id) : null, $d)), $id ? 200 : 201));
    }

    public function saveItems(Request $r, int $id)
    {
        $d = $r->validate(['items' => 'present|array|max:2000', 'items.*.id' => 'nullable|integer', 'items.*.lot' => 'nullable|string|max:120', 'items.*.code' => 'nullable|string|max:20',
            'items.*.designation' => 'required|string|max:255', 'items.*.unit' => 'nullable|in:'.implode(',', array_keys(BtpService::UNITS)), 'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0', 'items.*.unit_price' => 'required|numeric|min:0']);

        return $this->guard(fn () => $this->detail($this->svc->saveItems(Site::findOrFail($id), $d['items'])));
    }

    public function quote(int $id)
    {
        return $this->guard(function () use ($id) {
            $s = Site::findOrFail($id);
            $this->svc->quote($s);

            return $this->detail($s);
        });
    }

    public function accept(Request $r, int $id)
    {
        $d = $r->validate(['reference' => 'nullable|string|max:40', 'start_date' => 'nullable|date']);

        return $this->guard(fn () => $this->detail($this->svc->accept(Site::findOrFail($id), $d)));
    }

    public function lost(int $id)
    {
        return $this->guard(fn () => $this->detail($this->svc->lost(Site::findOrFail($id))));
    }

    public function advance(int $id)
    {
        return $this->guard(function () use ($id) {
            $s = Site::findOrFail($id);
            $this->svc->advance($s);

            return $this->detail($s);
        });
    }

    protected function progress(Request $r): array
    {
        $d = $r->validate(['progress' => 'present|array', 'progress.*' => 'numeric|min:0', 'date' => 'nullable|date', 'notes' => 'nullable|string|max:2000']);

        return [array_combine(array_map('intval', array_keys($d['progress'])), array_values($d['progress'])) ?: [], $d['date'] ?? null, $d['notes'] ?? null];
    }

    public function preview(Request $r, int $id)
    {
        [$p] = $this->progress($r);

        return ['data' => $this->svc->computeSituation(Site::findOrFail($id), $p)];
    }

    public function createSituation(Request $r, int $id)
    {
        [$p, $date, $notes] = $this->progress($r);

        return $this->guard(function () use ($id, $p, $date, $notes) {
            $s = Site::findOrFail($id);
            $this->svc->createSituation($s, $p, $date, $notes);

            return response()->json($this->detail($s), 201);
        });
    }

    public function receive(Request $r, int $id)
    {
        $d = $r->validate(['date' => 'nullable|date']);

        return $this->guard(fn () => $this->detail($this->svc->receive(Site::findOrFail($id), $d['date'] ?? null)));
    }

    public function release(int $id)
    {
        return $this->guard(function () use ($id) {
            $s = Site::findOrFail($id);
            $this->svc->releaseRetention($s);

            return $this->detail($s);
        });
    }

    public function saveLog(Request $r, int $id)
    {
        $s = Site::findOrFail($id);
        $d = $r->validate(['date' => 'required|date', 'weather' => 'nullable|in:'.implode(',', array_keys(BtpService::WEATHER)), 'workers' => 'nullable|integer|min:0|max:5000',
            'work_done' => 'nullable|string|max:5000', 'incidents' => 'nullable|string|max:5000']);
        Log::create($d + ['site_id' => $s->id, 'user_id' => auth()->id()]);

        return response()->json($this->detail($s), 201);
    }

    public function deleteLog(int $id)
    {
        $l = Log::findOrFail($id);
        abort_unless($l->user_id === auth()->id() || auth()->user()->hasPermission('btp.manage'), 403);
        $l->delete();

        return response()->noContent();
    }

    public function estimatePdf(Request $r, int $id)
    {
        $internal = $r->boolean('internal') && auth()->user()->hasPermission('btp.manage');

        return response($this->svc->estimatePdf(app('tenant'), Site::findOrFail($id), $internal), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="devis-chantier.pdf"']);
    }

    public function situationPdf(int $id)
    {
        $x = Situation::findOrFail($id);

        return response($this->svc->situationPdf(app('tenant'), Site::findOrFail($x->site_id), $x), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="situation-'.$x->number.'.pdf"']);
    }
}
