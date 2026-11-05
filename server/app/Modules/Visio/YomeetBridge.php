<?php

namespace App\Modules\Visio;

use App\Models\Company;
use App\Modules\Visio\Models\Meeting;
use Illuminate\Support\Str;

/**
 * Yomeet dans l'agenda : un événement « Yomeet » crée (ou met à jour) sa salle de visioconférence intégrée à l'ERP,
 * avec son lien, son identifiant de réunion et son code d'accès à partager — comme Zoom ou Teams, sans quitter l'ERP.
 */
class YomeetBridge
{
    public const OPTIONS = ['lobby', 'mute_on_entry', 'guests', 'record_auto', 'ai_report', 'chat', 'screen', 'transcribe'];

    public static function sync(Company $c, $event): void
    {
        if (! $c->hasModule('visio')) {
            return;
        }
        $y = (array) $event->yomeet;
        $svc = app(VisioService::class);
        $meeting = ! empty($y['meeting_id']) ? Meeting::withoutGlobalScopes()->where('company_id', $c->id)->find($y['meeting_id']) : null;
        if (empty($y['enabled'])) {
            if ($meeting && $meeting->status === 'scheduled') {
                $meeting->forceFill(['status' => 'cancelled'])->save();
            }

            return;
        }
        $meeting ??= new Meeting;
        $isNew = ! $meeting->exists;
        $start = $event->starts_at;
        $minutes = max(5, (int) $start->diffInMinutes($event->ends_at ?? $start->copy()->addHour()));
        $options = array_merge($svc->settings($c)['defaults'], (array) ($meeting->options ?? []), array_intersect_key(array_map(fn ($v) => (bool) $v, $y), array_flip(self::OPTIONS)));
        $password = array_key_exists('password', $y) ? (trim((string) $y['password']) ?: null) : ($meeting->password ?? (string) random_int(100000, 999999));
        $meeting->fill([
            'type' => 'meeting', 'title' => $event->title, 'description' => $event->description, 'starts_at' => $start, 'duration_minutes' => min(720, $minutes),
            'options' => $options, 'password' => $password, 'host_id' => $event->owner_id,
            'status' => $event->status === 'cancelled' ? 'cancelled' : ($meeting->status === 'live' ? 'live' : ($meeting->status === 'ended' ? 'ended' : 'scheduled')),
        ]);
        if ($isNew) {
            $meeting->code = VisioService::code();
        }
        $meeting->forceFill(['company_id' => $c->id, 'agenda_event_id' => $event->id]);
        $meeting->save();
        $users = array_values(array_unique(array_filter(array_merge([(int) $event->owner_id], array_map('intval', (array) $event->attendee_ids)))));
        $guests = array_values(array_filter((array) $event->guests, fn ($g) => ! empty($g['name']) || ! empty($g['email'])));
        $svc->syncInvitees($meeting, $users, $guests);
        if ($event->owner_id && ($host = \App\Models\User::find($event->owner_id))) {
            $svc->participantFor($meeting, $host, 'host');
        }
        $y = array_merge($y, ['meeting_id' => $meeting->id, 'code' => $meeting->code, 'url' => $meeting->roomUrl(), 'password' => $meeting->password]);
        $event->forceFill([
            'online_link' => $meeting->roomUrl(),
            'location' => $event->location ?: 'Yomeet — visio intégrée',
            'yomeet' => $y,
        ]);
        if ($event->isDirty()) {
            $event->save();
        }
    }

    /** Événement supprimé : la salle Yomeet planifiée est annulée. */
    public static function cancel(Company $c, $event): void
    {
        $id = ((array) $event->yomeet)['meeting_id'] ?? null;
        if ($id && ($m = Meeting::withoutGlobalScopes()->where('company_id', $c->id)->find($id)) && $m->status === 'scheduled') {
            $m->forceFill(['status' => 'cancelled'])->save();
        }
    }

    /** Texte d'invitation à copier (comme Zoom / Teams). */
    public static function invitation(Meeting $m): string
    {
        return "Réunion Yomeet : {$m->title}\n".($m->starts_at ? 'Le '.$m->starts_at->format('d/m/Y à H:i')."\n" : '')
            ."Rejoindre : {$m->roomUrl()}\nID de réunion : ".Str::upper($m->code)."\n".($m->password ? "Code d'accès : {$m->password}\n" : '');
    }
}
