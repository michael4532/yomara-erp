<?php

namespace App\Modules\Prestations;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Prestations\Models\Deliverable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Le client consulte le livrable et donne son accord ou demande des modifications. */
class PublicDeliverableController extends Controller
{
    public function __construct(private PrestationService $svc) {}

    protected function load(string $token): array
    {
        abort_unless(strlen($token) >= 32, 404);
        $raw = Deliverable::withoutGlobalScopes()->where('token', $token)->whereNull('deleted_at')->first();
        abort_unless($raw && $raw->sent_at, 404);
        $c = Company::find($raw->company_id);
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('prestations'), 404);
        app()->instance('tenant', $c);

        return [$c, Deliverable::find($raw->id)];
    }

    public function show(string $token)
    {
        [$c, $d] = $this->load($token);

        return response()->view('prestations.deliverable', ['company' => $c, 'd' => $d, 'ok' => session('ok'), 'error' => session('error')])
            ->header('X-Robots-Tag', 'noindex')->header('Referrer-Policy', 'no-referrer');
    }

    public function file(string $token)
    {
        [, $d] = $this->load($token);
        abort_unless($d->file_path && Storage::disk('local')->exists($d->file_path), 404);

        return Storage::disk('local')->download($d->file_path, $d->file_name);
    }

    public function decide(Request $r, string $token)
    {
        [, $d] = $this->load($token);
        $v = $r->validate(['decision' => 'required|in:approve,changes', 'name' => 'required|string|max:120', 'comment' => 'nullable|string|max:3000'],
            ['name.required' => 'Indiquez votre nom.']);
        try {
            $this->svc->decide($d, $v['decision'] === 'approve', $v['name'], $v['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect('/livrable/'.$token)->with('ok', $v['decision'] === 'approve' ? 'Merci, votre validation est enregistrée.' : 'Merci, vos demandes de modifications sont transmises à l\'équipe.');
    }

    /** Rapport d'activité mensuel (PDF) par son lien. */
    public function report(string $token)
    {
        abort_unless(strlen($token) >= 32, 404);
        $raw = \App\Modules\Prestations\Models\Report::withoutGlobalScopes()->where('token', $token)->whereNull('deleted_at')->first();
        abort_unless($raw && $raw->file_path && Storage::disk('local')->exists($raw->file_path), 404);
        $c = Company::find($raw->company_id);
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('prestations'), 404);

        return response(Storage::disk('local')->get($raw->file_path), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="rapport-activite-'.$raw->period.'.pdf"', 'X-Robots-Tag' => 'noindex']);
    }
}
