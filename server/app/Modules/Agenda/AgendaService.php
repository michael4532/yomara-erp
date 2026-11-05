<?php

namespace App\Modules\Agenda;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Models\Resource;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AgendaService
{
    public const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    public const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(protected MessagingService $messaging) {}

    public static function dateFr(CarbonInterface $d): string
    {
        return self::DAYS[$d->dayOfWeek].' '.$d->day.($d->day === 1 ? 'er' : '').' '.self::MONTHS[$d->month - 1].' '.$d->year;
    }

    /** « mardi 3 novembre 2026, 10:00 – 11:00 · Salle de réunion » */
    public function when(Event $e): string
    {
        $s = $e->starts_at;
        $txt = self::dateFr($s).($e->all_day ? ($e->ends_at->isSameDay($s) ? '' : ' au '.self::dateFr($e->ends_at)) : ', '.$s->format('H:i').' – '.($e->ends_at->isSameDay($s) ? '' : self::dateFr($e->ends_at).' ').$e->ends_at->format('H:i'));
        if ($e->recurrence) {
            $freq = ['daily' => 'tous les jours', 'weekly' => 'chaque semaine', 'monthly' => 'chaque mois', 'yearly' => 'chaque année'][$e->recurrence['freq'] ?? ''] ?? 'récurrent';
            $txt .= ' ('.$freq.')';
        }
        $place = $e->resource_id ? Resource::find($e->resource_id)?->name : null;

        return $txt.(($place ?: $e->location) ? ' · '.($place ?: $e->location) : '');
    }

    /** Une salle ou un véhicule ne peut pas être réservé deux fois sur le même créneau (récurrences comprises, sur un an). */
    public function assertResourceFree(string $resourceId, ?string $eventId, Carbon $start, Carbon $end, ?array $rule, array $exceptions): void
    {
        $from = $start->copy();
        $to = $rule ? $start->copy()->addYear() : $end->copy();
        $mine = Recurrence::occurrences($start, $end, $rule, $exceptions, $from, $to->copy()->addSecond(), 400);
        if (! $mine) {
            return;
        }
        $windowEnd = end($mine)[1];
        $others = Event::where('resource_id', $resourceId)->where('status', 'confirmed')
            ->when($eventId, fn ($q) => $q->where('id', '!=', $eventId))
            ->where('starts_at', '<', $windowEnd)
            ->where(fn ($q) => $q->whereNotNull('recurrence')->orWhere('ends_at', '>', $from))
            ->get();
        foreach ($others as $o) {
            foreach (Recurrence::occurrences($o->starts_at, $o->ends_at, $o->recurrence, (array) $o->exceptions, $from, $windowEnd, 400) as [$os, $oe]) {
                foreach ($mine as [$ms, $me]) {
                    if ($ms->lt($oe) && $me->gt($os)) {
                        $r = Resource::find($resourceId);
                        throw new \DomainException('« '.($r?->name ?? 'Ressource').' » est déjà réservé(e) le '.$os->format('d/m/Y').' de '.$os->format('H:i').' à '.$oe->format('H:i').' ('.$o->title.').');
                    }
                }
            }
        }
    }

    /** Fichier iCalendar d'événements (invitation ou abonnement). */
    public function ics(Company $company, iterable $events, string $name, ?string $method = null): string
    {
        $tz = $company->setting('timezone') ?: config('app.timezone');
        $esc = fn (?string $s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $s);
        $utc = fn (CarbonInterface $d) => Carbon::parse($d->format('Y-m-d H:i:s'), $tz)->utc()->format('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Yomara ERP//Agenda//FR', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.$esc($name), 'X-WR-TIMEZONE:'.$tz];
        if ($method) {
            $lines[] = 'METHOD:'.$method;
        }
        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$e->id.'@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'yomara');
            $lines[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
            $lines[] = 'SEQUENCE:'.(int) $e->version;
            if ($e->all_day) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$e->starts_at->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$e->ends_at->copy()->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.$utc($e->starts_at);
                $lines[] = 'DTEND:'.$utc($e->ends_at);
            }
            if ($rr = Recurrence::rrule($e->recurrence)) {
                $lines[] = 'RRULE:'.$rr;
                foreach ((array) $e->exceptions as $x) {
                    $lines[] = $e->all_day ? 'EXDATE;VALUE=DATE:'.str_replace('-', '', $x) : 'EXDATE:'.$utc(Carbon::parse($x.' '.$e->starts_at->format('H:i:s')));
                }
            }
            $lines[] = 'SUMMARY:'.$esc($e->title);
            $place = $e->resource_id ? Resource::find($e->resource_id)?->name : null;
            if ($place || $e->location) {
                $lines[] = 'LOCATION:'.$esc(trim(($place ? $place.' — ' : '').$e->location, ' —'));
            }
            $desc = trim($e->description.($e->online_link ? "\n".$e->online_link : ''));
            if ($desc !== '') {
                $lines[] = 'DESCRIPTION:'.$esc($desc);
            }
            if ($e->online_link) {
                $lines[] = 'URL:'.$esc($e->online_link);
            }
            $lines[] = 'STATUS:'.($e->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED');
            if ($e->reminder_minutes !== null) {
                $lines = array_merge($lines, ['BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$esc($e->title), 'TRIGGER:-PT'.(int) $e->reminder_minutes.'M', 'END:VALARM']);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        $out = [];
        foreach ($lines as $l) {
            // Lignes de 75 octets au plus (RFC 5545)
            while (strlen($l) > 75) {
                $cut = 75;
                while ($cut > 1 && (ord($l[$cut]) & 0xC0) === 0x80) {
                    $cut--;
                }
                $out[] = substr($l, 0, $cut);
                $l = ' '.substr($l, $cut);
            }
            $out[] = $l;
        }

        return implode("\r\n", $out)."\r\n";
    }

    /** Invités externes : e-mail avec l'invitation (.ics) ou, à défaut, WhatsApp / SMS. */
    public function inviteGuests(Event $e): int
    {
        $company = Company::find($e->company_id);
        $owner = $e->owner_id ? User::find($e->owner_id) : null;
        $available = $this->messaging->available($company);
        $when = $this->when($e);
        $n = 0;
        foreach ((array) $e->guests as $g) {
            $name = $g['name'] ?? '';
            $text = "Bonjour".($name ? ' '.$name : '').",\n\n".($owner?->name ?? $company->name).' ('.$company->name.") vous invite : « {$e->title} »\n$when".
                ($e->online_link ? "\nLien : ".$e->online_link : '').($e->description ? "\n\n".$e->description : '')."\n\nCordialement,\n".($owner?->name ?? $company->name);
            try {
                if (! empty($g['email']) && ($available['email'] ?? null)) {
                    $r = $this->messaging->send($company, 'email', new Message(to: $g['email'], toName: $name ?: null, subject: 'Invitation : '.$e->title, html: nl2br(e($text)), text: $text,
                        attachments: [['name' => 'invitation.ics', 'data' => $this->ics($company, [$e], $e->title, 'PUBLISH'), 'mime' => 'text/calendar']], replyTo: $owner?->email ?: $company->email), 'agenda:'.$e->id);
                } elseif (! empty($g['phone']) && (($ch = ($available['whatsapp'] ?? null) ? 'whatsapp' : (($available['sms'] ?? null) ? 'sms' : null)))) {
                    $r = $this->messaging->send($company, $ch, new Message(to: $g['phone'], toName: $name ?: null, text: $text), 'agenda:'.$e->id);
                } else {
                    continue;
                }
                $n += $r->ok ? 1 : 0;
            } catch (\Throwable $ex) {
                Log::warning('Agenda : invitation non envoyée', ['event' => $e->id, 'error' => $ex->getMessage()]);
            }
        }

        return $n;
    }

    /** Événements des autres modules (lecture seule) visibles par l'utilisateur sur la période. */
    public function overlay(Company $company, User $user, Carbon $from, Carbon $to): array
    {
        $items = [];
        $add = function (string $kind, string $id, string $title, $start, $end, bool $allDay, string $color, array $extra = []) use (&$items) {
            $items[] = ['id' => $kind.':'.$id, 'kind' => $kind, 'title' => $title, 'starts_at' => Carbon::parse($start)->format('Y-m-d H:i:s'), 'ends_at' => Carbon::parse($end)->format('Y-m-d H:i:s'), 'all_day' => $allDay, 'color' => $color] + $extra;
        };
        $mod = fn (string $m) => $company->hasModule($m);
        $try = function (callable $f) {
            try {
                $f();
            } catch (\Throwable $e) {
                Log::warning('Agenda : flux d\'un module indisponible', ['error' => $e->getMessage()]);
            }
        };
        if ($mod('rh') && $user->hasPermission('rh.view|rh.manage|rh.self')) {
            $try(function () use ($user, $from, $to, $add) {
                $q = \App\Modules\Rh\Models\Leave::where('status', 'approved')->where('start_date', '<=', $to->toDateString())->where('end_date', '>=', $from->toDateString());
                \App\Modules\Rh\Models\Leave::syncVisibility($q, $user);
                $emps = \App\Modules\Rh\Models\Employee::withTrashed()->get()->keyBy('id');
                foreach ($q->get() as $l) {
                    $e = $emps[$l->employee_id] ?? null;
                    $add('leave', $l->id, (\App\Modules\Rh\Models\Leave::TYPES[$l->type] ?? 'Absence').' — '.trim(($e->first_name ?? '').' '.($e->last_name ?? '')), Carbon::parse($l->start_date)->toDateString().' 00:00:00', Carbon::parse($l->end_date)->toDateString().' 23:59:59', true, '#8E44AD', ['user_id' => $e?->user_id]);
                }
            });
        }
        if ($mod('recrutement') && $user->hasPermission('recrutement.view|recrutement.manage|recrutement.interview')) {
            $try(function () use ($user, $from, $to, $add) {
                $q = \App\Modules\Recrutement\Models\Interview::where('status', 'planned')->whereBetween('starts_at', [$from, $to]);
                if (method_exists(\App\Modules\Recrutement\Models\Interview::class, 'syncVisibility')) {
                    \App\Modules\Recrutement\Models\Interview::syncVisibility($q, $user);
                }
                $apps = \App\Modules\Recrutement\Models\Application::withTrashed()->get()->keyBy('id');
                foreach ($q->get() as $i) {
                    $a = $apps[$i->application_id] ?? null;
                    $add('interview', $i->id, 'Entretien — '.trim(($a->first_name ?? '').' '.($a->last_name ?? '')), $i->starts_at, $i->starts_at->copy()->addMinutes($i->duration ?: 45), false, '#0E9AA7', ['location' => $i->location, 'user_ids' => array_map('intval', (array) $i->interviewer_ids)]);
                }
            });
        }
        if ($mod('rendez_vous') && $user->hasPermission('rendez_vous.view|rendez_vous.own|rendez_vous.manage')) {
            $try(function () use ($user, $from, $to, $add) {
                $q = \App\Modules\RendezVous\Models\Appointment::whereNotIn('status', ['cancelled'])->where('starts_at', '<', $to)->where('ends_at', '>', $from);
                $staff = \App\Modules\RendezVous\Models\Staff::withTrashed()->get()->keyBy('id');
                if (! $user->hasPermission('rendez_vous.view|rendez_vous.manage')) {
                    $q->whereIn('staff_id', $staff->where('user_id', $user->id)->keys());
                }
                foreach ($q->get() as $a) {
                    $add('appointment', $a->id, 'RDV — '.($a->customer_name ?: 'client'), $a->starts_at, $a->ends_at, false, '#12A150', ['user_id' => $staff[$a->staff_id]->user_id ?? null, 'staff' => $staff[$a->staff_id]->name ?? null]);
                }
            });
        }
        if ($mod('interventions') && $user->hasPermission('interventions.view|interventions.do|interventions.manage')) {
            $try(function () use ($user, $from, $to, $add) {
                $q = \App\Modules\Interventions\Models\Intervention::whereNotNull('scheduled_at')->whereNotIn('status', ['cancelled'])->whereBetween('scheduled_at', [$from->copy()->subDay(), $to]);
                if (! $user->hasPermission('interventions.view|interventions.manage')) {
                    $q->where('technician_id', $user->id);
                }
                foreach ($q->get() as $i) {
                    $add('intervention', $i->id, 'Intervention — '.$i->title, $i->scheduled_at, $i->scheduled_at->copy()->addMinutes($i->duration_minutes ?: 60), false, '#E67E22', ['location' => trim($i->address.' '.$i->city), 'user_id' => $i->technician_id]);
                }
            });
        }
        if ($mod('projets') && $user->hasPermission('projets.view|projets.contribute|projets.manage')) {
            $try(function () use ($user, $from, $to, $add) {
                $q = \App\Modules\Projets\Models\Task::whereNotNull('due_date')->where('status', '!=', 'done')->whereBetween('due_date', [$from->toDateString(), $to->toDateString()]);
                if (! $user->hasPermission('projets.view|projets.manage')) {
                    $q->where('assignee_id', $user->id);
                }
                foreach ($q->get() as $t) {
                    $add('task', $t->id, 'Échéance tâche — '.$t->title, Carbon::parse($t->due_date)->toDateString().' 00:00:00', Carbon::parse($t->due_date)->toDateString().' 23:59:59', true, '#1F6FEB', ['user_id' => $t->assignee_id]);
                }
            });
        }
        if ($mod('documents') && $user->hasPermission('documents.view|documents.manage|documents.admin')) {
            $try(function () use ($user, $from, $to, $add) {
                $q = \App\Modules\Documents\Models\DocFile::whereNotNull('expires_at')->whereBetween('expires_at', [$from->toDateString(), $to->toDateString()]);
                \App\Modules\Documents\Models\DocFile::syncVisibility($q, $user);
                foreach ($q->get() as $f) {
                    $add('document', $f->id, 'Échéance — '.$f->name, $f->expires_at->toDateString().' 00:00:00', $f->expires_at->toDateString().' 23:59:59', true, '#D93025');
                }
            });
        }

        return $items;
    }

    /** Rappels : notification avant chaque occurrence (organisateur et participants n'ayant pas décliné). */
    public function reminders(Company $company): int
    {
        $now = now();
        $n = 0;
        $events = Event::where('status', 'confirmed')->whereNotNull('reminder_minutes')
            ->where(fn ($q) => $q->whereNotNull('recurrence')->orWhereBetween('starts_at', [$now->copy()->subHour(), $now->copy()->addDays(15)]))->get();
        foreach ($events as $e) {
            foreach (Recurrence::occurrences($e->starts_at, $e->ends_at, $e->recurrence, (array) $e->exceptions, $now->copy()->subMinutes(10), $now->copy()->addMinutes($e->reminder_minutes + 1), 5) as [$s]) {
                if ($s->copy()->subMinutes($e->reminder_minutes)->gt($now) || $s->lt($now->copy()->subMinutes(10))) {
                    continue;
                }
                $inserted = DB::table('ag_reminders')->insertOrIgnore(['event_id' => $e->id, 'occurrence_at' => $s->format('Y-m-d H:i:s'), 'created_at' => now()]);
                if (! $inserted) {
                    continue;
                }
                $resp = (array) $e->responses;
                $users = array_merge([(int) $e->owner_id], array_filter(array_map('intval', (array) $e->attendee_ids), fn ($id) => ($resp[(string) $id] ?? null) !== 'declined'));
                $label = $e->all_day ? 'aujourd\'hui' : ($s->isToday() ? 'à '.$s->format('H:i') : self::dateFr($s).' à '.$s->format('H:i'));
                AppNotification::send($company->id, $users, 'agenda', 'Rappel : '.$e->title, ucfirst($label).($e->location ? ' · '.$e->location : ''), 'agenda', $e->id);
                $n++;
            }
        }
        DB::table('ag_reminders')->where('created_at', '<', now()->subDays(60))->delete();

        return $n;
    }

    /** Lien d'abonnement personnel (Google Agenda, Outlook, téléphone). */
    public function feedUrl(User $user, bool $renew = false): string
    {
        $row = DB::table('ag_feeds')->where('user_id', $user->id)->first();
        if ($row && ! $renew) {
            return rtrim(config('app.url'), '/').'/agenda/flux/'.decrypt($row->token).'.ics';
        }
        $token = Str::random(40);
        DB::table('ag_feeds')->updateOrInsert(['user_id' => $user->id], ['company_id' => $user->company_id, 'token_hash' => hash('sha256', $token), 'token' => encrypt($token), 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]);

        return rtrim(config('app.url'), '/').'/agenda/flux/'.$token.'.ics';
    }

    /** Événements de l'utilisateur : organisés par lui ou auxquels il participe (hors refus). */
    public function eventsFor(User $user)
    {
        return Event::where('status', 'confirmed')->get()->filter(fn ($e) => (int) $e->owner_id === $user->id
            || ($e->isAttendee($user->id) && (((array) $e->responses)[(string) $user->id] ?? null) !== 'declined'))->values();
    }
}
