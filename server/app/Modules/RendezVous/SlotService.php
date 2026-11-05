<?php

namespace App\Modules\RendezVous;

use App\Models\Company;
use App\Modules\RendezVous\Models\Appointment;
use App\Modules\RendezVous\Models\Closure;
use App\Modules\RendezVous\Models\Service;
use App\Modules\RendezVous\Models\Staff;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/** Calcul des créneaux libres : horaires de l'équipe − fermetures − rendez-vous existants. */
class SlotService
{
    public function settings(Company $company): array
    {
        $s = $company->settings ?? [];

        return [
            'tz' => $s['timezone'] ?? config('erp.defaults.timezone', 'UTC'),
            'step' => max(5, (int) ($s['rdv_slot_step'] ?? 30)),
            'notice' => max(0, (float) ($s['rdv_min_notice_hours'] ?? 2)),
            'max_days' => max(1, (int) ($s['rdv_max_days'] ?? 60)),
            'cancel_notice' => max(0, (float) ($s['rdv_cancel_notice_hours'] ?? 4)),
            'reminder' => max(0, (float) ($s['rdv_reminder_hours'] ?? 24)),
            'auto_confirm' => (bool) ($s['rdv_auto_confirm'] ?? true),
            'enabled' => (bool) ($s['rdv_enabled'] ?? true),
            'agenda_sync' => (bool) ($s['rdv_agenda_sync'] ?? true),
            'agenda_busy' => (bool) ($s['rdv_agenda_busy'] ?? true),
        ];
    }

    /** Membres qui peuvent assurer la prestation. */
    public function staffFor(Service $service, bool $online, ?string $staffId = null)
    {
        return Staff::where('active', true)->when($online, fn ($q) => $q->where('online', true))->when($staffId, fn ($q) => $q->whereKey($staffId))
            ->orderBy('sort')->orderBy('name')->get()->filter(fn ($s) => $service->allowsStaff($s->id))->values();
    }

    /**
     * Créneaux d'une journée (date locale Y-m-d) : [['time' => '09:30', 'starts_at' => UTC, 'staff_ids' => [...]], …]
     *
     * @param  string|null  $ignore  rendez-vous à ignorer (déplacement)
     */
    public function slots(Company $company, Service $service, string $date, ?string $staffId = null, bool $online = true, ?string $ignore = null): array
    {
        $cfg = $this->settings($company);
        $tz = $cfg['tz'];
        $day = Carbon::parse($date, $tz)->startOfDay();
        $now = Carbon::now($tz);
        if ($online && ($day->lt($now->copy()->startOfDay()) || $day->gt($now->copy()->addDays($cfg['max_days'])))) {
            return [];
        }
        $earliest = $online ? $now->copy()->addMinutes((int) round($cfg['notice'] * 60)) : $now->copy()->startOfDay();
        $dur = $service->duration_minutes;
        $buffer = $service->buffer_minutes;
        $dayStartUtc = $day->copy()->utc();
        $dayEndUtc = $day->copy()->endOfDay()->utc();
        $staff = $this->staffFor($service, $online, $staffId);
        if ($staff->isEmpty()) {
            return [];
        }
        $busy = Appointment::whereIn('staff_id', $staff->pluck('id'))->whereIn('status', Appointment::BUSY)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))
            ->where('starts_at', '<', $dayEndUtc->toDateTimeString())->where('ends_at', '>', $dayStartUtc->toDateTimeString())->get()->groupBy('staff_id');
        $closures = Closure::where('starts_at', '<', $dayEndUtc->toDateTimeString())->where('ends_at', '>', $dayStartUtc->toDateTimeString())->get();
        // Agenda des praticiens (réunions, congés, événements Outlook / Google) : créneaux bloqués
        $agenda = app(AgendaBridge::class)->busy($company, $staff->pluck('user_id')->filter()->all(), $dayStartUtc, $dayEndUtc);
        $slots = [];
        foreach ($staff as $st) {
            $blocked = collect($busy[$st->id] ?? [])->map(fn ($a) => [$a->starts_at->copy()->utc(), $a->ends_at->copy()->utc()])
                ->merge($closures->filter(fn ($c) => ! $c->staff_id || $c->staff_id === $st->id)->map(fn ($c) => [$c->starts_at->copy()->utc(), $c->ends_at->copy()->utc()]))
                ->merge($st->user_id ? ($agenda[(int) $st->user_id] ?? []) : []);
            foreach ($st->rangesFor($day->dayOfWeekIso) as [$from, $to]) {
                $t = Carbon::parse($day->toDateString().' '.$from, $tz);
                $limit = Carbon::parse($day->toDateString().' '.$to, $tz);
                for (; $t->copy()->addMinutes($dur)->lte($limit); $t->addMinutes($cfg['step'])) {
                    if ($t->lt($earliest)) {
                        continue;
                    }
                    $s = $t->copy()->utc();
                    $e = $s->copy()->addMinutes($dur + $buffer);
                    if ($blocked->contains(fn ($b) => $b[0]->lt($e) && $b[1]->gt($s))) {
                        continue;
                    }
                    $key = $t->format('H:i');
                    $slots[$key] ??= ['time' => $key, 'starts_at' => $s->toIso8601String(), 'staff_ids' => []];
                    $slots[$key]['staff_ids'][] = $st->id;
                }
            }
        }
        ksort($slots);

        return array_values($slots);
    }

    /** Jours ayant au moins un créneau libre, à partir d'aujourd'hui. */
    public function days(Company $company, Service $service, int $count = 14, ?string $staffId = null, ?string $from = null): array
    {
        $cfg = $this->settings($company);
        $start = $from ? Carbon::parse($from, $cfg['tz']) : Carbon::now($cfg['tz']);
        $out = [];
        foreach (CarbonPeriod::create($start->copy()->startOfDay(), $start->copy()->addDays($count - 1)->startOfDay()) as $d) {
            $n = count($this->slots($company, $service, $d->toDateString(), $staffId));
            $out[] = ['date' => $d->toDateString(), 'count' => $n];
        }

        return $out;
    }
}
