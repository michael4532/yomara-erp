<?php

namespace App\Modules\Visio;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Modules\Visio\Models\Meeting;
use App\Modules\Visio\Models\Participant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Tâche planifiée (toutes les 5 min) : rappels des webinaires, clôture des salles abandonnées, comptes rendus, nettoyage. */
class Housekeeping
{
    public function __construct(protected VisioService $svc, protected ReportService $reports) {}

    public function run(): array
    {
        $out = ['reminders' => 0, 'closed' => 0, 'reports' => 0];
        $out['reminders'] = $this->reminders();
        // Salle « en cours » sans personne depuis 10 minutes : terminée, compte rendu produit et envoyé
        $stale = Meeting::withoutGlobalScopes()->where('status', 'live')->get()->filter(function ($m) {
            $last = Participant::where('meeting_id', $m->id)->max('last_seen_at');

            return ! $last || now()->diffInMinutes(\Illuminate\Support\Carbon::parse($last), true) >= 10;
        });
        foreach ($stale as $m) {
            $c = Company::find($m->company_id);
            if (! $c) {
                continue;
            }
            $this->svc->end($c, $m);
            $out['closed']++;
        }
        // Réunions terminées sans compte rendu (fin par abandon, ou génération interrompue)
        $pending = Meeting::withoutGlobalScopes()->where('status', 'ended')->where('ended_at', '>=', now()->subDays(2))->whereNotNull('started_at')
            ->whereNotIn('id', DB::table('vis_reports')->select('meeting_id'))->limit(20)->get();
        foreach ($pending as $m) {
            $c = Company::find($m->company_id);
            if (! $c || ! $c->hasModule('visio')) {
                continue;
            }
            try {
                $this->reports->generate($c, $m);
                if ($m->opt('send_report', true)) {
                    $this->reports->send($c, $m, true, $m->host_id);
                }
                $out['reports']++;
            } catch (\Throwable $e) {
                Log::warning('Visio : compte rendu de la réunion '.$m->id.' impossible : '.$e->getMessage());
            }
        }
        // Signalisation et signaux de santé : inutiles après coup
        DB::table('vis_feed')->whereIn('kind', ['signal', 'health', 'control'])->where('created_at', '<', now()->subDay())->delete();

        return $out;
    }

    /** Rappels des webinaires : la veille (24 h) et une heure avant. */
    protected function reminders(): int
    {
        $sent = 0;
        $windows = ['24h' => [now()->addHours(23), now()->addHours(25)], '1h' => [now()->addMinutes(45), now()->addMinutes(75)]];
        foreach ($windows as $key => [$from, $to]) {
            $list = Meeting::withoutGlobalScopes()->where('type', 'webinar')->where('status', 'scheduled')->whereBetween('starts_at', [$from, $to])->get();
            foreach ($list as $m) {
                if (! empty(($m->reminders_sent ?? [])[$key])) {
                    continue;
                }
                $c = Company::find($m->company_id);
                $messaging = app(MessagingService::class);
                if (! $c || ! $c->hasModule('visio')) {
                    continue;
                }
                $mail = (bool) ($messaging->available($c)['email'] ?? null);
                foreach ($mail ? Participant::where('meeting_id', $m->id)->whereNotNull('email')->whereIn('role', ['attendee', 'panelist', 'cohost'])->get() : [] as $p) {
                    $this->remind($c, $m, $p, $key, $messaging) && $sent++;
                }
                $m->forceFill(['reminders_sent' => array_merge($m->reminders_sent ?? [], [$key => now()->toDateTimeString()])])->save();
            }
        }

        return $sent;
    }

    protected function remind(Company $c, Meeting $m, Participant $p, string $key, MessagingService $messaging): bool
    {
        $when = $m->starts_at->locale('fr')->isoFormat($key === '24h' ? '[demain à] HH:mm' : '[aujourd\'hui à] HH:mm');
        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;line-height:1.55;color:#1d2433"><p>Bonjour '.e($p->name).',</p>'
            .'<p>Rappel : le webinaire <b>« '.e($m->title).' »</b> commence '.e($when).'.</p>'
            .'<p style="margin:24px 0"><a href="'.e($m->roomUrl($p->token)).'" style="background:#2563EB;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Rejoindre le webinaire</a></p>'
            .'<p style="color:#94A3B8;font-size:11px">'.e($c->name).'</p></div>';
        try {
            return $messaging->send($c, 'email', new Message(to: $p->email, toName: $p->name, subject: ($key === '24h' ? 'Demain : ' : 'Dans une heure : ').$m->title, html: $html), 'visio')->ok;
        } catch (\Throwable $e) {
            Log::warning('Webinaire : rappel non envoyé : '.$e->getMessage());

            return false;
        }
    }
}
