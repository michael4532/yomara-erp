<?php

namespace App\Modules\Interventions;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Interventions\Models\Contract;
use App\Modules\Interventions\Models\Intervention;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class InterventionsController extends Controller
{
    public function __construct(protected InterventionService $service) {}

    /** Techniciens : utilisateurs ayant le droit d'intervenir. */
    public function technicians()
    {
        $users = User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($u) => $u->hasPermission('interventions.do'))
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'technician' => ! $u->is_super_admin && ! $u->hasPermission('interventions.manage')])
            ->sortByDesc('technician')->values();

        return ['technicians' => $users];
    }

    protected function fail(\DomainException $e)
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    public function invoice(string $id)
    {
        try {
            $doc = $this->service->invoice(app('tenant'), Intervention::findOrFail($id));
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return ['invoice' => $doc->toSyncArray()];
    }

    public function invoiceContract(string $id)
    {
        try {
            $doc = $this->service->invoiceContract(app('tenant'), Contract::findOrFail($id));
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return ['invoice' => $doc->toSyncArray()];
    }

    public function generate(Request $r)
    {
        $days = (int) ($r->validate(['days' => 'nullable|integer|min:0|max:90'])['days'] ?? 7);
        $created = $this->service->generateVisits(app('tenant'), $days);

        return ['created' => count($created), 'ids' => collect($created)->pluck('id')];
    }

    /** Photos avant / après (en ligne). */
    public function photo(Request $r, string $id)
    {
        $i = Intervention::findOrFail($id);
        $user = $r->user();
        abort_unless($user->hasPermission('interventions.manage') || $i->technician_id === $user->id, 403);
        $r->validate(['photo' => 'required|image|max:8192', 'caption' => 'nullable|string|max:120']);
        $photos = $i->photos ?? [];
        if (count($photos) >= 12) {
            return response()->json(['message' => '12 photos au maximum par intervention.'], 422);
        }
        $dir = public_path('uploads/interventions/'.app('tenant')->id);
        File::ensureDirectoryExists($dir);
        $name = Str::random(32).'.'.$r->file('photo')->extension();
        $r->file('photo')->move($dir, $name);
        $photos[] = ['url' => url('uploads/interventions/'.app('tenant')->id.'/'.$name), 'caption' => $r->input('caption'), 'at' => now()->toIso8601String()];
        $i->forceFill(['photos' => $photos])->save();

        return ['data' => $i->fresh()];
    }

    public function deletePhoto(Request $r, string $id, int $index)
    {
        $i = Intervention::findOrFail($id);
        abort_unless($r->user()->hasPermission('interventions.manage') || $i->technician_id === $r->user()->id, 403);
        $photos = $i->photos ?? [];
        abort_unless(isset($photos[$index]), 404);
        array_splice($photos, $index, 1);
        $i->forceFill(['photos' => $photos])->save();

        return ['data' => $i->fresh()];
    }

    public function stats(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = Carbon::parse($d['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($d['to'] ?? now())->endOfDay();
        $rows = Intervention::where(fn ($q) => $q->whereBetween('scheduled_at', [$from, $to])->orWhereBetween('finished_at', [$from, $to]))->get();
        $done = $rows->where('status', 'done');
        $users = User::where('company_id', app('tenant')->id)->pluck('name', 'id');
        $contracts = Contract::whereIn('id', $done->pluck('contract_id')->filter())->get()->keyBy('id');
        // Délai de prise en charge des dépannages : de la création au début de l'intervention
        $resp = $done->where('type', 'corrective')->filter(fn ($i) => $i->started_at && $i->started_at->gt($i->created_at))->map(fn ($i) => $i->created_at->diffInMinutes($i->started_at) / 60);
        $slaOk = $done->filter(fn ($i) => $i->contract_id && $i->type === 'corrective' && $i->started_at && $i->started_at->gt($i->created_at) && ($contracts[$i->contract_id]->response_hours ?? null))
            ->map(fn ($i) => $i->created_at->diffInMinutes($i->started_at) / 60 <= $contracts[$i->contract_id]->response_hours);

        return [
            'total' => $rows->where('status', '!=', 'cancelled')->count(),
            'done' => $done->count(),
            'open' => Intervention::whereIn('status', ['requested', 'planned', 'en_route', 'in_progress'])->count(),
            'hours' => round($done->sum('worked_minutes') / 60, 1),
            'avg_minutes' => $done->count() ? (int) round($done->avg('worked_minutes')) : null,
            'response_hours' => $resp->count() ? round($resp->avg(), 1) : null,
            'sla_rate' => $slaOk->count() ? round(100 * $slaOk->filter()->count() / $slaOk->count(), 1) : null,
            'satisfaction' => $done->whereNotNull('satisfaction')->count() ? round($done->whereNotNull('satisfaction')->avg('satisfaction'), 1) : null,
            'parts' => round($done->sum(fn ($i) => $i->partsTotal()), 2),
            'to_invoice' => Intervention::where('status', 'done')->whereNull('invoice_id')->where('billable', true)->count(),
            'by_type' => $rows->where('status', '!=', 'cancelled')->groupBy('type')->map->count(),
            'technicians' => $done->groupBy('technician_id')->map(fn ($g, $tid) => [
                'name' => $users[$tid] ?? 'Non attribué', 'done' => $g->count(), 'hours' => round($g->sum('worked_minutes') / 60, 1),
                'satisfaction' => $g->whereNotNull('satisfaction')->count() ? round($g->whereNotNull('satisfaction')->avg('satisfaction'), 1) : null,
            ])->sortByDesc('done')->values(),
        ];
    }
}
