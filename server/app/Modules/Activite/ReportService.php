<?php

namespace App\Modules\Activite;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Support\Tr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Rapports d'activité : brouillon rédigé automatiquement, relecture, envoi au supérieur, validation, PDF et Word. */
class ReportService
{
    public const STATUSES = ['draft' => 'Brouillon', 'sent' => 'Envoyé', 'approved' => 'Validé', 'returned' => 'À compléter'];

    public const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function __construct(private ActivityCollector $collector, private MessagingService $messaging) {}

    /** Semaine de travail (lundi → vendredi) contenant la date. */
    public static function week(?string $date = null): array
    {
        $d = Carbon::parse($date ?? now());
        $mon = $d->copy()->startOfWeek(Carbon::MONDAY);
        // Le week-end, la période va jusqu'au jour même (travail du samedi compris)
        $end = $d->isWeekend() ? $d->copy() : $mon->copy()->addDays(4);

        return [$mon->toDateString(), $end->toDateString()];
    }

    public static function periodLabel(string $from, string $to): string
    {
        $a = Carbon::parse($from);
        $b = Carbon::parse($to);
        if (app()->getLocale() === 'en') {
            $e = fn (Carbon $x, bool $year) => $x->day.' '.$x->locale('en')->monthName.($year ? ' '.$x->year : '');
            if ($a->isSameDay($b)) {
                return $e($a, true);
            }

            return ($a->month === $b->month && $a->year === $b->year ? $a->day : $e($a, $a->year !== $b->year)).' – '.$e($b, true);
        }
        $fmt = fn (Carbon $x, bool $year) => ($x->day === 1 ? '1er' : $x->day).' '.self::MONTHS[$x->month - 1].($year ? ' '.$x->year : '');
        if ($a->isSameDay($b)) {
            return 'du '.$fmt($a, true);
        }
        if ($a->month === $b->month && $a->year === $b->year) {
            return 'du '.($a->day === 1 ? '1er' : $a->day).' au '.$fmt($b, true);
        }

        return 'du '.$fmt($a, $a->year !== $b->year).' au '.$fmt($b, true);
    }

    public function title(User $u, string $from, string $to): string
    {
        $isWeek = Carbon::parse($from)->isMonday() && Carbon::parse($from)->addDays(4)->toDateString() === $to;

        if (app()->getLocale() === 'en') {
            return 'Activity report — '.$u->name.' — '.($isWeek ? 'week of ' : 'period ').self::periodLabel($from, $to);
        }

        return 'Rapport d\'activité — '.$u->name.' — '.($isWeek ? 'semaine ' : 'période ').self::periodLabel($from, $to);
    }

    public function find(Company $c, User $u, string $from, string $to): ?object
    {
        return DB::table('ra_reports')->where('company_id', $c->id)->where('user_id', $u->id)->where('period_start', $from)->where('period_end', $to)->first();
    }

    /** Éléments du rapport : instantané s'il est envoyé, sinon calculés en direct (avec ceux que la personne a retirés). */
    public function items(Company $c, object|array|null $report, User $author, string $from, string $to): array
    {
        $r = $report ? (object) $report : null;
        if ($r && $r->status !== 'draft' && $r->status !== 'returned' && $r->items) {
            return json_decode($r->items, true) ?: [];
        }
        $excluded = $r ? (json_decode($r->excluded ?? '[]', true) ?: []) : [];

        return array_map(fn ($i) => $i + ['included' => ! in_array($i['key'], $excluded, true)], $this->collector->collect($c, $author, $from, $to));
    }

    public function toApi(Company $c, ?object $r, User $author, string $from, string $to, User $viewer): array
    {
        $items = $this->items($c, $r, $author, $from, $to);
        $sup = $r?->supervisor_id ?? (Schema::hasColumn('users', 'supervisor_id') ? DB::table('users')->where('id', $author->id)->value('supervisor_id') : null);
        $names = User::whereIn('id', array_filter([$sup, $r?->reviewed_by]))->pluck('name', 'id');
        $included = array_values(array_filter($items, fn ($i) => $i['included'] ?? true));

        return [
            'id' => $r?->id, 'user_id' => $author->id, 'author' => $author->name, 'job' => $this->job($c, $author),
            'period_start' => $from, 'period_end' => $to, 'period_label' => self::periodLabel($from, $to),
            'title' => $r?->title ?? $this->title($author, $from, $to), 'status' => $r?->status ?? 'draft', 'status_label' => self::STATUSES[$r?->status ?? 'draft'],
            'summary' => $r?->summary, 'difficulties' => $r?->difficulties, 'next_steps' => $r?->next_steps, 'message' => $r?->message,
            'supervisor_id' => $sup, 'supervisor' => $names[$sup] ?? null, 'sent_at' => $r?->sent_at, 'reviewed_at' => $r?->reviewed_at,
            'reviewed_by' => $names[$r?->reviewed_by] ?? null, 'review_comment' => $r?->review_comment,
            'sections' => ActivityCollector::SECTIONS,
            'items' => $items,
            'stats' => ['count' => count($included), 'minutes' => array_sum(array_map(fn ($i) => (int) ($i['minutes'] ?? 0), $included)),
                'by_section' => collect($included)->countBy('section')->all()],
            'can' => [
                'edit' => $author->id === $viewer->id && in_array($r?->status ?? 'draft', ['draft', 'returned'], true),
                'review' => $r && $r->status === 'sent' && ((int) $r->supervisor_id === $viewer->id || $viewer->hasPermission('activite.view_all')),
            ],
        ];
    }

    protected function job(Company $c, User $u): ?string
    {
        if (! Schema::hasTable('rh_employees') || ! Schema::hasColumn('rh_employees', 'user_id')) {
            return null;
        }
        $e = DB::table('rh_employees')->where('company_id', $c->id)->where('user_id', $u->id)->whereNull('deleted_at')->first(['job_title', 'department']);

        return $e ? trim(implode(' · ', array_filter([$e->job_title, $e->department]))) ?: null : null;
    }

    public function save(Company $c, User $u, string $from, string $to, array $d): object
    {
        $r = $this->find($c, $u, $from, $to);
        if ($r && ! in_array($r->status, ['draft', 'returned'], true)) {
            throw new \DomainException('Ce rapport est déjà envoyé : il ne se modifie plus.');
        }
        $row = array_intersect_key($d, array_flip(['summary', 'difficulties', 'next_steps']));
        if (array_key_exists('excluded', $d)) {
            $row['excluded'] = json_encode(array_values((array) $d['excluded']));
        }
        if ($r) {
            DB::table('ra_reports')->where('id', $r->id)->update($row + ['updated_at' => now()]);
        } else {
            DB::table('ra_reports')->insert($row + ['company_id' => $c->id, 'user_id' => $u->id, 'period_start' => $from, 'period_end' => $to, 'title' => $this->title($u, $from, $to),
                'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $this->find($c, $u, $from, $to);
    }

    public function send(Company $c, User $u, int $id, int $supervisorId, ?string $message): object
    {
        $r = DB::table('ra_reports')->where('company_id', $c->id)->where('id', $id)->where('user_id', $u->id)->first() ?: throw new \DomainException('Rapport introuvable.');
        if (! in_array($r->status, ['draft', 'returned'], true)) {
            throw new \DomainException('Rapport déjà envoyé.');
        }
        $sup = User::where('company_id', $c->id)->where('is_active', true)->find($supervisorId);
        if (! $sup || $sup->id === $u->id) {
            throw new \DomainException('Choisissez votre supérieur (une autre personne de la société).');
        }
        $items = array_values(array_filter($this->items($c, $r, $u, $r->period_start, $r->period_end), fn ($i) => $i['included'] ?? true));
        if (! $items && ! trim((string) $r->summary)) {
            throw new \DomainException('Le rapport est vide : ajoutez au moins une activité ou un résumé.');
        }
        DB::table('ra_reports')->where('id', $r->id)->update(['status' => 'sent', 'items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'supervisor_id' => $sup->id,
            'message' => $message, 'sent_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => now()]);
        if (Schema::hasColumn('users', 'supervisor_id')) {
            DB::table('users')->where('id', $u->id)->update(['supervisor_id' => $sup->id]);
        }
        $r = DB::table('ra_reports')->where('id', $r->id)->first();
        AppNotification::send($c->id, [$sup->id], 'activite', 'Rapport d\'activité reçu', $u->name.' — '.self::periodLabel($r->period_start, $r->period_end).' ('.count($items).' activité(s)).', 'activite', (string) $r->id, $u->id);
        $this->mail($c, $sup, $r, $u, 'Rapport d\'activité de '.$u->name.' — '.self::periodLabel($r->period_start, $r->period_end),
            $u->name.' vous a envoyé son rapport d\'activité'.($message ? ' : « '.$message.' »' : '.').' Il est joint en PDF et à valider dans Yomara ERP.');

        return $r;
    }

    public function review(Company $c, User $u, int $id, string $decision, ?string $comment): object
    {
        $r = DB::table('ra_reports')->where('company_id', $c->id)->where('id', $id)->first() ?: throw new \DomainException('Rapport introuvable.');
        if ((int) $r->supervisor_id !== $u->id && ! $u->hasPermission('activite.view_all')) {
            throw new \DomainException('Seul le supérieur destinataire valide ce rapport.');
        }
        if ($r->status !== 'sent') {
            throw new \DomainException('Ce rapport n\'attend pas de validation.');
        }
        if ($decision === 'returned' && ! trim((string) $comment)) {
            throw new \DomainException('Indiquez ce qu\'il faut compléter.');
        }
        DB::table('ra_reports')->where('id', $r->id)->update(['status' => $decision, 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'review_comment' => $comment, 'updated_at' => now()]);
        AppNotification::send($c->id, [$r->user_id], 'activite', $decision === 'approved' ? 'Rapport d\'activité validé' : 'Rapport d\'activité à compléter',
            self::periodLabel($r->period_start, $r->period_end).' — '.$u->name.($comment ? ' : '.$comment : '.'), 'activite', (string) $r->id, $u->id);

        return DB::table('ra_reports')->where('id', $r->id)->first();
    }

    protected function mail(Company $c, User $to, object $r, User $author, string $subject, string $text): void
    {
        if (! $to->email) {
            return;
        }
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1d2433"><p>Bonjour '.$e($to->name).',</p><p>'.$e($text).'</p>'
            .'<p><a href="'.$e(url('/app/')).'" style="display:inline-block;background:#1f6feb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Ouvrir Yomara ERP</a></p><p style="color:#888">'.$e($c->name).'</p></div>';
        rescue(fn () => $this->messaging->send($c, 'email', new Message($to->email, $subject, $html, $text,
            [['name' => Str::slug('rapport-'.$author->name.'-'.$r->period_start).'.pdf', 'data' => $this->pdf($c, $r), 'mime' => 'application/pdf']], $to->name), 'activite'), null, false);
    }

    // ------------------------------------------------------------------ restitution PDF / Word

    protected function money(?float $v, Company $c): string
    {
        return $v === null ? '' : number_format($v, 0, ',', ' ').' '.(in_array($c->currency, ['XOF', 'XAF', null], true) ? 'F CFA' : $c->currency);
    }

    protected function body(Company $c, object $r, bool $word): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $T = fn ($s) => Tr::t((string) $s);
        $author = User::find($r->user_id);
        $data = $this->toApi($c, $r, $author, $r->period_start, $r->period_end, $author);
        $items = array_values(array_filter($data['items'], fn ($i) => $i['included'] ?? true));
        $blue = '#1f6feb';
        $h = '<table width="100%" cellpadding="0"><tr><td width="60%"><b style="font-size:14pt">'.$e($c->name).'</b><br><span style="color:#666">'.$e(trim(($c->address ?? '').' '.($c->city ?? ''))).'</span></td>'
            .'<td width="40%" align="right"><b style="font-size:15pt;color:'.$blue.'">'.$e($T('RAPPORT D\'ACTIVITÉ')).'</b><br>'.$e($T(ucfirst($data['period_label']))).'</td></tr></table><br>'
            .'<table width="100%" cellpadding="6" style="border:0.3px solid #cfd6e4;border-collapse:collapse"><tr bgcolor="#f2f5fa">'
            .'<td width="50%"><span style="color:#666">'.$e($T('Rédigé par')).'</span><br><b>'.$e($author->name).'</b>'.($data['job'] ? '<br>'.$e($data['job']) : '').'</td>'
            .'<td width="50%"><span style="color:#666">'.$e($T('Destinataire')).'</span><br><b>'.$e($data['supervisor'] ?? '—').'</b>'.($r->sent_at ? '<br>'.$e($T('Envoyé le')).' '.$e(Carbon::parse($r->sent_at)->format('d/m/Y à H:i')) : '').'</td></tr></table><br>';
        $minutes = $data['stats']['minutes'];
        $h .= '<p><b>'.count($items).'</b> '.$e($T('activité(s) enregistrée(s)')).($minutes ? ' · <b>'.intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'</b> '.$e($T('de temps déclaré')) : '').'.</p>';
        if ($r->summary) {
            $h .= '<h3 style="color:'.$blue.'">'.$e($T('Synthèse')).'</h3><p>'.nl2br($e($r->summary)).'</p>';
        }
        foreach (collect($items)->groupBy('section') as $sec => $list) {
            $h .= '<h3 style="color:'.$blue.'">'.$e($T(ActivityCollector::SECTIONS[$sec] ?? $sec)).' ('.count($list).')</h3>'
                .'<table width="100%" cellpadding="4" border="0.3" style="border-collapse:collapse;border:0.3px solid #cfd6e4"><tr bgcolor="#1f2a44" style="color:#ffffff;font-weight:bold">'
                .'<td width="14%">'.$e($T('Date')).'</td><td width="'.($word ? '62' : '64').'%">'.$e($T('Activité')).'</td><td width="'.($word ? '24' : '22').'%" align="right">'.$e($T('Montant / durée')).'</td></tr>';
            foreach ($list as $i) {
                $val = $i['amount'] !== null && $i['amount'] != 0 ? $this->money((float) $i['amount'], $c) : ($i['minutes'] ? intdiv((int) $i['minutes'], 60).' h '.str_pad((string) ((int) $i['minutes'] % 60), 2, '0', STR_PAD_LEFT) : '');
                $h .= '<tr><td>'.$e(Carbon::parse($i['date'])->format('d/m')).'</td><td><b>'.$e($i['title']).'</b>'.($i['details'] ? '<br><span style="color:#555">'.nl2br($e($i['details'])).'</span>' : '').'</td><td align="right">'.$e($val).'</td></tr>';
            }
            $h .= '</table>';
        }
        if (! $items) {
            $h .= '<p style="color:#888">'.$e($T('Aucune activité enregistrée dans le logiciel sur la période.')).'</p>';
        }
        if ($r->difficulties) {
            $h .= '<h3 style="color:'.$blue.'">'.$e($T('Difficultés rencontrées')).'</h3><p>'.nl2br($e($r->difficulties)).'</p>';
        }
        if ($r->next_steps) {
            $h .= '<h3 style="color:'.$blue.'">'.$e($T('Priorités de la période suivante')).'</h3><p>'.nl2br($e($r->next_steps)).'</p>';
        }
        if ($r->review_comment) {
            $h .= '<h3 style="color:'.$blue.'">'.$e($T('Avis du supérieur')).'</h3><p>'.nl2br($e($r->review_comment)).'</p>';
        }
        $h .= '<br><br><table width="100%" cellpadding="6"><tr><td width="50%" style="border-top:0.3px solid #999">'.$e($author->name).'<br><br><br></td>'
            .'<td width="50%" style="border-top:0.3px solid #999">Visa du supérieur'.($r->status === 'approved' ? ' — '.$e($T('validé le')).' '.$e(Carbon::parse($r->reviewed_at)->format('d/m/Y')) : '').'<br><br><br></td></tr></table>';

        return $h;
    }

    public function pdf(Company $c, object $r): string
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Yomara ERP');
        $pdf->SetTitle($r->title);
        $pdf->setPrintHeader(false);
        $pdf->setFooterFont(['dejavusans', '', 7]);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->writeHTML($this->body($c, $r, false), true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    /** Document Word (HTML compatible Word, s'ouvre et se modifie dans Word ou LibreOffice). */
    public function word(Company $c, object $r): string
    {
        return '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"><title>'
            .htmlspecialchars($r->title).'</title><!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->'
            .'<style>body{font-family:Calibri,Arial,sans-serif;font-size:10.5pt} h3{font-size:12pt;margin:14pt 0 4pt} td{font-size:9.5pt;vertical-align:top} @page{size:21cm 29.7cm;margin:1.8cm}</style></head><body>'
            .$this->body($c, $r, true).'</body></html>';
    }

    // ------------------------------------------------------------------ préparation automatique

    /** Le vendredi : brouillon prêt pour chaque personne active ; le lundi : rappel des rapports non envoyés. */
    public function weekly(Company $c, bool $remind = false): int
    {
        $n = 0;
        [$from, $to] = $remind ? self::week(now()->subWeek()->startOfWeek()->toDateString()) : self::week();
        foreach (User::where('company_id', $c->id)->where('is_active', true)->get() as $u) {
            if (! $u->hasPermission('activite.use')) {
                continue;
            }
            $r = $this->find($c, $u, $from, $to);
            if ($remind) {
                if ($r && in_array($r->status, ['draft', 'returned'], true) && ! $r->reminded_at) {
                    AppNotification::send($c->id, [$u->id], 'activite', 'Rapport de la semaine dernière pas encore envoyé', 'Relisez-le et envoyez-le à votre supérieur.', 'activite', (string) $r->id);
                    DB::table('ra_reports')->where('id', $r->id)->update(['reminded_at' => now()]);
                    $n++;
                }

                continue;
            }
            if ($r || ! $this->collector->collect($c, $u, $from, $to)) {
                continue;
            }
            $r = $this->save($c, $u, $from, $to, []);
            AppNotification::send($c->id, [$u->id], 'activite', 'Votre rapport de la semaine est prêt', 'Il a été rédigé à partir de vos activités dans le logiciel : relisez, complétez et envoyez-le à votre supérieur.', 'activite', (string) $r->id);
            $n++;
        }

        return $n;
    }
}
