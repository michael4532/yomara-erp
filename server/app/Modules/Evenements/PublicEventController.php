<?php

namespace App\Modules\Evenements;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Evenements\Models\Booking;
use App\Modules\Evenements\Models\Event;
use App\Modules\Evenements\Models\Package;
use App\Modules\Evenements\Models\Space;
use Illuminate\Http\Request;

/** Page publique d'un événement : plan des stands libres et demande de réservation par l'exposant. */
class PublicEventController extends Controller
{
    public function __construct(private EventService $svc) {}

    protected function load(string $token): array
    {
        abort_unless(strlen($token) >= 32, 404);
        $e = Event::withoutGlobalScopes()->where('public_token', $token)->whereNull('deleted_at')->first();
        abort_unless($e && $e->public_enabled && in_array($e->status, ['open', 'closed'], true), 404);
        $c = Company::find($e->company_id);
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('evenements'), 404);
        app()->instance('tenant', $c);

        return [$c, Event::find($e->id)];
    }

    public function show(string $token)
    {
        [$c, $e] = $this->load($token);
        $spaces = Space::where('event_id', $e->id)->orderBy('code')->get();
        $taken = [];
        foreach (Booking::where('event_id', $e->id)->whereIn('status', Booking::ACTIVE)->get(['space_ids', 'status']) as $b) {
            foreach ($b->space_ids ?? [] as $sid) {
                $taken[$sid] = true;
            }
        }
        $packages = Package::where('event_id', $e->id)->where('active', true)->where('online', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn ($p) => ['p' => $p, 'left' => $p->quantity === null ? null : max(0, $p->quantity - $p->sold())]);

        return response()->view('evenements.show', [
            'company' => $c, 'event' => $e, 'spaces' => $spaces, 'taken' => $taken, 'packages' => $packages,
            'cur' => in_array($e->currency ?: $c->currency, ['XOF', 'XAF'], true) ? 'F CFA' : ($e->currency ?: $c->currency),
            'ok' => session('ok'), 'error' => session('error'),
        ])->header('X-Robots-Tag', 'noindex');
    }

    /** Suivi de la demande par l'exposant : état, acompte à payer en ligne. */
    public function booking(string $token, string $btoken)
    {
        [$c, $e] = $this->load($token);
        abort_unless(strlen($btoken) >= 32, 404);
        $b = Booking::where('event_id', $e->id)->where('public_token', $btoken)->first();
        abort_unless($b, 404);
        $a = $b->amounts($e);

        return response()->view('evenements.booking', ['company' => $c, 'event' => $e, 'b' => $b, 'a' => $a, 'codes' => $this->svc->spaceCodes($b),
            'payUrl' => $this->svc->payUrl($b), 'invoices' => $b->invoices(),
            'cur' => in_array($e->currency ?: $c->currency, ['XOF', 'XAF'], true) ? 'F CFA' : ($e->currency ?: $c->currency)])->header('X-Robots-Tag', 'noindex');
    }

    /** Espace de l'exposant / sponsor : il présente son entreprise (logo, texte, liens) pour l'annuaire de l'événement. */
    public function space(string $token, string $btoken)
    {
        [$c, $e] = $this->load($token);
        $b = $this->bookingFor($e, $btoken);

        return response()->view('evenements.space', ['company' => $c, 'event' => $e, 'b' => $b, 'p' => (array) $b->profile, 'logo' => $this->svc->logoUrl($b),
            'codes' => $this->svc->spaceCodes($b), 'saved' => session('saved'), 'error' => session('error'), 'directory' => url('/salon/'.$token.'/exposants')])->header('X-Robots-Tag', 'noindex');
    }

    public function saveSpace(Request $r, string $token, string $btoken)
    {
        [, $e] = $this->load($token);
        $b = $this->bookingFor($e, $btoken);
        $r->validate(['logo' => 'nullable|file|max:2048', 'website' => 'max:300'] + array_fill_keys(EventService::PROFILE_FIELDS, 'nullable|string|max:4000'));
        try {
            $this->svc->saveProfile($b, $r->only(EventService::PROFILE_FIELDS), $r->file('logo'), $b->status === 'confirmed' ? $r->boolean('published') : false);
        } catch (\DomainException $ex) {
            return back()->withInput()->with('error', $ex->getMessage());
        }

        return redirect('/salon/'.$token.'/r/'.$btoken.'/espace')->with('saved', true);
    }

    /** Annuaire public des sponsors et exposants. */
    public function directory(string $token)
    {
        [$c, $e] = $this->load($token);

        return response()->view('evenements.directory', ['company' => $c, 'event' => $e, 'dir' => $this->svc->directory($e), 'token' => $token,
            'ticketing' => $e->ticketing_enabled ? url('/billetterie/'.$e->public_token) : null]);
    }

    protected function bookingFor(Event $e, string $btoken): Booking
    {
        abort_unless(strlen($btoken) >= 32, 404);
        $b = Booking::where('event_id', $e->id)->where('public_token', $btoken)->whereIn('status', Booking::ACTIVE)->first();
        abort_unless($b, 404);

        return $b;
    }

    public function store(Request $r, string $token)
    {
        [$c, $e] = $this->load($token);
        $d = $r->validate([
            'company' => 'required|string|max:255', 'name' => 'required|string|max:120', 'phone' => 'required|string|max:40',
            'email' => 'nullable|email|max:255', 'message' => 'nullable|string|max:1000', 'spaces' => 'nullable|array|max:20', 'spaces.*' => 'uuid',
            'packages' => 'nullable|array|max:50', 'packages.*' => 'nullable|integer|min:0|max:50', 'website' => 'max:0',
        ], ['company.required' => 'Indiquez le nom de votre entreprise.', 'name.required' => 'Indiquez votre nom.', 'phone.required' => 'Indiquez votre téléphone.']);
        if (empty($d['spaces']) && ! array_filter($d['packages'] ?? [])) {
            return back()->withInput()->with('error', 'Choisissez au moins un stand ou une offre.');
        }
        try {
            $b = $this->svc->publicRequest($e, $d);
        } catch (\DomainException $ex) {
            return back()->withInput()->with('error', $ex->getMessage());
        }

        if ($b->deposit_invoice_id || $b->balance_invoice_id) {
            return redirect('/salon/'.$token.'/r/'.$b->public_token);
        }

        return redirect('/salon/'.$token)->with('ok', 'Merci ! Votre demande '.$b->number.' est enregistrée : les emplacements sont réservés pour vous jusqu\'au '
            .$b->option_until->format('d/m/Y').'. Nous vous contactons très vite pour confirmer.');
    }
}
