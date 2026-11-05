<?php

namespace App\Modules\Tickets;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Tickets\Models\Department;
use App\Modules\Tickets\Models\Member;
use App\Modules\Tickets\Models\Report;
use Carbon\Carbon;

/** Rapports automatiques (hebdomadaire, mensuel) : un par service et un pour toute l'entreprise. */
class TicketReports
{
    public function __construct(protected TicketStats $stats) {}

    public static function lastPeriod(string $period, ?Carbon $now = null): array
    {
        $now ??= now();

        return $period === 'month'
            ? [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()]
            : [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()];
    }

    /** @return Report[] */
    public function generate(Company $company, string $period, ?Carbon $from = null, ?Carbon $to = null): array
    {
        app()->instance('tenant', $company);
        [$f, $t] = $from && $to ? [$from, $to] : self::lastPeriod($period);
        $out = [];

        $all = $this->stats->compute(null, $f, $t);
        $out[] = $r = Report::updateOrCreate(
            ['company_id' => $company->id, 'department_id' => null, 'period' => $period, 'from' => $f->toDateString()],
            ['to' => $t->toDateString(), 'data' => $all, 'summary' => TicketStats::summary($all, 'Toute l\'entreprise')],
        );
        $direction = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('tickets.view_all'))->pluck('id');
        AppNotification::send($company->id, $direction, 'ticket_report', 'Rapport des demandes internes', strtok($r->summary, "\n")."\n".$all['kpi']['received'].' reçues, '.$all['kpi']['done'].' traitées.', 'tickets', 'report:'.$r->id);

        foreach (Department::where('active', true)->get() as $d) {
            $s = $this->stats->compute([$d->id], $f, $t);
            if ($s['kpi']['received'] === 0 && $s['kpi']['backlog'] === 0) {
                continue;
            }
            $out[] = $r = Report::updateOrCreate(
                ['company_id' => $company->id, 'department_id' => $d->id, 'period' => $period, 'from' => $f->toDateString()],
                ['to' => $t->toDateString(), 'data' => $s, 'summary' => TicketStats::summary($s, $d->name)],
            );
            $heads = Member::where('department_id', $d->id)->whereIn('role', ['head', 'deputy'])->pluck('user_id')
                ->push($d->head_user_id)->merge((array) $d->supervisor_ids);
            AppNotification::send($company->id, $heads, 'ticket_report', 'Rapport '.$d->name, $s['kpi']['received'].' reçues, '.$s['kpi']['done'].' traitées, '.$s['kpi']['late'].' en retard.', 'tickets', 'report:'.$r->id);
        }

        return $out;
    }
}
