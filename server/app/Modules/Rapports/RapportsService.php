<?php

namespace App\Modules\Rapports;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Models\User;
use App\Modules\Rapports\Models\Board;
use App\Modules\Rapports\Models\Report;
use App\Modules\Rapports\Models\Schedule;
use Illuminate\Support\Carbon;

class RapportsService
{
    public function __construct(private ReportEngine $engine, private Exporter $exporter) {}

    public function runReport(Company $company, $user, Report $r, array $override = []): array
    {
        if (! $r->isVisibleTo($user)) {
            throw new AccessException('Ce rapport n\'est pas partagé avec vous.');
        }

        return $this->engine->run($company, $user, $r->source, $r->config ?? [], $override) + ['report' => ['id' => $r->id, 'name' => $r->name]];
    }

    /** Tous les widgets d'un tableau de bord en un appel ; une erreur n'empêche pas les autres. */
    public function runBoard(Company $company, $user, Board $b, array $override = []): array
    {
        if (! $b->isVisibleTo($user)) {
            throw new AccessException('Ce tableau de bord n\'est pas partagé avec vous.');
        }
        if (empty($override['period']) && $b->period) {
            $override = ['period' => $b->period];
        }
        $reports = Report::whereIn('id', array_column($b->widgets ?? [], 'report_id'))->get()->keyBy('id');
        $out = [];
        foreach ($b->widgets ?? [] as $w) {
            $r = $reports->get($w['report_id']);
            try {
                if (! $r) {
                    throw new \DomainException('Rapport supprimé.');
                }
                $out[$w['id']] = ['ok' => true, 'title' => $w['title'] ?? $r->name, 'result' => $this->runReport($company, $user, $r, $override)];
            } catch (\DomainException $e) {
                $out[$w['id']] = ['ok' => false, 'title' => $w['title'] ?? $r?->name ?? 'Rapport', 'error' => $e->getMessage(), 'forbidden' => $e instanceof AccessException];
            }
        }

        return ['dashboard' => ['id' => $b->id, 'name' => $b->name], 'widgets' => $out, 'generated_at' => now()->toIso8601String()];
    }

    public function boardPdf(Company $company, $user, Board $b, array $override = []): string
    {
        $run = $this->runBoard($company, $user, $b, $override);
        $sections = [];
        foreach ($run['widgets'] as $w) {
            $sections[] = $w['ok'] ? ['title' => $w['title'], 'result' => $w['result']] : ['title' => $w['title'], 'error' => $w['error']];
        }

        return $this->exporter->pdf($company, $b->name, $sections, 'Tableau de bord');
    }

    public static function slug(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(\Illuminate\Support\Str::ascii($s))), '_') ?: 'rapport';
    }

    // ---------------------------------------------------------------- envois programmés

    public function nextRun(Schedule $s, ?Carbon $after = null): Carbon
    {
        $after ??= now();
        $d = $after->copy()->setTime($s->hour, 0);
        $d = match ($s->frequency) {
            'weekly' => $d->startOfWeek()->addDays(max(1, min(7, $s->weekday)) - 1)->setTime($s->hour, 0),
            'monthly' => $d->startOfMonth()->addDays(max(1, min(28, $s->monthday)) - 1)->setTime($s->hour, 0),
            default => $d,
        };
        while ($d->lte($after)) {
            $d = match ($s->frequency) {
                'weekly' => $d->addWeek(),
                'monthly' => $d->addMonthNoOverflow(),
                default => $d->addDay(),
            };
        }

        return $d;
    }

    public function runDue(Company $company): array
    {
        $out = ['sent' => 0, 'failed' => 0];
        foreach (Schedule::where('active', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->get() as $s) {
            $ok = $this->send($company, $s);
            $out[$ok ? 'sent' : 'failed']++;
            $s->forceFill(['next_run_at' => $this->nextRun($s)])->save();
        }

        return $out;
    }

    /** Envoi immédiat (avec les droits de l'auteur du programme). */
    public function send(Company $company, Schedule $s): bool
    {
        $fail = function (string $msg) use ($s) {
            $s->forceFill(['last_status' => 'failed', 'last_error' => mb_substr($msg, 0, 250)])->save();

            return false;
        };
        $owner = User::where('company_id', $company->id)->find($s->owner_id);
        if (! $owner || ! $owner->is_active) {
            return $fail('L\'auteur de cet envoi n\'a plus accès à l\'application.');
        }
        if (! ($this->messaging()->available($company)['email'] ?? null)) {
            return $fail('Aucun serveur d\'e-mail configuré (Paramètres › Messagerie).');
        }
        $override = $s->period ? ['period' => $s->period] : [];
        try {
            if ($s->report_id) {
                $r = Report::find($s->report_id) ?? throw new \DomainException('Rapport supprimé.');
                $res = $this->runReport($company, $owner, $r, $override);
                $title = $r->name;
                $body = $this->exporter->html($r->name, $res);
                if ($s->format === 'xlsx') {
                    $path = tempnam(sys_get_temp_dir(), 'rp').'.xlsx';
                    $this->exporter->xlsx($res, $path);
                    $attach = ['data' => file_get_contents($path), 'name' => self::slug($r->name).'.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                    @unlink($path);
                } else {
                    $attach = ['data' => $this->exporter->pdf($company, $r->name, [['title' => $r->name, 'result' => $res]]), 'name' => self::slug($r->name).'.pdf', 'mime' => 'application/pdf'];
                }
            } else {
                $b = Board::find($s->dashboard_id) ?? throw new \DomainException('Tableau de bord supprimé.');
                $run = $this->runBoard($company, $owner, $b, $override);
                $title = $b->name;
                $body = '';
                foreach ($run['widgets'] as $w) {
                    $body .= $w['ok'] ? $this->exporter->html($w['title'], $w['result']) : '<h3 style="margin:18px 0 4px">'.e($w['title']).'</h3><div style="color:#D93025">'.e($w['error']).'</div>';
                }
                $attach = ['data' => $this->boardPdf($company, $owner, $b, $override), 'name' => self::slug($b->name).'.pdf', 'mime' => 'application/pdf'];
            }
        } catch (\DomainException $e) {
            return $fail($e->getMessage());
        }

        $intro = $s->message ? '<p>'.nl2br(e($s->message)).'</p>' : '<p>Bonjour,</p><p>Voici le rapport « '.e($title).' » ('.e(mb_strtolower(Schedule::FREQUENCIES[$s->frequency])).').</p>';
        $html = '<div style="font-family:Arial,sans-serif;max-width:720px;margin:auto;line-height:1.45;color:#1d2433">'.$intro.$body
            .'<p style="color:#5B6B85;font-size:11px;margin-top:22px">Envoi programmé par '.e($owner->name).' depuis Yomara ERP — '.e($company->name).'.</p></div>';
        $errors = [];
        foreach ($s->recipients ?? [] as $to) {
            try {
                $res = $this->messaging()->send($company, 'email', new Message(to: $to, subject: $title.' — '.$company->name, html: $html, attachments: [$attach]), 'rapport', $owner->id);
                if (! $res->ok) {
                    $errors[] = $to.' : '.($res->error ?? 'échec');
                }
            } catch (\Throwable $e) {
                $errors[] = $to.' : '.$e->getMessage();
            }
        }
        if (count($errors) === count($s->recipients ?? [])) {
            return $fail(implode(' ; ', $errors) ?: 'Aucun destinataire.');
        }
        $s->forceFill(['last_status' => $errors ? 'partial' : 'sent', 'last_error' => $errors ? mb_substr(implode(' ; ', $errors), 0, 250) : null, 'last_sent_at' => now()])->save();

        return true;
    }

    private function messaging(): MessagingService
    {
        return app(MessagingService::class);
    }
}
