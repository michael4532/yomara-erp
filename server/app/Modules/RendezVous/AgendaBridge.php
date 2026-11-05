<?php

namespace App\Modules\RendezVous;

use App\Models\Company;
use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Recurrence;
use App\Modules\RendezVous\Models\Appointment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rendez-vous ↔ Agenda : chaque rendez-vous d'un praticien relié à un utilisateur apparaît dans son Agenda
 * (et donc dans son Outlook / Google Agenda s'il a branché sa boîte mail) ; à l'inverse, ses événements
 * d'agenda (réunions, congés, rendez-vous pris dans Outlook ou Google) bloquent les créneaux de réservation.
 */
class AgendaBridge
{
    public static function enabled(Company $c, string $key): bool
    {
        return $c->hasModule('agenda') && Schema::hasTable('ag_events') && (bool) (($c->settings ?? [])[$key] ?? true);
    }

    public function sync(Appointment $a): void
    {
        $c = Company::find($a->company_id);
        if (! $c || ! self::enabled($c, 'rdv_agenda_sync') || ! Schema::hasColumn('rdv_appointments', 'agenda_event_id')) {
            return;
        }
        $userId = $a->staff_id ? DB::table('rdv_staff')->where('id', $a->staff_id)->value('user_id') : null;
        $event = $a->agenda_event_id ? Event::withoutGlobalScopes()->withTrashed()->find($a->agenda_event_id) : null;
        if (! $userId || ! in_array($a->status, Appointment::BUSY, true) || $a->trashed()) {
            if ($event && ! $event->trashed()) {
                $event->delete();
            }

            return;
        }
        $service = $a->service_id ? DB::table('rdv_services')->where('id', $a->service_id)->value('name') : null;
        $event ??= new Event;
        if ($event->trashed()) {
            $event->restore();
        }
        $event->forceFill([
            'id' => $event->id ?: (string) Str::uuid(), 'company_id' => $a->company_id, 'owner_id' => $userId,
            'title' => Str::limit('RDV '.($service ? $service.' · ' : '').($a->customer_name ?: 'client'), 250, ''),
            'description' => trim(implode("\n", array_filter([$a->number ? 'Rendez-vous '.$a->number : null, $a->customer_phone ? 'Tél. '.$a->customer_phone : null,
                $a->customer_email, $a->status === 'pending' ? 'À confirmer' : null, $a->notes]))),
            'starts_at' => $a->starts_at, 'ends_at' => $a->ends_at, 'all_day' => false, 'category' => 'appointment', 'visibility' => 'private', 'status' => 'confirmed',
        ]);
        $event->save();
        if ($a->agenda_event_id !== $event->id) {
            $a->forceFill(['agenda_event_id' => $event->id])->saveQuietly();
        }
    }

    /**
     * Périodes occupées dans l'Agenda des utilisateurs (UTC), hors événements créés pour les rendez-vous.
     *
     * @return array<int, list<array{0: Carbon, 1: Carbon}>> user_id => périodes
     */
    public function busy(Company $c, array $userIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (! $userIds || ! self::enabled($c, 'rdv_agenda_busy')) {
            return [];
        }
        $own = Schema::hasColumn('rdv_appointments', 'agenda_event_id')
            ? DB::table('rdv_appointments')->where('company_id', $c->id)->whereNotNull('agenda_event_id')->pluck('agenda_event_id')->flip() : collect();
        $rows = DB::table('ag_events')->where('company_id', $c->id)->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereNotNull('recurrence')->orWhere(fn ($w) => $w->where('starts_at', '<', $to->toDateTimeString())->where('ends_at', '>', $from->toDateTimeString())))
            ->get(['id', 'owner_id', 'attendee_ids', 'responses', 'starts_at', 'ends_at', 'all_day', 'recurrence', 'exceptions']);
        $out = [];
        foreach ($rows as $e) {
            if (isset($own[$e->id])) {
                continue;
            }
            $att = array_map('intval', (array) json_decode((string) $e->attendee_ids, true));
            $resp = (array) json_decode((string) $e->responses, true);
            $who = array_filter($userIds, fn ($u) => (int) $e->owner_id === $u || (in_array($u, $att, true) && ($resp[$u] ?? $resp[(string) $u] ?? null) !== 'declined'));
            if (! $who) {
                continue;
            }
            $s = Carbon::parse($e->starts_at, 'UTC');
            $en = Carbon::parse($e->ends_at, 'UTC');
            if ($e->all_day) {
                $en = $en->copy()->max($s->copy()->endOfDay());
            }
            $rule = $e->recurrence ? json_decode((string) $e->recurrence, true) : null;
            foreach (Recurrence::occurrences($s, $en, $rule ?: null, (array) json_decode((string) $e->exceptions, true), $from, $to, 200) as [$os, $oe]) {
                foreach ($who as $u) {
                    $out[$u][] = [Carbon::instance($os)->utc(), Carbon::instance($oe)->utc()];
                }
            }
        }

        return $out;
    }
}
