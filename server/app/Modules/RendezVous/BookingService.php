<?php

namespace App\Modules\RendezVous;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\RendezVous\Models\Appointment;
use App\Modules\RendezVous\Models\Service;
use App\Modules\RendezVous\Models\Staff;
use App\Modules\Ventes\Models\SalesDocument;
use App\Services\EntityWriter;
use Carbon\Carbon;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(protected SlotService $slots, protected MessagingService $messaging, protected EntityWriter $writer) {}

    /** Réservation en ligne (page publique ou portail). Vérifie que le créneau est toujours libre. */
    public function book(Company $company, Service $service, string $startsAt, array $customer, ?string $staffId, string $source, ?Partner $partner = null, ?Appointment $reschedule = null): Appointment
    {
        $cfg = $this->slots->settings($company);
        $start = Carbon::parse($startsAt)->utc();
        $local = $start->copy()->timezone($cfg['tz']);
        $slot = collect($this->slots->slots($company, $service, $local->toDateString(), $staffId, true, $reschedule?->id))->firstWhere('time', $local->format('H:i'));
        if (! $slot) {
            throw new \DomainException('Ce créneau vient d\'être pris ou n\'est plus disponible. Choisissez-en un autre.');
        }
        // Le membre le moins chargé ce jour-là parmi ceux qui sont libres
        $staff = $staffId && in_array($staffId, $slot['staff_ids'], true) ? $staffId : collect($slot['staff_ids'])->sortBy(fn ($id) => Appointment::where('staff_id', $id)->whereIn('status', Appointment::BUSY)
            ->whereBetween('starts_at', [$local->copy()->startOfDay()->utc()->toDateTimeString(), $local->copy()->endOfDay()->utc()->toDateTimeString()])->count())->first();
        if ($reschedule) {
            $reschedule->fill(['starts_at' => $start->toDateTimeString(), 'ends_at' => $start->copy()->addMinutes($service->duration_minutes)->toDateTimeString(), 'staff_id' => $staff, 'service_id' => $service->id]);
            $reschedule->reminded_at = null;
            $reschedule->save();
            $this->notifyStaff($company, $reschedule, 'Rendez-vous déplacé par le client');

            return $reschedule;
        }
        $partner ??= $this->findOrCreatePartner($company, $customer);
        $a = new Appointment([
            'service_id' => $service->id, 'staff_id' => $staff, 'partner_id' => $partner?->id, 'customer_name' => $customer['name'], 'customer_phone' => $customer['phone'] ?? null,
            'customer_email' => $customer['email'] ?? null, 'starts_at' => $start->toDateTimeString(), 'ends_at' => $start->copy()->addMinutes($service->duration_minutes)->toDateTimeString(),
            'source' => $source, 'notes' => $customer['notes'] ?? null, 'price' => $service->price,
        ]);
        $a->status = $service->requires_approval || ! $cfg['auto_confirm'] ? 'pending' : 'confirmed';
        $a->save();
        $this->notifyStaff($company, $a, $a->status === 'pending' ? 'Nouvelle demande de rendez-vous' : 'Nouveau rendez-vous en ligne');

        return $a;
    }

    protected function findOrCreatePartner(Company $company, array $c): ?Partner
    {
        $digits = preg_replace('/\D/', '', (string) ($c['phone'] ?? ''));
        $p = null;
        if (! empty($c['email'])) {
            $p = Partner::whereRaw('lower(email) = ?', [mb_strtolower($c['email'])])->first();
        }
        if (! $p && strlen($digits) >= 8) {
            $p = Partner::where('phone', 'like', '%'.implode('%', str_split(substr($digits, -6))))->get()->first(fn ($x) => substr(preg_replace('/\D/', '', (string) $x->phone), -8) === substr($digits, -8));
        }
        if (! $p && ($company->settings['rdv_create_contacts'] ?? true)) {
            $p = Partner::create(['name' => $c['name'], 'type' => 'customer', 'phone' => $c['phone'] ?? null, 'email' => $c['email'] ?? null, 'notes' => 'Créé par la réservation en ligne']);
        }

        return $p;
    }

    public function notifyStaff(Company $company, Appointment $a, string $title): void
    {
        $tz = $this->slots->settings($company)['tz'];
        $staff = $a->staff_id ? Staff::find($a->staff_id) : null;
        $users = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('rendez_vous.manage'))->pluck('id');
        if ($staff?->user_id) {
            $users->push($staff->user_id);
        }
        $service = $a->service_id ? Service::find($a->service_id) : null;
        AppNotification::send($company->id, $users->unique(), 'appointment', $title.' : '.$a->customer_name,
            trim(($service?->name ?? '').' · '.$a->starts_at->copy()->timezone($tz)->locale('fr')->isoFormat('ddd D MMM [à] HH:mm').($staff ? ' avec '.$staff->name : '')), 'rendez_vous', $a->id, auth()->id());
    }

    /** Messages au client : confirmation, rappel, annulation. */
    public function message(Company $company, Appointment $a, string $kind): bool
    {
        if (! $a->notify) {
            return false;
        }
        $tz = $this->slots->settings($company)['tz'];
        $service = $a->service_id ? Service::find($a->service_id) : null;
        $staff = $a->staff_id ? Staff::find($a->staff_id) : null;
        $when = $a->starts_at->copy()->timezone($tz)->locale('fr')->isoFormat('dddd D MMMM [à] HH:mm');
        $what = trim(($service?->name ?? 'votre rendez-vous').($staff ? ' avec '.$staff->name : ''));
        $name = $a->customer_name ?: 'Madame, Monsieur';
        $text = match ($kind) {
            'pending' => "Bonjour $name, votre demande de rendez-vous ($what) le $when est bien reçue. Nous vous confirmons très vite.",
            'confirmed' => "Bonjour $name, votre rendez-vous est confirmé : $what, le $when.",
            'reminder' => "Rappel : $name, vous avez rendez-vous demain ou bientôt — $what, le $when.",
            'cancelled' => "Bonjour $name, votre rendez-vous ($what) du $when est annulé.".($a->cancel_reason ? ' Motif : '.$a->cancel_reason.'.' : ''),
            default => '',
        };
        if ($kind !== 'cancelled') {
            $text .= "\nGérer ou annuler : ".$a->manageUrl();
        }
        if ($company->address || $company->city) {
            $text .= "\nAdresse : ".trim($company->address.' '.$company->city);
        }
        $text .= "\n— {$company->name}".($company->phone ? ' · '.$company->phone : '');
        $available = $this->messaging->available($company);
        try {
            if ($a->customer_phone && ($available['whatsapp'] ?? null)) {
                $this->messaging->send($company, 'whatsapp', new Message(to: $a->customer_phone, toName: $name, text: $text), 'appointment:'.$a->id);
            } elseif ($a->customer_phone && ($available['sms'] ?? null)) {
                $this->messaging->send($company, 'sms', new Message(to: $a->customer_phone, toName: $name, text: $text), 'appointment:'.$a->id);
            } elseif ($a->customer_email && ($available['email'] ?? null)) {
                $subject = ['pending' => 'Demande de rendez-vous reçue', 'confirmed' => 'Rendez-vous confirmé', 'reminder' => 'Rappel de rendez-vous', 'cancelled' => 'Rendez-vous annulé'][$kind].' — '.$company->name;
                $this->messaging->send($company, 'email', new Message(to: $a->customer_email, toName: $name, subject: $subject, html: nl2br(e($text)), text: $text, replyTo: $company->email), 'appointment:'.$a->id);
            } else {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function afterSave(Appointment $a): void
    {
        $company = Company::find($a->company_id);
        if (! $company) {
            return;
        }
        if ($a->wasRecentlyCreated) {
            $this->message($company, $a, $a->status === 'pending' ? 'pending' : 'confirmed');

            return;
        }
        if ($a->wasChanged('status')) {
            if ($a->status === 'confirmed' && $a->getPrevious()['status'] === 'pending') {
                $this->message($company, $a, 'confirmed');
            } elseif ($a->status === 'cancelled') {
                $this->message($company, $a, 'cancelled');
            }
        } elseif ($a->wasChanged('starts_at') && in_array($a->status, ['pending', 'confirmed'], true)) {
            $this->message($company, $a, 'confirmed');
        }
    }

    /** Rappels : rendez-vous qui commencent dans le délai réglé. */
    public function reminders(Company $company): int
    {
        $cfg = $this->slots->settings($company);
        if ($cfg['reminder'] <= 0) {
            return 0;
        }
        $n = 0;
        $horizon = now()->addMinutes((int) round($cfg['reminder'] * 60));
        foreach (Appointment::whereIn('status', ['pending', 'confirmed'])->whereNull('reminded_at')->where('starts_at', '>', now()->toDateTimeString())->where('starts_at', '<=', $horizon->toDateTimeString())->get() as $a) {
            // Pris peu avant : la confirmation suffit
            $late = $a->created_at->diffInMinutes($a->starts_at) < $cfg['reminder'] * 60;
            if (! $late && $this->message($company, $a, 'reminder')) {
                $n++;
            }
            $a->forceFill(['reminded_at' => now()])->saveQuietly();
        }

        return $n;
    }

    public function invoice(Company $company, Appointment $a): SalesDocument
    {
        if (! $company->hasModule('ventes')) {
            throw new \DomainException('Le module Ventes & Facturation est nécessaire pour facturer.');
        }
        if ($a->invoice_id) {
            throw new \DomainException('Ce rendez-vous est déjà facturé.');
        }
        if ($a->status === 'cancelled') {
            throw new \DomainException('Ce rendez-vous est annulé.');
        }
        $partner = $a->partner_id ? Partner::find($a->partner_id) : $this->findOrCreatePartner($company, ['name' => $a->customer_name ?: 'Client', 'phone' => $a->customer_phone, 'email' => $a->customer_email]);
        $service = $a->service_id ? Service::find($a->service_id) : null;
        $tz = $this->slots->settings($company)['tz'];
        // Le prix affiché au client est TTC
        $rate = $service?->product_id ? (float) (\App\Modules\Core\Models\Product::find($service->product_id)?->tax_rate ?? 0) : (float) ($company->settings['tax_rate'] ?? 18);
        SalesDocument::$systemWrite = true;
        try {
            $res = $this->writer->upsert(SalesDocument::class, (string) Str::uuid(), [
                'type' => 'invoice', 'partner_id' => $partner?->id, 'date' => now()->toDateString(), 'reference' => $a->number,
                'lines' => [['id' => (string) Str::uuid(), 'product_id' => $service?->product_id, 'description' => ($service?->name ?? 'Prestation').' — '.$a->starts_at->copy()->timezone($tz)->format('d/m/Y H:i'),
                    'quantity' => 1, 'unit_price' => round($a->price / (1 + $rate / 100), 2), 'tax_rate' => $rate, 'discount_percent' => 0]],
            ]);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException($res['message'] ?? 'Facture refusée.');
        }
        $a->forceFill(['invoice_id' => $res['record']->id, 'partner_id' => $partner?->id])->save();

        return $res['record'];
    }
}
