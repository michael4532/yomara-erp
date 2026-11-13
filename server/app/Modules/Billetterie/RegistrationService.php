<?php

namespace App\Modules\Billetterie;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Modules\Billetterie\Models\Checkin;
use App\Modules\Billetterie\Models\Ticket;
use App\Modules\Billetterie\Models\TicketOrder;
use App\Modules\Billetterie\Models\TicketType;
use App\Modules\Evenements\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inscriptions aux événements : formulaire (questions, consentement), e-mail de confirmation avec billets PDF et agenda,
 * contacts versés dans les listes du Marketing, avis des participants après l'événement, statistiques.
 */
class RegistrationService
{
    public const QUESTION_TYPES = ['text' => 'Texte court', 'textarea' => 'Texte long', 'select' => 'Choix dans une liste', 'checkbox' => 'Case à cocher', 'phone' => 'Téléphone', 'number' => 'Nombre'];

    public static function defaults(): array
    {
        return [
            'ask_email' => 'required', // required, optional
            'ask_company' => true,
            'ask_job' => false,
            'questions' => [],
            'consent_text' => 'J\'accepte de recevoir les informations de {societe} (prochains événements, offres).',
            'confirmation_message' => 'Votre inscription est confirmée. Présentez le QR code de votre billet à l\'entrée.',
            'global_list' => true,
            // Provenance : off, optional, required
            'ask_city' => 'optional',
            'ask_country' => 'off',
            'default_country' => 'Côte d\'Ivoire',
            // Bornes d'accueil
            'kiosk_welcome' => 'Bienvenue ! Inscrivez-vous en une minute pour entrer au salon.',
            'kiosk_type_id' => null,
            'kiosk_color' => null, // couleur des bornes (#RRGGBB), sinon bleu nuit
            'kiosk_logo' => true, // logo de la société sur les bornes et les tickets
        ];
    }

    public function form(Event $e): array
    {
        $f = array_replace(self::defaults(), (array) ($e->registration ?? []));
        $f['questions'] = array_values(array_filter((array) $f['questions'], fn ($q) => ! empty($q['label'])));

        return $f;
    }

    public static function cleanForm(array $v): array
    {
        $q = [];
        foreach ((array) ($v['questions'] ?? []) as $i => $x) {
            if (trim((string) ($x['label'] ?? '')) === '') {
                continue;
            }
            $type = array_key_exists($x['type'] ?? '', self::QUESTION_TYPES) ? $x['type'] : 'text';
            $q[] = ['id' => $x['id'] ?? 'q'.Str::random(6), 'label' => Str::limit(trim($x['label']), 160, ''), 'type' => $type, 'required' => (bool) ($x['required'] ?? false),
                'options' => $type === 'select' ? array_values(array_filter(array_map(fn ($o) => Str::limit(trim((string) $o), 80, ''), (array) ($x['options'] ?? [])))) : []];
        }

        $out = array_intersect_key(array_replace(self::defaults(), $v, ['questions' => array_slice($q, 0, 20)]), self::defaults());
        foreach (['ask_city', 'ask_country'] as $k) {
            $out[$k] = in_array($out[$k], ['off', 'optional', 'required'], true) ? $out[$k] : 'off';
        }
        $out['default_country'] = Str::limit(trim((string) $out['default_country']), 80, '');
        $out['kiosk_welcome'] = Str::limit(trim((string) $out['kiosk_welcome']), 300, '');
        $out['kiosk_type_id'] = Str::isUuid((string) $out['kiosk_type_id']) ? $out['kiosk_type_id'] : null;
        $out['kiosk_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $out['kiosk_color']) ? strtoupper($out['kiosk_color']) : null;
        $out['kiosk_logo'] = (bool) $out['kiosk_logo'];

        return $out;
    }

    /** Réponses validées, ou exception avec le premier message d'erreur. */
    public function answers(Event $e, array $input): array
    {
        $out = [];
        foreach ($this->form($e)['questions'] as $q) {
            $v = $input[$q['id']] ?? null;
            $v = is_string($v) ? trim($v) : $v;
            if ($q['type'] === 'checkbox') {
                $v = (bool) $v;
            }
            if ($q['required'] && ($v === null || $v === '' || $v === false)) {
                throw new \DomainException('Répondez à « '.$q['label'].' ».');
            }
            if ($q['type'] === 'select' && $v !== null && $v !== '' && ! in_array($v, $q['options'], true)) {
                throw new \DomainException('Choix non valable pour « '.$q['label'].' ».');
            }
            if ($v !== null && $v !== '') {
                $out[] = ['id' => $q['id'], 'label' => $q['label'], 'value' => is_bool($v) ? ($v ? 'Oui' : 'Non') : Str::limit((string) $v, 1000, '')];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ Marketing

    /** Liste « Inscrits — événement » (créée au besoin) et, avec le consentement, la liste générale des abonnés événements. */
    public function capture(TicketOrder $o): ?int
    {
        $c = app('tenant');
        if (! $c->hasModule('marketing') || (! $o->buyer_email && ! $o->buyer_phone)) {
            return null;
        }
        $e = Event::find($o->event_id);
        $L = \App\Modules\Marketing\Models\MkList::class;
        $list = $e->mk_list_id ? $L::find($e->mk_list_id) : null;
        if (! $list) {
            $list = $L::create(['name' => Str::limit('Inscrits — '.$e->name, 250, ''), 'description' => 'Inscrits à l\'événement du '.$e->start_date->format('d/m/Y').' (ajout automatique)']);
            $e->forceFill(['mk_list_id' => $list->id])->saveQuietly();
        }
        $C = \App\Modules\Marketing\Models\Contact::class;
        $contact = $o->buyer_email ? $C::where('email', mb_strtolower(trim($o->buyer_email)))->first() : null;
        if (! $contact && $o->buyer_phone) {
            $digits = substr(preg_replace('/\D/', '', $o->buyer_phone), -8);
            $contact = $C::whereNotNull('phone')->get()->first(fn ($x) => substr(preg_replace('/\D/', '', (string) $x->phone), -8) === $digits);
        }
        $contact ??= new $C(['source' => 'event']);
        $name = trim($o->buyer_name);
        [$first, $last] = str_contains($name, ' ') ? explode(' ', $name, 2) : [$name, null];
        $contact->first_name ??= $first;
        $contact->last_name ??= $last;
        $contact->email ??= $o->buyer_email ? mb_strtolower(trim($o->buyer_email)) : null;
        $contact->phone ??= $o->buyer_phone;
        if ($o->buyer_company) {
            $contact->company_name ??= $o->buyer_company;
        }
        $tags = collect(explode(',', (string) $contact->tags))->map(fn ($t) => trim($t))->filter()->push('événement')->unique()->implode(', ');
        $contact->tags = Str::limit($tags, 250, '');
        if ($o->consent) {
            $contact->opt_in = $contact->opt_in === 'pending' ? 'confirmed' : ($contact->opt_in ?? 'confirmed');
            $contact->consent_at ??= now();
            if ($o->buyer_email) {
                $contact->unsubscribed_email_at = null;
            }
        }
        $contact->last_activity_at = now();
        $contact->save();
        $contact->lists()->syncWithoutDetaching([$list->id]);
        if ($o->consent && $this->form($e)['global_list']) {
            $g = $L::firstOrCreate(['name' => 'Abonnés événements'], ['description' => 'Participants ayant accepté de recevoir vos informations (ajout automatique)']);
            $contact->lists()->syncWithoutDetaching([$g->id]);
        }

        return $contact->id;
    }

    // ------------------------------------------------------------------ confirmation

    /** Billets en PDF : une page par billet avec QR code, événement, lieu, titulaire. */
    public function ticketsPdf(Company $c, Event $e, iterable $tickets): string
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(18, 18, 18);
        $types = TicketType::where('event_id', $e->id)->pluck('name', 'id');
        foreach ($tickets as $t) {
            $pdf->AddPage();
            $pdf->SetFillColor(11, 27, 58);
            $pdf->Rect(0, 0, 210, 52, 'F');
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('dejavusans', 'B', 20);
            $pdf->SetXY(18, 14);
            $pdf->MultiCell(174, 9, $e->name, 0, 'L');
            $pdf->SetFont('dejavusans', '', 11);
            $pdf->SetX(18);
            $when = ucfirst($e->start_date->locale('fr')->isoFormat('dddd D MMMM YYYY')).($e->end_date && ! $e->end_date->eq($e->start_date) ? ' — '.$e->end_date->locale('fr')->isoFormat('D MMMM YYYY') : '');
            $pdf->MultiCell(174, 6, $when.($e->venue ? ' · '.$e->venue : '').($e->city ? ', '.$e->city : ''), 0, 'L');
            $pdf->SetTextColor(11, 27, 58);
            $pdf->write2DBarcode($t->code, 'QRCODE,M', 18, 66, 70, 70, ['border' => false, 'padding' => 0, 'fgcolor' => [11, 27, 58], 'bgcolor' => false]);
            $pdf->SetXY(98, 66);
            $pdf->SetFont('dejavusans', 'B', 16);
            $pdf->MultiCell(94, 8, (string) ($t->holder_name ?: 'Participant'), 0, 'L');
            $pdf->SetX(98);
            $pdf->SetFont('dejavusans', '', 11);
            $lines = array_filter([$t->company_name, $types[$t->type_id] ?? null, 'Billet '.$t->code, $t->price > 0 ? number_format($t->price, 0, ',', ' ').' '.($e->currency ?: $c->currency) : 'Entrée gratuite']);
            foreach ($lines as $l) {
                $pdf->SetX(98);
                $pdf->MultiCell(94, 6, $l, 0, 'L');
            }
            $pdf->SetXY(18, 146);
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->SetTextColor(91, 107, 133);
            $pdf->MultiCell(174, 5, "Présentez ce QR code à l'entrée, sur votre téléphone ou imprimé. Il n'est valable qu'une fois par jour de validité.\n".$c->name.($c->phone ? ' · '.$c->phone : '').($c->email ? ' · '.$c->email : ''), 0, 'L');
        }

        return $pdf->Output('', 'S');
    }

    /** Fichier agenda (.ics) de l'événement. */
    public static function ics(Company $c, Event $e): string
    {
        $esc = fn ($s) => str_replace([',', ';', "\n"], ['\\,', '\\;', '\\n'], (string) $s);

        return implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Yomara ERP//FR', 'BEGIN:VEVENT', 'UID:'.$e->id.'@yomara.erp', 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:'.$e->start_date->format('Ymd'), 'DTEND;VALUE=DATE:'.$e->end_date->copy()->addDay()->format('Ymd'), 'SUMMARY:'.$esc($e->name),
            'LOCATION:'.$esc(trim(($e->venue ?? '').' '.($e->city ?? ''))), 'DESCRIPTION:'.$esc(Str::limit((string) $e->description, 500)), 'ORGANIZER;CN='.$esc($c->name).':mailto:'.($c->email ?: 'contact@example.com'),
            'END:VEVENT', 'END:VCALENDAR']);
    }

    /** E-mail de confirmation : récapitulatif, QR codes en PDF, agenda ; WhatsApp avec le lien. */
    public function confirm(Company $c, TicketOrder $o): bool
    {
        $e = Event::find($o->event_id);
        $tickets = Ticket::where('order_id', $o->id)->get();
        $form = $this->form($e);
        $free = (float) $o->total <= 0;
        $when = ucfirst($e->start_date->locale('fr')->isoFormat('dddd D MMMM YYYY')).($e->venue ? ' · '.$e->venue : '').($e->city ? ', '.$e->city : '');
        $intro = $free ? strtr($form['confirmation_message'], ['{societe}' => $c->name]) : 'Merci pour votre commande '.$o->number.'. '.strtr($form['confirmation_message'], ['{societe}' => $c->name]);
        $text = "Bonjour {$o->buyer_name},\n\n{$intro}\n\n« {$e->name} »\n{$when}\n".count($tickets)." billet(s) : ".$tickets->pluck('code')->implode(', ')."\n\nVos billets avec leur QR code : ".$o->publicUrl()."\n\n{$c->name}";
        $answers = collect($o->answers ?? [])->map(fn ($a) => '<tr><td style="padding:4px 10px;color:#5B6B85">'.e($a['label']).'</td><td style="padding:4px 10px">'.e($a['value']).'</td></tr>')->implode('');
        $qr = $tickets->take(1)->map(fn ($t) => '<div style="text-align:center;margin:16px 0"><img alt="QR" width="180" height="180" src="data:image/svg+xml;base64,'.base64_encode(TicketService::qrSvg($t->code, 6)).'"><div style="font-family:monospace;font-size:16px;letter-spacing:2px">'.e($t->code).'</div></div>')->implode('');
        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#1b2333;line-height:1.5">'
            .'<div style="background:#0B1B3A;color:#fff;padding:22px 24px;border-radius:14px 14px 0 0"><div style="font-size:13px;opacity:.75">'.e($c->name).'</div><div style="font-size:22px;font-weight:bold">'.e($e->name).'</div><div style="opacity:.85">'.e($when).'</div></div>'
            .'<div style="border:1px solid #E3E8F1;border-top:0;padding:22px 24px;border-radius:0 0 14px 14px">'
            .'<p>Bonjour '.e($o->buyer_name).',</p><p>'.e($intro).'</p>'.$qr
            .'<p><b>'.count($tickets).' billet(s)</b> — vos QR codes sont joints en PDF, et l\'événement à ajouter à votre agenda.</p>'
            .($answers ? '<table style="border-collapse:collapse;font-size:13px;margin:10px 0">'.$answers.'</table>' : '')
            .'<p><a href="'.e($o->publicUrl()).'" style="display:inline-block;background:#1F6FEB;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Voir mes billets</a></p>'
            .'</div></div>';
        $messaging = app(MessagingService::class);
        $avail = $messaging->available($c);
        $sent = false;
        if ($o->buyer_email && ($avail['email'] ?? null)) {
            $att = [['name' => 'billets-'.Str::slug($e->name).'.pdf', 'data' => $this->ticketsPdf($c, $e, $tickets), 'mime' => 'application/pdf'],
                ['name' => Str::slug($e->name).'.ics', 'data' => self::ics($c, $e), 'mime' => 'text/calendar']];
            try {
                $sent = $messaging->send($c, 'email', new Message(to: $o->buyer_email, subject: ($free ? 'Inscription confirmée — ' : 'Vos billets — ').$e->name, html: $html, text: $text, toName: $o->buyer_name, attachments: $att, replyTo: $c->email), 'billetterie')->ok;
            } catch (\Throwable) {
            }
        }
        if ($o->buyer_phone && ($avail['whatsapp'] ?? null)) {
            try {
                $sent = $messaging->send($c, 'whatsapp', new Message(to: $o->buyer_phone, text: $text, toName: $o->buyer_name), 'billetterie')->ok || $sent;
            } catch (\Throwable) {
            }
        }

        return $sent;
    }

    // ------------------------------------------------------------------ avis

    /** Le lendemain de la fin de l'événement : demande d'avis à chaque participant (une fois). */
    public function requestFeedback(Company $c, ?string $today = null, ?string $eventId = null): int
    {
        $today ??= now()->toDateString();
        $n = 0;
        $messaging = app(MessagingService::class);
        $avail = $messaging->available($c);
        foreach (Event::where('feedback_enabled', true)->whereNull('feedback_sent_at')->where('end_date', '<', $today)->where('end_date', '>=', Carbon::parse($today)->subDays(10)->toDateString())->where('status', '!=', 'cancelled')->when($eventId, fn ($q) => $q->whereKey($eventId))->get() as $e) {
            $e->forceFill(['feedback_sent_at' => now()])->saveQuietly();
            if (! ($avail['email'] ?? null) && ! ($avail['whatsapp'] ?? null)) {
                continue;
            }
            $seen = [];
            foreach (Ticket::where('event_id', $e->id)->where('status', 'valid')->whereIn('kind', ['visitor', 'vip', 'press'])->orderBy('created_at')->orderBy('code')->get() as $t) {
                $key = mb_strtolower((string) ($t->holder_email ?: $t->holder_phone));
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $link = url('/avis/'.$t->token);
                $text = "Bonjour {$t->holder_name},\n\nMerci d'être venu(e) à « {$e->name} ». Votre avis compte : il prend 30 secondes.\n{$link}\n\n{$c->name}";
                $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;line-height:1.5"><p>Bonjour '.e((string) $t->holder_name).',</p><p>Merci d\'être venu(e) à <b>'.e($e->name).'</b>. Comment avez-vous trouvé l\'événement ?</p><p>'
                    .collect(range(1, 5))->map(fn ($s) => '<a href="'.e($link.'?note='.$s).'" style="display:inline-block;margin:2px;padding:10px 14px;border-radius:8px;background:#FFF4D6;color:#6B4A0E;text-decoration:none;font-weight:bold">'.str_repeat('★', $s).'</a>')->implode('')
                    .'</p><p style="color:#5B6B85;font-size:12px">'.e($c->name).'</p></div>';
                try {
                    if ($t->holder_email && ($avail['email'] ?? null)) {
                        $messaging->send($c, 'email', new Message(to: $t->holder_email, subject: 'Votre avis sur '.$e->name, html: $html, text: $text, toName: (string) $t->holder_name), 'avis');
                        $n++;
                    } elseif ($t->holder_phone && ($avail['whatsapp'] ?? null)) {
                        $messaging->send($c, 'whatsapp', new Message(to: $t->holder_phone, text: $text), 'avis');
                        $n++;
                    }
                } catch (\Throwable) {
                }
            }
        }

        return $n;
    }

    public function saveFeedback(Ticket $t, array $d): void
    {
        DB::table('ev_feedback')->updateOrInsert(['ticket_id' => $t->id], [
            'company_id' => $t->company_id, 'event_id' => $t->event_id, 'name' => $t->holder_name, 'email' => $t->holder_email,
            'rating' => max(1, min(5, (int) $d['rating'])), 'nps' => isset($d['nps']) && $d['nps'] !== '' ? max(0, min(10, (int) $d['nps'])) : null,
            'comment' => isset($d['comment']) && trim((string) $d['comment']) !== '' ? Str::limit(trim((string) $d['comment']), 3000, '') : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ statistiques

    public function analytics(Event $e): array
    {
        $orders = TicketOrder::where('event_id', $e->id)->where('status', '!=', 'cancelled')->get();
        $tickets = Ticket::where('event_id', $e->id)->where('status', 'valid')->whereIn('kind', ['visitor', 'vip', 'press'])->get();
        $byDay = $orders->groupBy(fn ($o) => $o->created_at->toDateString())->map(fn ($g, $d) => ['date' => $d, 'orders' => $g->count(), 'tickets' => (int) $g->sum(fn ($o) => collect($o->items)->sum('quantity'))])->sortKeys()->values();
        $cum = 0;
        $byDay = $byDay->map(function ($r) use (&$cum) {
            $cum += $r['tickets'];

            return $r + ['cumulative' => $cum];
        });
        $sources = $orders->groupBy(fn ($o) => $o->source ?: ['online' => 'Site / lien direct', 'kiosk' => 'Bornes d\'accueil'][$o->channel] ?? 'Guichet')->map(fn ($g, $k) => ['source' => $k, 'count' => $g->count()])->sortByDesc('count')->values();
        $answers = [];
        foreach ($this->form($e)['questions'] as $q) {
            $vals = $orders->flatMap(fn ($o) => collect($o->answers ?? [])->where('id', $q['id'])->pluck('value'));
            $answers[] = ['id' => $q['id'], 'label' => $q['label'], 'type' => $q['type'], 'count' => $vals->count(),
                'distribution' => in_array($q['type'], ['select', 'checkbox'], true) ? $vals->countBy()->map(fn ($n, $v) => ['value' => (string) $v, 'count' => $n])->sortByDesc('count')->values()->all() : [],
                'samples' => in_array($q['type'], ['text', 'textarea'], true) ? $vals->take(-10)->values()->all() : []];
        }
        $fb = DB::table('ev_feedback')->where('event_id', $e->id)->orderByDesc('created_at')->get();
        $nps = $fb->whereNotNull('nps');
        $promoters = $nps->where('nps', '>=', 9)->count();
        $detractors = $nps->where('nps', '<=', 6)->count();
        $companies = $orders->filter(fn ($o) => trim((string) $o->buyer_company) !== '')->groupBy(fn ($o) => mb_strtolower(trim($o->buyer_company)))
            ->mapWithKeys(fn ($g) => [trim($g->first()->buyer_company) => $g->count()])->sortDesc()->take(10);

        return [
            'registrations' => $orders->count(), 'tickets' => $tickets->count(),
            'free' => $orders->where('total', '<=', 0)->count(), 'paid' => $orders->where('total', '>', 0)->where('status', 'paid')->count(), 'pending' => $orders->where('status', 'pending')->count(),
            'checked_in' => $tickets->whereNotNull('checked_in_at')->count(),
            'attendance_rate' => $tickets->count() ? round($tickets->whereNotNull('checked_in_at')->count() * 100 / $tickets->count(), 1) : null,
            'with_email' => $orders->whereNotNull('buyer_email')->count(), 'consent' => $orders->where('consent', true)->count(),
            'mk_list_id' => $e->mk_list_id, 'list_contacts' => $e->mk_list_id ? DB::table('mk_list_contact')->where('list_id', $e->mk_list_id)->count() : 0,
            'by_day' => $byDay->all(), 'sources' => $sources->all(), 'answers' => $answers,
            'top_companies' => $companies->map(fn ($n, $k) => ['name' => $k, 'count' => $n])->values()->all(),
            'feedback' => [
                'count' => $fb->count(), 'rating' => $fb->count() ? round($fb->avg('rating'), 2) : null,
                'stars' => collect(range(1, 5))->mapWithKeys(fn ($s) => [$s => $fb->where('rating', $s)->count()])->all(),
                'nps' => $nps->count() ? (int) round(($promoters - $detractors) * 100 / $nps->count()) : null,
                'comments' => $fb->whereNotNull('comment')->take(50)->map(fn ($f) => ['name' => $f->name, 'rating' => $f->rating, 'nps' => $f->nps, 'comment' => $f->comment, 'date' => Carbon::parse($f->created_at)->toDateString()])->values()->all(),
                'requested_at' => $e->feedback_sent_at?->toIso8601String(),
            ],
            'checkins_by_hour' => Checkin::where('event_id', $e->id)->where('result', 'ok')->get()->groupBy(fn ($x) => $x->scanned_at->format('Y-m-d H:00'))->map->count()->sortKeys()->all(),
        ];
    }

    // ------------------------------------------------------------------ fréquentation (bornes d'accueil)

    /** Jour local d'un instant (fuseau de l'application). */
    protected static function day($at): ?string
    {
        return $at ? Carbon::parse($at)->timezone(config('app.timezone'))->toDateString() : null;
    }

    /** Ville / pays écrits de façons différentes (« abidjan », « ABIDJAN ») regroupés. */
    protected static function place(?string $v): ?string
    {
        $v = trim(preg_replace('/\s+/', ' ', (string) $v));

        return $v === '' ? null : mb_convert_case(mb_strtolower($v), MB_CASE_TITLE);
    }

    /**
     * Fréquentation de l'événement : inscrits et entrées par jour, par borne et par heure, visiteurs uniques,
     * nouveaux et revenants, provenance (ville, pays, canal), réponses aux questions.
     * Les entrées comptent les visiteurs (billets visiteur, VIP, presse) ; les badges exposants et organisation sont à part.
     */
    public function attendance(Event $e): array
    {
        $tz = config('app.timezone');
        $orders = TicketOrder::where('event_id', $e->id)->where('status', '!=', 'cancelled')->get();
        $visitorKinds = ['visitor', 'vip', 'press'];
        $tickets = Ticket::where('event_id', $e->id)->where('status', 'valid')->get(['id', 'kind', 'order_id', 'checked_in_at']);
        $kindOf = $tickets->pluck('kind', 'id');
        $checks = Checkin::where('event_id', $e->id)->orderBy('scanned_at')->get(['ticket_id', 'scanned_at', 'gate', 'result']);
        $ok = $checks->where('result', 'ok')->filter(fn ($c) => $c->ticket_id);

        // jours : ceux de l'événement, plus tout jour qui a des données
        $days = [];
        if ($e->start_date) {
            for ($d = $e->start_date->copy(); $d->lte($e->end_date ?? $e->start_date) && count($days) < 60; $d->addDay()) {
                $days[$d->toDateString()] = true;
            }
        }
        $regDay = fn ($o) => self::day($o->registered_at ?? $o->created_at);
        foreach ($orders as $o) {
            $days[$regDay($o)] = true;
        }
        foreach ($ok as $c) {
            $days[self::day($c->scanned_at)] = true;
        }
        ksort($days);

        // visiteurs entrés : premier jour de présence de chaque billet
        $firstDay = [];
        $daySets = [];
        $staffDay = [];
        foreach ($ok as $c) {
            $d = self::day($c->scanned_at);
            if (! in_array($kindOf[$c->ticket_id] ?? 'visitor', $visitorKinds, true)) {
                $staffDay[$d][$c->ticket_id] = true;

                continue;
            }
            $firstDay[$c->ticket_id] ??= $d;
            $daySets[$d][$c->ticket_id] = true;
        }
        $kioskNames = $orders->pluck('kiosk')->merge($ok->pluck('gate'))->filter()->unique()->sort()->values();

        $byDay = [];
        foreach (array_keys($days) as $d) {
            $regs = $orders->filter(fn ($o) => $regDay($o) === $d);
            $entered = array_keys($daySets[$d] ?? []);
            $new = count(array_filter($entered, fn ($id) => $firstDay[$id] === $d));
            $hours = [];
            foreach ($ok as $c) {
                if (self::day($c->scanned_at) === $d && in_array($kindOf[$c->ticket_id] ?? 'visitor', $visitorKinds, true)) {
                    $h = (int) Carbon::parse($c->scanned_at)->timezone($tz)->format('G');
                    $hours[$h] = ($hours[$h] ?? 0) + 1;
                }
            }
            ksort($hours);
            $peak = $hours ? array_search(max($hours), $hours, true) : null;
            $byDay[] = [
                'date' => $d,
                'registrations' => $regs->count(),
                'kiosk' => $regs->where('channel', 'kiosk')->count(),
                'online' => $regs->where('channel', 'online')->count(),
                'box_office' => $regs->where('channel', 'box_office')->count(),
                'entries' => count($entered),
                'new_visitors' => $new,
                'returning' => count($entered) - $new,
                'badges' => count($staffDay[$d] ?? []),
                'refused' => $checks->where('result', '!=', 'ok')->filter(fn ($c) => self::day($c->scanned_at) === $d)->count(),
                'peak_hour' => $peak,
                'by_hour' => collect($hours)->map(fn ($n, $h) => ['hour' => $h, 'count' => $n])->values()->all(),
            ];
        }

        // par borne : inscriptions (borne d'inscription) et entrées (point de contrôle) de chaque jour
        $byKiosk = [];
        foreach ($kioskNames as $k) {
            $row = ['name' => $k, 'registrations' => 0, 'entries' => 0, 'days' => []];
            foreach (array_keys($days) as $d) {
                $r = $orders->filter(fn ($o) => $o->kiosk === $k && $regDay($o) === $d)->count();
                $en = $ok->filter(fn ($c) => $c->gate === $k && self::day($c->scanned_at) === $d && in_array($kindOf[$c->ticket_id] ?? 'visitor', $visitorKinds, true))->pluck('ticket_id')->unique()->count();
                $row['days'][] = ['date' => $d, 'registrations' => $r, 'entries' => $en];
                $row['registrations'] += $r;
                $row['entries'] += $en;
            }
            $byKiosk[] = $row;
        }

        $count = fn ($col) => $col->filter()->countBy()->sortDesc()->map(fn ($n, $k) => ['name' => (string) $k, 'count' => $n])->values();
        $cities = $count($orders->map(fn ($o) => self::place($o->buyer_city)));
        $countries = $count($orders->map(fn ($o) => self::place($o->buyer_country)));
        $channels = $orders->groupBy('channel')->map(fn ($g, $k) => ['name' => ['kiosk' => 'Bornes', 'online' => 'En ligne', 'box_office' => 'Guichet'][$k] ?? $k, 'count' => $g->count()])->sortByDesc('count')->values();
        $unique = count($firstDay);
        $entries = array_sum(array_column($byDay, 'entries'));
        $eventDays = collect($byDay)->filter(fn ($r) => $r['entries'] > 0)->count();
        $best = collect($byDay)->sortByDesc('entries')->first();
        $an = $this->analytics($e);

        return [
            'event' => ['id' => $e->id, 'name' => $e->name, 'start_date' => $e->start_date?->toDateString(), 'end_date' => $e->end_date?->toDateString(), 'venue' => $e->venue, 'city' => $e->city],
            'totals' => [
                'registrations' => $orders->count(),
                'kiosk_registrations' => $orders->where('channel', 'kiosk')->count(),
                'unique_visitors' => $unique,
                'entries' => $entries,
                'average_per_day' => $eventDays ? round($entries / $eventDays, 1) : 0,
                'returning_visitors' => collect($daySets)->flatMap(fn ($set) => array_keys($set))->countBy()->filter(fn ($n) => $n > 1)->count(),
                'registered_not_entered' => $tickets->whereIn('kind', $visitorKinds)->whereNull('checked_in_at')->count(),
                'with_email' => $orders->filter(fn ($o) => $o->buyer_email)->count(),
                'with_phone' => $orders->filter(fn ($o) => $o->buyer_phone)->count(),
                'consent' => $orders->where('consent', true)->count(),
                'best_day' => $best && $best['entries'] > 0 ? ['date' => $best['date'], 'entries' => $best['entries']] : null,
                'capacity' => $e->capacity,
            ],
            'days' => $byDay,
            'kiosks' => $byKiosk,
            'cities' => $cities->take(20)->all(),
            'countries' => $countries->take(20)->all(),
            'channels' => $channels->all(),
            // lien partagé (?source=facebook…) : seulement les inscriptions qui en indiquent un ; le canal est déjà dans « channels »
            'sources' => $count($orders->map(fn ($o) => $o->source))->map(fn ($x) => ['source' => $x['name'], 'count' => $x['count']])->all(),
            'answers' => $an['answers'],
            'top_companies' => $an['top_companies'],
        ];
    }

    /** Liste des inscrits pour Excel : coordonnées, provenance, réponses, jours de présence. */
    public function attendanceRows(Event $e): array
    {
        $tz = config('app.timezone');
        $form = $this->form($e);
        $orders = TicketOrder::where('event_id', $e->id)->where('status', '!=', 'cancelled')->get()->sortBy(fn ($o) => ($o->registered_at ?? $o->created_at)->timestamp);
        $tickets = Ticket::where('event_id', $e->id)->get(['id', 'order_id', 'code']);
        $byOrder = $tickets->groupBy('order_id');
        $presence = Checkin::where('event_id', $e->id)->where('result', 'ok')->whereNotNull('ticket_id')->get(['ticket_id', 'scanned_at'])
            ->groupBy('ticket_id')->map(fn ($g) => $g->map(fn ($c) => self::day($c->scanned_at))->unique()->sort()->values());
        $head = ['Date d\'inscription', 'Heure', 'Canal', 'Borne', 'Nom', 'Téléphone', 'E-mail', 'Société', 'Fonction', 'Ville', 'Pays', 'Connu par'];
        foreach ($form['questions'] as $q) {
            $head[] = $q['label'];
        }
        array_push($head, 'Accepte les informations', 'Jours de présence', 'Nombre de jours', 'Billet');
        $rows = [];
        foreach ($orders as $o) {
            $at = ($o->registered_at ?? $o->created_at)->copy()->timezone($tz);
            $ts = $byOrder[$o->id] ?? collect();
            $days = $ts->flatMap(fn ($t) => $presence[$t->id] ?? [])->unique()->sort()->values();
            $row = [$at->format('d/m/Y'), $at->format('H:i'), ['kiosk' => 'Borne', 'online' => 'En ligne', 'box_office' => 'Guichet'][$o->channel] ?? $o->channel, $o->kiosk,
                $o->buyer_name, $o->buyer_phone, $o->buyer_email, $o->buyer_company, $o->buyer_job, self::place($o->buyer_city), self::place($o->buyer_country), $o->source];
            $ans = collect($o->answers ?? [])->keyBy('id');
            foreach ($form['questions'] as $q) {
                $row[] = $ans[$q['id']]['value'] ?? null;
            }
            array_push($row, $o->consent ? 'Oui' : 'Non', $days->map(fn ($d) => Carbon::parse($d)->format('d/m'))->implode(', '), $days->count(), $ts->pluck('code')->implode(', '));
            $rows[] = $row;
        }

        return [$head, $rows];
    }
}
