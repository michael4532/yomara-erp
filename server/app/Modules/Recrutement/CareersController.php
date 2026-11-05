<?php

namespace App\Modules\Recrutement;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Recrutement\Models\Job;
use Illuminate\Http\Request;

/** Page publique « Emplois » : offres ouvertes et formulaire de candidature avec CV. */
class CareersController extends Controller
{
    public function __construct(protected RecrutementService $svc) {}

    protected function company(string $slug): Company
    {
        $c = Company::where('slug', $slug)->first();
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('recrutement') && $this->svc->settings($c)['careers_enabled'], 404);
        app()->instance('tenant', $c);

        return $c;
    }

    public function index(string $slug)
    {
        $c = $this->company($slug);
        $jobs = Job::where('status', 'open')->where('published', true)->orderByDesc('created_at')->get()->filter->isPublic()->values();

        return response()->view('emplois.index', ['company' => $c, 'jobs' => $jobs, 'intro' => $this->svc->settings($c)['careers_intro']]);
    }

    public function show(string $slug, string $id)
    {
        $c = $this->company($slug);
        $job = $id === 'spontanee' ? null : Job::find($id);
        abort_if($id !== 'spontanee' && (! $job || ! $job->isPublic()), 404);

        return response()->view('emplois.show', ['company' => $c, 'job' => $job]);
    }

    public function apply(Request $r, string $slug, string $id)
    {
        $c = $this->company($slug);
        $job = $id === 'spontanee' ? null : Job::find($id);
        abort_if($id !== 'spontanee' && (! $job || ! $job->isPublic()), 404);
        $v = $r->validate([
            'first_name' => 'required|string|max:120', 'last_name' => 'required|string|max:120',
            'email' => 'required_without:phone|nullable|email|max:255', 'phone' => 'required_without:email|nullable|string|max:40',
            'city' => 'nullable|string|max:120', 'cover_letter' => 'nullable|string|max:5000', 'expected_salary' => 'nullable|string|max:80',
            'available_from' => 'nullable|date', 'cv' => 'required|file|max:6144', 'consent' => 'accepted',
            'website' => 'max:0', // piège à robots
            't' => 'required|integer',
        ], [
            'cv.required' => 'Joignez votre CV.', 'cv.max' => 'CV trop lourd (6 Mo maximum).', 'consent.accepted' => 'Merci d\'accepter le traitement de vos données.',
            'email.required_without' => 'Indiquez un e-mail ou un téléphone.', 'phone.required_without' => 'Indiquez un e-mail ou un téléphone.',
        ]);
        // Formulaire rempli en moins de 4 secondes : robot
        if (time() - (int) $v['t'] < 4) {
            return back()->withInput()->with('error', 'Merci de vérifier le formulaire et de réessayer.');
        }
        try {
            $this->svc->apply($c, $job, $v, $r->file('cv'));
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect('/emplois/'.$slug)->with('ok', 'Merci '.$v['first_name'].' ! Votre candidature est bien envoyée. Nous vous recontacterons.');
    }
}
