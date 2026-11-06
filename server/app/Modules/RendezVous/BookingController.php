<?php

namespace App\Modules\RendezVous;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Core\Models\Partner;
use App\Modules\Portail\Models\PortalAccess;
use App\Modules\RendezVous\Models\Appointment;
use App\Modules\RendezVous\Models\Service;
use App\Modules\RendezVous\Models\Staff;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Page publique de réservation et gestion du rendez-vous par le client. */
class BookingController extends Controller
{
    public function __construct(protected SlotService $slots, protected BookingService $booking) {}

    protected function company(string $slug): Company
    {
        $c = Company::where('slug', $slug)->first();
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('rendez_vous') && $this->slots->settings($c)['enabled'], 404);
        app()->instance('tenant', $c);

        return $c;
    }

    protected function view(string $name, array $data)
    {
        return response()->view('rdv.'.$name, $data)->header('X-Robots-Tag', 'noindex');
    }

    /** Client du portail (réservation depuis son espace) : rattachement et pré-remplissage. */
    protected function portalPartner(Request $r, Company $c): ?Partner
    {
        $t = (string) $r->query('portal', $r->input('portal', ''));
        if (strlen($t) < 32 || ! $c->hasModule('portail')) {
            return null;
        }
        $a = PortalAccess::where('token', $t)->where('active', true)->first();

        return $a ? Partner::find($a->partner_id) : null;
    }

    protected function reschedule(Request $r): ?Appointment
    {
        $t = (string) $r->query('reschedule', $r->input('reschedule', ''));

        return strlen($t) >= 32 ? Appointment::where('token', $t)->whereIn('status', ['pending', 'confirmed'])->first() : null;
    }

    public function show(Request $r, string $slug)
    {
        $c = $this->company($slug);
        $cfg = $this->slots->settings($c);
        $services = Service::where('active', true)->where('online', true)->orderBy('sort')->orderBy('name')->get();
        $service = $r->query('service') ? $services->firstWhere('id', $r->query('service')) : null;
        $reschedule = $this->reschedule($r);
        if (! $service && $reschedule) {
            $service = $services->firstWhere('id', $reschedule->service_id);
        }
        $data = ['company' => $c, 'services' => $services, 'service' => $service, 'cfg' => $cfg, 'reschedule' => $reschedule, 'portal' => $r->query('portal'),
            'partner' => $this->portalPartner($r, $c)];
        if ($service) {
            $staffList = $this->slots->staffFor($service, true);
            $staffId = $r->query('staff') && $staffList->contains('id', $r->query('staff')) ? $r->query('staff') : null;
            try {
                $from = $r->query('from') ? Carbon::parse((string) $r->query('from'), $cfg['tz']) : Carbon::now($cfg['tz']);
            } catch (\Throwable) {
                $from = Carbon::now($cfg['tz']);
            }
            if ($from->lt(Carbon::now($cfg['tz'])->startOfDay())) {
                $from = Carbon::now($cfg['tz']);
            }
            $days = $this->slots->days($c, $service, 7, $staffId, $from->toDateString());
            $date = $r->query('date') ?: (collect($days)->firstWhere('count', '>', 0)['date'] ?? $days[0]['date']);
            $data += ['staffList' => $staffList, 'staffId' => $staffId, 'days' => $days, 'date' => $date, 'from' => $from,
                'slots' => $this->slots->slots($c, $service, $date, $staffId, true, $reschedule?->id)];
        }

        return $this->view('book', $data);
    }

    public function confirm(Request $r, string $slug)
    {
        $c = $this->company($slug);
        $service = Service::where('active', true)->where('online', true)->findOrFail(is_string($r->query('service')) ? $r->query('service') : '');
        try {
            $start = Carbon::parse((string) $r->query('start'));
        } catch (\Throwable) {
            abort(404);
        }
        $staff = is_string($r->query('staff')) && $r->query('staff') !== '' ? Staff::find($r->query('staff')) : null;

        return $this->view('confirm', ['company' => $c, 'service' => $service, 'start' => $start, 'staff' => $staff, 'cfg' => $this->slots->settings($c),
            'reschedule' => $this->reschedule($r), 'portal' => $r->query('portal'), 'partner' => $this->portalPartner($r, $c), 'error' => session('error')]);
    }

    public function store(Request $r, string $slug)
    {
        $c = $this->company($slug);
        $reschedule = $this->reschedule($r);
        $d = $r->validate([
            'service_id' => 'required|uuid', 'staff_id' => 'nullable|uuid', 'starts_at' => 'required|date',
            'name' => $reschedule ? 'nullable' : 'required|string|max:120', 'phone' => $reschedule ? 'nullable' : 'required|string|max:40',
            'email' => 'nullable|email|max:255', 'notes' => 'nullable|string|max:1000', 'website' => 'max:0', // piège à robots
        ], ['name.required' => 'Indiquez votre nom.', 'phone.required' => 'Indiquez votre téléphone.']);
        $service = Service::where('active', true)->where('online', true)->findOrFail($d['service_id']);
        $partner = $this->portalPartner($r, $c);
        try {
            $a = $this->booking->book($c, $service, $d['starts_at'], ['name' => $d['name'] ?? null, 'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null, 'notes' => $d['notes'] ?? null],
                $d['staff_id'] ?? null, $partner ? 'portal' : 'online', $partner, $reschedule);
        } catch (\DomainException $e) {
            return redirect('/rdv/'.$slug.'?service='.$service->id.($reschedule ? '&reschedule='.$reschedule->token : '').($r->input('portal') ? '&portal='.$r->input('portal') : ''))->with('error', $e->getMessage());
        }

        return redirect('/rdv/a/'.$a->token)->with('ok', $reschedule ? 'Votre rendez-vous a été déplacé.' : ($a->status === 'pending' ? 'Demande envoyée : nous vous confirmons très vite.' : 'Votre rendez-vous est confirmé.'));
    }

    protected function appointment(string $token): array
    {
        abort_unless(strlen($token) >= 32, 404);
        $a = Appointment::withoutGlobalScopes()->where('token', $token)->whereNull('deleted_at')->first();
        abort_unless($a, 404);
        $c = Company::find($a->company_id);
        abort_unless($c && $c->hasModule('rendez_vous'), 404);
        app()->instance('tenant', $c);

        return [$c, $a];
    }

    public function manage(string $token)
    {
        [$c, $a] = $this->appointment($token);
        $cfg = $this->slots->settings($c);
        $canCancel = in_array($a->status, ['pending', 'confirmed'], true) && $a->starts_at->gt(now()->addMinutes((int) round($cfg['cancel_notice'] * 60)));

        return $this->view('manage', ['company' => $c, 'a' => $a, 'cfg' => $cfg, 'service' => $a->service_id ? Service::find($a->service_id) : null,
            'staff' => $a->staff_id ? Staff::find($a->staff_id) : null, 'canCancel' => $canCancel, 'ok' => session('ok'), 'error' => session('error')]);
    }

    public function cancel(Request $r, string $token)
    {
        [$c, $a] = $this->appointment($token);
        $cfg = $this->slots->settings($c);
        if (! in_array($a->status, ['pending', 'confirmed'], true) || $a->starts_at->lte(now()->addMinutes((int) round($cfg['cancel_notice'] * 60)))) {
            return back()->with('error', 'Il est trop tard pour annuler en ligne : appelez-nous au '.($c->phone ?: 'numéro habituel').'.');
        }
        $a->status = 'cancelled';
        $a->cancel_reason = trim('Annulé par le client. '.(string) $r->input('reason'));
        $a->save();
        $this->booking->notifyStaff($c, $a, 'Rendez-vous annulé par le client');

        return redirect('/rdv/a/'.$token)->with('ok', 'Votre rendez-vous est annulé.');
    }

    public function ics(string $token)
    {
        [$c, $a] = $this->appointment($token);
        $service = $a->service_id ? Service::find($a->service_id) : null;
        $esc = fn ($s) => str_replace([',', ';', "\n"], ['\\,', '\;', '\\n'], (string) $s);
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Yomara ERP//Rendez-vous//FR', 'BEGIN:VEVENT',
            'UID:'.$a->id.'@'.parse_url(url('/'), PHP_URL_HOST), 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$a->starts_at->copy()->utc()->format('Ymd\THis\Z'), 'DTEND:'.$a->ends_at->copy()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$esc(($service?->name ?? 'Rendez-vous').' — '.$c->name), 'LOCATION:'.$esc(trim($c->address.' '.$c->city)),
            'DESCRIPTION:'.$esc('Gérer : '.$a->manageUrl()), 'END:VEVENT', 'END:VCALENDAR', '',
        ]);

        return response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="rendez-vous.ics"']);
    }
}
