<?php

namespace App\Modules\Agenda;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/** Occurrences d'un événement récurrent (quotidien, hebdomadaire par jours, mensuel, annuel ; fin par date ou nombre). */
class Recurrence
{
    public const FREQS = ['daily', 'weekly', 'monthly', 'yearly'];

    /**
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    public static function occurrences(CarbonInterface $start, CarbonInterface $end, ?array $rule, array $exceptions, CarbonInterface $from, CarbonInterface $to, int $max = 1000): array
    {
        $start = Carbon::instance($start);
        $duration = max(0, $end->getTimestamp() - $start->getTimestamp());
        $out = [];
        $push = function (Carbon $s) use (&$out, $duration, $from, $to, $exceptions) {
            if (in_array($s->toDateString(), $exceptions, true)) {
                return;
            }
            $e = $s->copy()->addSeconds($duration);
            if ($s->lt($to) && ($e->gt($from) || ($duration === 0 && $s->gte($from)))) {
                $out[] = [$s, $e];
            }
        };
        if (! $rule || ! in_array($rule['freq'] ?? null, self::FREQS, true)) {
            $push($start);

            return $out;
        }
        $interval = max(1, min(99, (int) ($rule['interval'] ?? 1)));
        $until = ! empty($rule['until']) ? Carbon::parse($rule['until'])->endOfDay() : null;
        $count = ! empty($rule['count']) ? max(1, (int) $rule['count']) : null;
        $limit = $until && $until->lt($to) ? $until : $to;
        $n = 0;
        $guard = 0;
        $emit = function (Carbon $s) use (&$n, $count, $push, $limit): bool {
            if ($s->gt($limit) || ($count && $n >= $count)) {
                return false;
            }
            $n++;
            $push($s);

            return true;
        };
        $freq = $rule['freq'];
        if ($freq === 'weekly') {
            $days = array_values(array_unique(array_filter(array_map('intval', (array) ($rule['byday'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
            sort($days);
            if (! $days) {
                $days = [$start->dayOfWeekIso];
            }
            $week = $start->copy()->startOfWeek(CarbonInterface::MONDAY);
            while ($guard++ < 5000 && count($out) < $max) {
                foreach ($days as $d) {
                    $s = $week->copy()->addDays($d - 1)->setTime($start->hour, $start->minute, $start->second);
                    if ($s->lt($start)) {
                        continue;
                    }
                    if (! $emit($s)) {
                        return $out;
                    }
                }
                $week->addWeeks($interval);
            }

            return $out;
        }
        for ($i = 0; $guard++ < 5000 && count($out) < $max; $i++) {
            $s = match ($freq) {
                'daily' => $start->copy()->addDays($i * $interval),
                'monthly' => $start->copy()->addMonthsNoOverflow($i * $interval),
                default => $start->copy()->addYearsNoOverflow($i * $interval),
            };
            if (in_array($freq, ['monthly', 'yearly'], true) && $s->day !== $start->day) {
                continue; // le 31 n'existe pas ce mois-ci : pas d'occurrence
            }
            if (! $emit($s)) {
                break;
            }
        }

        return $out;
    }

    /** Règle iCalendar (RRULE) pour l'abonnement Google / Outlook. */
    public static function rrule(?array $rule): ?string
    {
        if (! $rule || ! in_array($rule['freq'] ?? null, self::FREQS, true)) {
            return null;
        }
        $p = ['FREQ='.strtoupper($rule['freq'])];
        if (($rule['interval'] ?? 1) > 1) {
            $p[] = 'INTERVAL='.(int) $rule['interval'];
        }
        if ($rule['freq'] === 'weekly' && ! empty($rule['byday'])) {
            $map = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];
            $p[] = 'BYDAY='.implode(',', array_map(fn ($d) => $map[(int) $d] ?? 'MO', (array) $rule['byday']));
        }
        if (! empty($rule['until'])) {
            $p[] = 'UNTIL='.Carbon::parse($rule['until'])->format('Ymd').'T235959Z';
        } elseif (! empty($rule['count'])) {
            $p[] = 'COUNT='.(int) $rule['count'];
        }

        return implode(';', $p);
    }
}
