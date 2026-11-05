<?php

namespace App\Modules\Recrutement;

use App\Http\Controllers\Controller;
use App\Modules\Recrutement\Models\Application;
use App\Modules\Recrutement\Models\Interview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RecrutementController extends Controller
{
    public function __construct(protected RecrutementService $svc) {}

    protected function visible(Request $r, string $id): Application
    {
        $a = Application::findOrFail($id);
        $u = $r->user();
        if (! $u->hasPermission('recrutement.view|recrutement.manage')
            && ! Interview::where('application_id', $a->id)->get()->contains(fn ($i) => in_array($u->id, (array) $i->interviewer_ids, true))) {
            abort(404);
        }

        return $a;
    }

    public function cv(Request $r, string $id)
    {
        $a = $this->visible($r, $id);
        abort_unless($a->cv_path && Storage::disk('local')->exists($a->cv_path), 404, 'Pas de CV.');

        return response()->file(Storage::disk('local')->path($a->cv_path), ['Content-Disposition' => 'inline; filename="'.($a->cv_name ?: basename($a->cv_path)).'"']);
    }

    public function uploadCv(Request $r, string $id)
    {
        $a = Application::findOrFail($id);
        $r->validate(['file' => 'required|file|max:6144']);
        try {
            $this->svc->storeCv($a, $r->file('file'));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => $a->fresh()];
    }

    public function message(Request $r, string $id)
    {
        $a = Application::findOrFail($id);
        $v = $r->validate(['channel' => 'nullable|in:auto,email,whatsapp,sms', 'subject' => 'nullable|string|max:200', 'text' => 'required|string|max:5000']);
        try {
            $ch = $this->svc->message(app('tenant'), $a, $v['subject'] ?? ('Votre candidature — '.app('tenant')->name), $v['text'], $v['channel'] ?? 'auto');
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => ['channel' => $ch]];
    }

    public function hire(Request $r, string $id)
    {
        $a = Application::findOrFail($id);
        $v = $r->validate(['hire_date' => 'nullable|date', 'base_salary' => 'nullable|numeric|min:0', 'contract_type' => 'nullable|in:CDI,CDD,stage,journalier,consultant',
            'job_title' => 'nullable|string|max:255', 'department' => 'nullable|string|max:255']);
        try {
            $e = $this->svc->hire(app('tenant'), $a, $v);
        } catch (\DomainException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return ['data' => ['employee_id' => $e->id, 'application' => $a->fresh()]];
    }

    public function stats()
    {
        return ['data' => $this->svc->stats()];
    }

    public function settings()
    {
        $c = app('tenant');

        return ['data' => $this->svc->settings($c) + ['url' => url('/emplois/'.$c->slug)]];
    }

    public function updateSettings(Request $r)
    {
        $v = $r->validate(['careers_enabled' => 'sometimes|boolean', 'careers_intro' => 'sometimes|nullable|string|max:3000', 'retention_months' => 'sometimes|integer|min:0|max:120']);
        $c = app('tenant');
        $c->forceFill(['settings' => array_merge($c->settings ?? [], ['recrutement' => array_merge($this->svc->settings($c), $v)])])->save();

        return $this->settings();
    }

    /** Intervenants possibles pour un entretien (comptes actifs de l'entreprise). */
    public function users()
    {
        return ['data' => \App\Models\User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'is_active'])];
    }
}
