<?php

namespace App\Modules\Prestations;

use App\Http\Controllers\Controller;
use App\Modules\Prestations\Models\Deliverable;
use App\Modules\Prestations\Models\Pack;
use App\Modules\Prestations\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PrestationsController extends Controller
{
    public function __construct(private PrestationService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function stats()
    {
        return ['data' => $this->svc->stats()];
    }

    public function invoicePack(string $id)
    {
        return $this->guard(fn () => ['data' => $this->svc->invoicePack(Pack::findOrFail($id))->fresh('lines')->toSyncArray(), 'pack' => Pack::find($id)->toSyncArray()]);
    }

    public function invoiceOverage(string $id)
    {
        return $this->guard(fn () => ['data' => $this->svc->invoiceOverage(Pack::findOrFail($id))->fresh('lines')->toSyncArray(), 'pack' => Pack::find($id)->toSyncArray()]);
    }

    public function renew(Request $r, string $id)
    {
        return $this->guard(fn () => response()->json(['data' => $this->svc->renew(Pack::findOrFail($id), $r->boolean('invoice', true))->toSyncArray(), 'old' => Pack::find($id)->toSyncArray()], 201));
    }

    public function upload(Request $r, string $id)
    {
        $r->validate(['file' => 'required|file|max:51200']);

        return $this->guard(fn () => ['data' => $this->svc->attach(Deliverable::findOrFail($id), $r->file('file'))->toSyncArray()]);
    }

    public function download(string $id)
    {
        $d = Deliverable::findOrFail($id);
        abort_unless($d->file_path && Storage::disk('local')->exists($d->file_path), 404);

        return Storage::disk('local')->download($d->file_path, $d->file_name);
    }

    public function resend(string $id)
    {
        $d = Deliverable::findOrFail($id);
        if ($d->status !== 'sent') {
            return response()->json(['message' => 'Ce livrable n\'attend pas de décision.'], 422);
        }

        return $this->svc->sendDeliverable(app('tenant'), $d) ? ['data' => ['sent' => true]]
            : response()->json(['message' => 'Aucun canal d\'envoi disponible (e-mail ou WhatsApp du client et canaux configurés). Lien à transmettre : '.$d->publicUrl()], 422);
    }

    public function settings(ReportService $rs)
    {
        return ['data' => $rs->settings(app('tenant'))];
    }

    public function saveSettings(Request $r, ReportService $rs)
    {
        $v = $r->validate(['monthly_reports' => 'sometimes|boolean', 'include_projects' => 'sometimes|boolean']);

        return ['data' => $rs->saveSettings(app('tenant'), $v)];
    }

    public function generateReport(Request $r, ReportService $rs)
    {
        $v = $r->validate(['partner_id' => 'required|uuid', 'period' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'send' => 'sometimes|boolean']);

        return $this->guard(function () use ($rs, $v, $r) {
            $rep = $rs->generate(app('tenant'), $v['partner_id'], $v['period'], $r->user());
            $sent = ! empty($v['send']) ? $rs->send(app('tenant'), $rep) : null;

            return response()->json(['data' => $rep->fresh()->toSyncArray(), 'sent' => $sent], 201);
        });
    }

    public function sendReport(string $id, ReportService $rs)
    {
        $rep = Report::findOrFail($id);
        if (! $rs->send(app('tenant'), $rep)) {
            return response()->json(['message' => 'Aucun canal disponible : le client n\'a ni e-mail ni WhatsApp, ou aucun canal n\'est configuré. Partagez le lien du rapport.'], 422);
        }

        return ['data' => $rep->fresh()->toSyncArray()];
    }

    public function reportPdf(string $id)
    {
        $rep = Report::findOrFail($id);
        abort_unless($rep->file_path && Storage::disk('local')->exists($rep->file_path), 404);

        return response(Storage::disk('local')->get($rep->file_path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="rapport-'.$rep->period.'.pdf"']);
    }
}
