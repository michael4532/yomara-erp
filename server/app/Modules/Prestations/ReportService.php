<?php

namespace App\Modules\Prestations;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Prestations\Models\Deliverable;
use App\Modules\Prestations\Models\Entry;
use App\Modules\Prestations\Models\Pack;
use App\Modules\Prestations\Models\Report;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/** Rapports d'activité mensuels des clients : temps passé, livrables, reste des forfaits. */
class ReportService
{
    public const DEFAULTS = ['monthly_reports' => false, 'include_projects' => true];

    public function settings(Company $c): array
    {
        return array_merge(self::DEFAULTS, (array) data_get($c->settings, 'prestations', []));
    }

    public function saveSettings(Company $c, array $v): array
    {
        $s = (array) ($c->settings ?? []);
        $s['prestations'] = array_merge($this->settings($c), $v);
        $c->settings = $s;
        $c->save();

        return $s['prestations'];
    }

    /** Données du rapport (aussi utilisées pour l'aperçu). */
    public function data(Company $company, string $partnerId, string $period): array
    {
        $from = Carbon::parse($period.'-01')->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $packIds = Pack::where('partner_id', $partnerId)->pluck('id');
        $entries = Entry::whereIn('pack_id', $packIds)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->get();
        $time = $entries->map(fn ($e) => ['date' => $e->date->toDateString(), 'hours' => (float) $e->hours, 'description' => $e->description, 'who' => $e->user_name,
            'scope' => $e->project_name ?: optional(Pack::find($e->pack_id))->name, 'pack' => true])->all();
        $settings = $this->settings($company);
        if ($settings['include_projects'] && $company->hasModule('projets')) {
            $projects = \App\Modules\Projets\Models\Project::where('partner_id', $partnerId)->pluck('name', 'id');
            $users = User::where('company_id', $company->id)->pluck('name', 'id');
            foreach (\App\Modules\Projets\Models\Timesheet::whereIn('project_id', $projects->keys())->whereNull('pack_id')
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->get() as $t) {
                $time[] = ['date' => $t->date->toDateString(), 'hours' => (float) $t->hours, 'description' => $t->description ?: 'Temps passé', 'who' => $users[$t->user_id] ?? null,
                    'scope' => $projects[$t->project_id] ?? 'Projet', 'pack' => false];
            }
        }
        usort($time, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $packs = Pack::where('partner_id', $partnerId)->where('status', '!=', 'cancelled')->where('start_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $from->toDateString()))->orderBy('start_date')->get()
            ->map(function (Pack $p) use ($to, $from) {
                $until = (float) Entry::where('pack_id', $p->id)->where('date', '<=', $to->toDateString())->sum('hours');
                $month = (float) Entry::where('pack_id', $p->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('hours');

                return ['number' => $p->number, 'name' => $p->name, 'hours' => (float) $p->hours, 'month' => round($month, 2), 'consumed' => round($until, 2),
                    'remaining' => round($p->hours - $until, 2), 'expiry' => $p->expiry_date?->toDateString(), 'status' => $p->status];
            })->all();
        $deliverables = Deliverable::where('partner_id', $partnerId)->where('status', '!=', 'draft')
            ->where(fn ($q) => $q->whereBetween('sent_at', [$from, $to])->orWhereBetween('decided_at', [$from, $to]))->orderBy('sent_at')->get()
            ->map(fn ($d) => ['number' => $d->number, 'title' => $d->title, 'version' => $d->version_no, 'status' => $d->status, 'sent_at' => $d->sent_at?->toDateString(),
                'decided_at' => $d->decided_at?->toDateString(), 'comment' => $d->client_comment])->all();

        return ['period' => $period, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'time' => $time, 'hours' => round(array_sum(array_column($time, 'hours')), 2),
            'packs' => $packs, 'deliverables' => $deliverables];
    }

    public function hasActivity(array $d): bool
    {
        return $d['time'] || $d['deliverables'];
    }

    public function generate(Company $company, string $partnerId, string $period, ?User $by = null): Report
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) || $period > now()->format('Y-m')) {
            throw new \DomainException('Mois invalide.');
        }
        $partner = Partner::findOrFail($partnerId);
        $data = $this->data($company, $partnerId, $period);
        $report = Report::where('partner_id', $partnerId)->where('period', $period)->first() ?? new Report;
        $report->forceFill(['partner_id' => $partnerId, 'period' => $period, 'partner_name' => $partner->name, 'hours' => $data['hours'],
            'deliverables' => count($data['deliverables']), 'created_by' => $by?->name ?? $report->created_by]);
        $report->save();
        $path = 'reports/'.$company->id.'/'.$report->id.'.pdf';
        Storage::disk('local')->put($path, $this->pdf($company, $partner, $data, $report));
        $report->forceFill(['file_path' => $path])->save();

        return $report;
    }

    public function send(Company $company, Report $r): bool
    {
        $p = Partner::find($r->partner_id);
        $h = fn ($v) => rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
        $text = 'Bonjour '.($p?->name ?? '').",\n\nVoici le rapport d'activité de ".$r->label().' : '.$h($r->hours).' h de travail'
            .($r->deliverables ? ', '.$r->deliverables.' livrable(s)' : '').".\n\nRapport (PDF) : ".$r->publicUrl()."\n\n{$company->name}";
        $messaging = app(MessagingService::class);
        $avail = $messaging->available($company);
        $to = [];
        if ($p?->email && ($avail['email'] ?? null)) {
            $pdf = $r->file_path && Storage::disk('local')->exists($r->file_path) ? Storage::disk('local')->get($r->file_path) : null;
            try {
                if ($messaging->send($company, 'email', new Message(to: $p->email, subject: 'Rapport d\'activité — '.$r->label(), html: nl2br(e($text)), text: $text, toName: $p->name,
                    attachments: $pdf ? [['name' => 'rapport-'.$r->period.'.pdf', 'data' => $pdf, 'mime' => 'application/pdf']] : []), 'prestations')->ok) {
                    $to[] = $p->email;
                }
            } catch (\Throwable) {
            }
        }
        if ($p?->phone && ($avail['whatsapp'] ?? null)) {
            try {
                if ($messaging->send($company, 'whatsapp', new Message(to: $p->phone, text: $text, toName: $p->name), 'prestations')->ok) {
                    $to[] = $p->phone;
                }
            } catch (\Throwable) {
            }
        }
        if ($to) {
            $r->forceFill(['sent_at' => now(), 'sent_to' => implode(', ', $to)])->save();
        }

        return (bool) $to;
    }

    /** Le 1er du mois : un rapport par client ayant eu de l'activité le mois précédent. */
    public function runMonthly(Company $company): int
    {
        if (! $this->settings($company)['monthly_reports']) {
            return 0;
        }
        $period = now()->subMonthNoOverflow()->format('Y-m');
        $partners = Pack::pluck('partner_id')->merge(Deliverable::pluck('partner_id'));
        if ($this->settings($company)['include_projects'] && $company->hasModule('projets')) {
            $partners = $partners->merge(\App\Modules\Projets\Models\Project::whereNotNull('partner_id')->pluck('partner_id'));
        }
        $n = 0;
        foreach ($partners->unique()->filter() as $pid) {
            if (Report::where('partner_id', $pid)->where('period', $period)->exists()) {
                continue;
            }
            if (! $this->hasActivity($this->data($company, $pid, $period))) {
                continue;
            }
            $r = $this->generate($company, $pid, $period);
            $this->send($company, $r);
            $n++;
        }

        return $n;
    }

    public function pdf(Company $company, Partner $partner, array $d, Report $r): string
    {
        $h = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',').' h';
        $fr = fn ($s) => $s ? Carbon::parse($s)->format('d/m/Y') : '';
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Yomara ERP');
        $pdf->SetTitle('Rapport d\'activité '.$r->label());
        $pdf->setPrintHeader(false);
        $pdf->setFooterData();
        $pdf->setFooterFont(['dejavusans', '', 7]);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 9);
        $status = ['active' => 'En cours', 'exhausted' => 'Consommé', 'expired' => 'Expiré'];
        $dstatus = ['sent' => 'En attente de votre réponse', 'approved' => 'Validé', 'changes' => 'Modifications demandées'];
        $html = '<table cellpadding="0"><tr><td width="60%"><b style="font-size:13pt">'.$e($company->name).'</b><br><span style="color:#666">'.$e(trim(($company->address ?? '').' '.($company->phone ?? ''))).'</span></td>'
            .'<td width="40%" align="right"><b style="font-size:15pt;color:#1f6feb">Rapport d\'activité</b><br>'.$e(ucfirst($r->label())).'</td></tr></table>'
            .'<p style="font-size:10pt">Client : <b>'.$e($partner->name).'</b></p>';
        $kp = '<table cellpadding="6" style="border:0.3px solid #ccc"><tr>'
            .'<td align="center" bgcolor="#f2f6fd"><span style="color:#666">Temps passé</span><br><b style="font-size:14pt">'.$h($d['hours']).'</b></td>'
            .'<td align="center" bgcolor="#f2f6fd"><span style="color:#666">Interventions</span><br><b style="font-size:14pt">'.count($d['time']).'</b></td>'
            .'<td align="center" bgcolor="#f2f6fd"><span style="color:#666">Livrables</span><br><b style="font-size:14pt">'.count($d['deliverables']).'</b></td>'
            .'<td align="center" bgcolor="#f2f6fd"><span style="color:#666">Reste sur forfait(s)</span><br><b style="font-size:14pt">'.$h(array_sum(array_map(fn ($p) => max(0, $p['remaining']), $d['packs']))).'</b></td>'
            .'</tr></table>';
        $html .= $kp;
        if ($d['packs']) {
            $html .= '<h3 style="color:#1f6feb">Forfaits d\'heures</h3><table cellpadding="4" border="0.3"><tr bgcolor="#eeeeee" style="font-weight:bold"><td width="34%">Forfait</td><td width="11%" align="right">Heures</td><td width="13%" align="right">Ce mois</td><td width="14%" align="right">Consommé</td><td width="14%" align="right">Reste</td><td width="14%">Échéance</td></tr>';
            foreach ($d['packs'] as $p) {
                $html .= '<tr><td>'.$e($p['name']).'<br><span style="color:#888;font-size:7pt">'.$e($p['number']).' · '.($status[$p['status']] ?? '').'</span></td><td align="right">'.$h($p['hours']).'</td><td align="right">'.$h($p['month']).'</td><td align="right">'.$h($p['consumed']).'</td>'
                    .'<td align="right"'.($p['remaining'] < 0 ? ' style="color:#c62828"' : '').'>'.($p['remaining'] < 0 ? 'dépassement '.$h(-$p['remaining']) : $h($p['remaining'])).'</td><td>'.$fr($p['expiry']).'</td></tr>';
            }
            $html .= '</table>';
        }
        $html .= '<h3 style="color:#1f6feb">Temps passé</h3>';
        if ($d['time']) {
            $html .= '<table cellpadding="4" border="0.3"><tr bgcolor="#eeeeee" style="font-weight:bold"><td width="13%">Date</td><td width="25%">Forfait / projet</td><td width="38%">Travail réalisé</td><td width="14%">Intervenant</td><td width="10%" align="right">Durée</td></tr>';
            foreach ($d['time'] as $t) {
                $html .= '<tr><td>'.$fr($t['date']).'</td><td>'.$e($t['scope']).'</td><td>'.$e($t['description']).'</td><td>'.$e($t['who']).'</td><td align="right">'.$h($t['hours']).'</td></tr>';
            }
            $html .= '<tr style="font-weight:bold"><td colspan="4" align="right">Total</td><td align="right">'.$h($d['hours']).'</td></tr></table>';
        } else {
            $html .= '<p style="color:#666">Aucun temps saisi ce mois-ci.</p>';
        }
        if ($d['deliverables']) {
            $html .= '<h3 style="color:#1f6feb">Livrables</h3><table cellpadding="4" border="0.3"><tr bgcolor="#eeeeee" style="font-weight:bold"><td width="42%">Livrable</td><td width="14%">Envoyé le</td><td width="44%">État</td></tr>';
            foreach ($d['deliverables'] as $l) {
                $html .= '<tr><td>'.$e($l['title']).'<br><span style="color:#888;font-size:7pt">'.$e($l['number']).' · version '.$l['version'].'</span></td><td>'.$fr($l['sent_at']).'</td><td>'.($dstatus[$l['status']] ?? $l['status'])
                    .($l['decided_at'] ? ' le '.$fr($l['decided_at']) : '').($l['comment'] ? '<br><i style="color:#666">« '.$e($l['comment']).' »</i>' : '').'</td></tr>';
            }
            $html .= '</table>';
        }
        $html .= '<p style="color:#888;font-size:7pt">Rapport généré le '.now()->format('d/m/Y à H:i').' · période du '.$fr($d['from']).' au '.$fr($d['to']).'.</p>';
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}
