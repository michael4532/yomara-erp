<?php

namespace App\Modules\Billetterie;

use App\Http\Controllers\Controller;
use App\Modules\Billetterie\Models\Ticket;
use App\Modules\Billetterie\Models\TicketOrder;
use App\Modules\Evenements\Models\Booking;
use App\Modules\Evenements\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BilletterieController extends Controller
{
    public function __construct(private TicketService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function scan(Request $r)
    {
        $v = $r->validate(['event_id' => 'required|uuid', 'code' => 'required|string|max:200', 'gate' => 'nullable|string|max:60']);

        return $this->guard(fn () => ['data' => $this->svc->scan($v['event_id'], $v['code'], $v['gate'] ?? null)]);
    }

    public function stats(string $id)
    {
        return ['data' => $this->svc->stats(Event::findOrFail($id))];
    }

    /** Analyse des inscriptions : courbe, provenance, réponses, présence, avis. */
    public function analytics(string $id)
    {
        return ['data' => app(RegistrationService::class)->analytics(Event::findOrFail($id))];
    }

    /** Fréquentation : inscrits et entrées par jour, par borne, visiteurs uniques, provenance. */
    public function attendance(string $id)
    {
        return ['data' => app(RegistrationService::class)->attendance(Event::findOrFail($id))];
    }

    /** Rapport Excel de fréquentation : par jour, par borne, provenance, liste des inscrits avec leurs coordonnées. */
    public function attendanceExport(string $id)
    {
        $e = Event::findOrFail($id);
        $reg = app(RegistrationService::class);
        $a = $reg->attendance($e);
        [$head, $rows] = $reg->attendanceRows($e);
        $x = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = function (string $title, array $header, array $data, array $widths = []) use ($x) {
            $sh = $x->getSheetCount() === 1 && $x->getActiveSheet()->getTitle() === 'Worksheet' ? $x->getActiveSheet() : $x->createSheet();
            $sh->setTitle(mb_substr($title, 0, 31));
            $sh->fromArray($header, null, 'A1');
            $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($header)));
            $sh->getStyle('A1:'.$last.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sh->getStyle('A1:'.$last.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF0B1B3A');
            $r = 2;
            foreach ($data as $row) {
                $sh->fromArray(\App\Support\SafeCell::csvRow($row), null, 'A'.$r++, true);
            }
            foreach (range(1, count($header)) as $i) {
                $sh->getColumnDimensionByColumn($i)->setWidth($widths[$i - 1] ?? 18);
            }
            $sh->freezePane('A2');

            return $sh;
        };
        $fr = fn ($d) => \Carbon\Carbon::parse($d)->locale('fr')->isoFormat('ddd D MMM YYYY');
        $days = array_map(fn ($d) => [$fr($d['date']), $d['registrations'], $d['kiosk'], $d['online'], $d['box_office'], $d['entries'], $d['new_visitors'], $d['returning'], $d['badges'], $d['peak_hour'] !== null ? $d['peak_hour'].' h' : '', $d['entries_diff'] ?? '', $d['entries_change'] !== null ? $d['entries_change'].' %' : '', $d['registrations_change'] !== null ? $d['registrations_change'].' %' : ''], $a['days']);
        $t = $a['totals'];
        $days[] = ['TOTAL', $t['registrations'], $t['kiosk_registrations'], array_sum(array_column($a['days'], 'online')), array_sum(array_column($a['days'], 'box_office')), $t['entries'], $t['unique_visitors'], $t['returning_visitors'], array_sum(array_column($a['days'], 'badges')), '', '', '', ''];
        $sh = $sheet('Par jour', ['Jour', 'Inscrits', 'dont aux bornes', 'dont en ligne', 'dont au guichet', 'Visiteurs entrés', 'Nouveaux visiteurs', 'Revenus d\'un autre jour', 'Badges (exposants, organisation)', 'Heure de pointe', 'Écart visiteurs / veille', 'Évolution visiteurs / veille', 'Évolution inscrits / veille'], $days, [22, 12, 14, 14, 14, 16, 18, 20, 22, 14, 16, 18, 18]);
        $sh->getStyle('A'.(count($days) + 1).':M'.(count($days) + 1))->getFont()->setBold(true);
        $kh = ['Borne'];
        foreach ($a['days'] as $d) {
            array_push($kh, 'Inscrits '.\Carbon\Carbon::parse($d['date'])->format('d/m'), 'Entrées '.\Carbon\Carbon::parse($d['date'])->format('d/m'));
        }
        array_push($kh, 'Total inscrits', 'Total entrées');
        $sheet('Classement des bornes', ['Rang', 'Borne', 'Inscriptions', '% des inscriptions aux bornes', 'Visiteurs accueillis (entrées)', 'Meilleur jour'],
            array_map(fn ($k) => [$k['rank'], $k['name'], $k['registrations'], str_replace('.', ',', (string) $k['share']).' %', $k['entries'], $k['best_day'] ? \Illuminate\Support\Carbon::parse($k['best_day'])->format('d/m/Y') : ''], $a['kiosks']), [8, 24, 14, 24, 26, 16]);
        $sheet('Par borne', $kh, array_map(fn ($k) => array_merge([$k['name']], collect($k['days'])->flatMap(fn ($d) => [$d['registrations'], $d['entries']])->all(), [$k['registrations'], $k['entries']]), $a['kiosks']), [24]);
        // villes et communes : une ligne par ville, puis ses communes en retrait
        $ph = ['Ville', 'Commune', 'Inscrits', '% des inscrits', 'Visiteurs venus', 'Taux de venue'];
        foreach ($a['days'] as $d) {
            $ph[] = 'Visiteurs '.\Carbon\Carbon::parse($d['date'])->format('d/m');
        }
        $pr = [];
        $line = fn ($city, $commune, $r) => array_merge([$city, $commune, $r['registrations'], $t['registrations'] ? round($r['registrations'] * 100 / $t['registrations'], 1) : 0, $r['visitors'], $r['registrations'] ? round($r['visitors'] * 100 / $r['registrations'], 1).' %' : ''], array_column($r['days'], 'entries'));
        foreach ($a['places'] as $c) {
            $pr[] = $line($c['name'], $c['communes'] ? 'Toutes communes' : '', $c);
            foreach ($c['communes'] as $cm) {
                $pr[] = $line('', $cm['name'], $cm);
            }
        }
        $sheet('Villes et communes', $ph, $pr, [22, 20, 12, 14, 16, 14]);
        $prov = [];
        foreach (['Ville' => $a['cities'], 'Pays' => $a['countries'], 'Canal d\'inscription' => $a['channels'], 'Connu par' => array_map(fn ($s) => ['name' => $s['source'], 'count' => $s['count']], $a['sources']), 'Société' => $a['top_companies']] as $label => $list) {
            foreach ($list as $it) {
                $prov[] = [$label, $it['name'], $it['count'], $t['registrations'] ? round($it['count'] * 100 / $t['registrations'], 1) : 0];
            }
        }
        foreach ($a['answers'] as $q) {
            foreach ($q['distribution'] as $it) {
                $prov[] = [$q['label'], $it['value'], $it['count'], $t['registrations'] ? round($it['count'] * 100 / $t['registrations'], 1) : 0];
            }
        }
        $sheet('Provenance', ['Critère', 'Valeur', 'Inscrits', '% des inscrits'], $prov, [26, 34, 12, 14]);
        $sheet('Inscrits', $head, $rows, [14, 8, 10, 16, 26, 18, 30, 24, 20, 18, 16, 16]);
        $x->setActiveSheetIndex(0);
        $path = tempnam(sys_get_temp_dir(), 'att');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($path);

        return response()->download($path, 'frequentation-'.Str::slug($e->name).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /**
     * Avant l'ouverture : supprime les inscriptions, billets visiteurs et entrées faits pour tester les bornes.
     * Les badges exposants et organisation restent. Refusé une fois l'événement commencé.
     */
    public function clearTests(string $id)
    {
        $e = Event::findOrFail($id);
        if ($e->start_date && $e->start_date->startOfDay()->lte(now())) {
            return response()->json(['message' => 'L\'événement a commencé : les inscriptions ne peuvent plus être supprimées.'], 422);
        }
        // Suppression définitive (y compris les essais déjà supprimés) : les bornes repartent à 01-0001 et ces numéros
        // doivent redevenir libres (un billet supprimé « en douce » garderait son numéro réservé).
        $orders = TicketOrder::withTrashed()->where('event_id', $e->id)->get();
        $tickets = Ticket::withTrashed()->where('event_id', $e->id)->whereIn('order_id', $orders->pluck('id'))->get();
        $n = ['orders' => $orders->whereNull('deleted_at')->count(), 'tickets' => $tickets->whereNull('deleted_at')->count(), 'checkins' => 0];
        \Illuminate\Support\Facades\DB::transaction(function () use ($e, $orders, $tickets, &$n) {
            foreach (\App\Modules\Billetterie\Models\Checkin::withTrashed()->where('event_id', $e->id)->whereIn('ticket_id', $tickets->pluck('id'))->get() as $c) {
                $n['checkins'] += $c->trashed() ? 0 : 1;
                $c->forceDelete();
            }
            $tickets->each->forceDelete();
            $orders->each->forceDelete();
        });

        return ['data' => $n];
    }

    /** Envoyer maintenant la demande d'avis aux participants. */
    public function requestFeedback(string $id)
    {
        $e = Event::findOrFail($id);
        if ($e->feedback_sent_at) {
            return response()->json(['message' => 'Demande d\'avis déjà envoyée le '.$e->feedback_sent_at->format('d/m/Y').'.'], 422);
        }
        if ($e->end_date->isFuture()) {
            return response()->json(['message' => 'L\'événement n\'est pas encore terminé.'], 422);
        }
        $n = app(RegistrationService::class)->requestFeedback(app('tenant'), now()->addDay()->toDateString(), $e->id);

        return ['data' => ['sent' => $n]];
    }

    public function badges(Request $r, string $id)
    {
        $v = $r->validate(['names' => 'required|array|min:1|max:50', 'names.*' => 'nullable|string|max:160']);

        return $this->guard(fn () => response()->json(['data' => array_map(fn ($t) => $t->toSyncArray(), $this->svc->exhibitorBadges(Booking::findOrFail($id), $v['names']))], 201));
    }

    public function resend(string $id)
    {
        $o = TicketOrder::findOrFail($id);

        return $this->svc->sendTickets(app('tenant'), $o) ? ['data' => ['sent' => true]]
            : response()->json(['message' => 'Aucun canal d\'envoi disponible (e-mail ou WhatsApp de l\'acheteur et canaux configurés).'], 422);
    }

    public function lookup(Request $r)
    {
        $t = $this->svc->find((string) $r->validate(['code' => 'required|string|max:200'])['code']);

        return $t ? ['data' => $t->toSyncArray()] : response()->json(['message' => 'Billet inconnu.'], 404);
    }
}
