<?php

namespace App\Modules\Mailbox;

use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Recurrence;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Protocol\MailProtocolException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Agenda Yomara ↔ calendrier Outlook (Microsoft Graph) ou Google Agenda, dans les deux sens.
 * Les événements de l'utilisateur partent vers son calendrier ; ceux de son calendrier arrivent dans l'Agenda.
 * Lieu « teams » → réunion Microsoft Teams créée ; « meet » → Google Meet créé ; le lien remplace le mot-clé.
 */
class CalendarSync
{
    public function sync(Account $a): array
    {
        if (! $a->user_id || ! \Illuminate\Support\Facades\Schema::hasTable('ag_events')) {
            return [];
        }
        $pulled = $a->provider === 'microsoft' ? $this->pullGraph($a) : $this->pullGoogle($a);
        $pushed = $this->push($a);

        return ['pulled' => $pulled, 'pushed' => $pushed];
    }

    protected function graph(Account $a, string $method, string $url, array $body = [])
    {
        $url = str_starts_with($url, 'https://') ? $url : 'https://graph.microsoft.com/v1.0/me'.$url;
        $r = Http::withToken(OAuth::token($a))->acceptJson()->timeout(30)
            ->withHeaders(['Prefer' => 'outlook.timezone="UTC", odata.maxpagesize=50'])->send($method, $url, $body ? ['json' => $body] : []);
        if ($r->failed() && $r->status() !== 404) {
            throw new MailProtocolException('Calendrier Outlook : '.($r->json('error.message') ?: 'erreur '.$r->status()), $r->status());
        }

        return $r;
    }

    protected function google(Account $a, string $method, string $url, array $body = [], array $query = [])
    {
        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events'.$url;
        $r = Http::withToken(OAuth::token($a))->acceptJson()->timeout(30)->send($method, $url.($query ? '?'.http_build_query($query) : ''), $body ? ['json' => $body] : []);
        if ($r->failed() && ! in_array($r->status(), [404, 410], true)) {
            throw new MailProtocolException('Google Agenda : '.($r->json('error.message') ?: 'erreur '.$r->status()), $r->status());
        }

        return $r;
    }

    protected function link(Account $a, string $externalId)
    {
        return DB::table('ag_event_links')->where('account_id', $a->id)->where('external_id', $externalId)->first();
    }

    // ------------------------------------------------------------------ réception

    protected function pullGraph(Account $a): int
    {
        $url = $a->stateValue('cal_delta') ?: '/calendarView/delta?'.http_build_query([
            'startDateTime' => now()->subDays(30)->utc()->format('Y-m-d\TH:i:s\Z'), 'endDateTime' => now()->addDays(365)->utc()->format('Y-m-d\TH:i:s\Z')]);
        $n = 0;
        $pages = 0;
        while ($url && $pages++ < 20) {
            $r = $this->graph($a, 'GET', $url);
            if ($r->status() === 404 || $r->status() === 410) { // jeton delta expiré : on repart de zéro
                $a->putState(['cal_delta' => null]);
                $a->saveQuietly();

                return $n;
            }
            $j = $r->json();
            foreach ($j['value'] ?? [] as $x) {
                if (isset($x['@removed'])) {
                    $n += $this->removeExternal($a, $x['id']);

                    continue;
                }
                if (($x['type'] ?? '') === 'seriesMaster' || (! empty($x['seriesMasterId']) && $this->link($a, $x['seriesMasterId']))) {
                    continue; // occurrences d'une série créée depuis Yomara : déjà dans l'Agenda
                }
                if (! isset($x['start'])) {
                    continue;
                }
                $allDay = (bool) ($x['isAllDay'] ?? false);
                $tz = config('app.timezone');
                $start = Carbon::parse($x['start']['dateTime'], 'UTC')->setTimezone($tz);
                $end = Carbon::parse($x['end']['dateTime'], 'UTC')->setTimezone($tz);
                if ($allDay) {
                    $start = Carbon::parse(substr($x['start']['dateTime'], 0, 10), $tz)->startOfDay();
                    $end = Carbon::parse(substr($x['end']['dateTime'], 0, 10), $tz)->subDay()->endOfDay();
                }
                $n += $this->upsertExternal($a, $x['id'], $x['changeKey'] ?? null, [
                    'title' => mb_substr($x['subject'] ?? '(sans titre)', 0, 200) ?: '(sans titre)',
                    'description' => isset($x['bodyPreview']) && trim($x['bodyPreview']) !== '' ? mb_substr($x['bodyPreview'], 0, 10000) : null,
                    'location' => isset($x['location']['displayName']) && $x['location']['displayName'] !== '' ? mb_substr($x['location']['displayName'], 0, 255) : null,
                    'online_link' => $x['onlineMeeting']['joinUrl'] ?? ($x['onlineMeetingUrl'] ?? null),
                    'starts_at' => $start, 'ends_at' => $end->lt($start) ? $start->copy()->addHour() : $end, 'all_day' => $allDay,
                    'visibility' => in_array($x['sensitivity'] ?? 'normal', ['private', 'confidential'], true) ? 'private' : 'public',
                    'status' => ($x['isCancelled'] ?? false) ? 'cancelled' : 'confirmed',
                    'reminder_minutes' => ($x['isReminderOn'] ?? false) ? (int) ($x['reminderMinutesBeforeStart'] ?? 15) : null,
                    'guests' => array_values(array_filter(array_map(fn ($p) => ['name' => $p['emailAddress']['name'] ?? null, 'email' => mb_strtolower($p['emailAddress']['address'] ?? '')], $x['attendees'] ?? []),
                        fn ($g) => $g['email'] !== '' && $g['email'] !== mb_strtolower($a->email))),
                ]);
            }
            if (isset($j['@odata.deltaLink'])) {
                $a->putState(['cal_delta' => $j['@odata.deltaLink']]);
                $url = null;
            } else {
                $url = $j['@odata.nextLink'] ?? null;
            }
        }
        $a->saveQuietly();

        return $n;
    }

    protected function pullGoogle(Account $a): int
    {
        $token = $a->stateValue('cal_sync_token');
        $q = $token ? ['syncToken' => $token, 'maxResults' => 250, 'showDeleted' => 'true']
            : ['timeMin' => now()->subDays(30)->toRfc3339String(), 'timeMax' => now()->addDays(365)->toRfc3339String(), 'singleEvents' => 'true', 'maxResults' => 250, 'showDeleted' => 'true'];
        $n = 0;
        $pages = 0;
        do {
            $r = $this->google($a, 'GET', '', [], $q);
            if ($r->status() === 410) {
                $a->putState(['cal_sync_token' => null]);
                $a->saveQuietly();

                return $this->pullGoogle($a);
            }
            $j = $r->json();
            foreach ($j['items'] ?? [] as $x) {
                if (($x['status'] ?? '') === 'cancelled') {
                    $n += $this->removeExternal($a, $x['id']);

                    continue;
                }
                if (! empty($x['recurringEventId']) && $this->link($a, $x['recurringEventId'])) {
                    continue;
                }
                if (! empty($x['recurrence'])) {
                    continue; // série (renvoyée en plus des occurrences lors des mises à jour)
                }
                $tz = config('app.timezone');
                $allDay = isset($x['start']['date']);
                $start = $allDay ? Carbon::parse($x['start']['date'], $tz)->startOfDay() : Carbon::parse($x['start']['dateTime'])->setTimezone($tz);
                $end = $allDay ? Carbon::parse($x['end']['date'], $tz)->subDay()->endOfDay() : Carbon::parse($x['end']['dateTime'])->setTimezone($tz);
                $n += $this->upsertExternal($a, $x['id'], $x['etag'] ?? null, [
                    'title' => mb_substr($x['summary'] ?? '(sans titre)', 0, 200) ?: '(sans titre)',
                    'description' => isset($x['description']) ? mb_substr(strip_tags($x['description']), 0, 10000) : null,
                    'location' => isset($x['location']) ? mb_substr($x['location'], 0, 255) : null,
                    'online_link' => $x['hangoutLink'] ?? null,
                    'starts_at' => $start, 'ends_at' => $end->lt($start) ? $start->copy()->addHour() : $end, 'all_day' => $allDay,
                    'visibility' => in_array($x['visibility'] ?? 'default', ['private', 'confidential'], true) ? 'private' : 'public',
                    'status' => 'confirmed',
                    'reminder_minutes' => isset($x['reminders']['overrides'][0]['minutes']) ? (int) $x['reminders']['overrides'][0]['minutes'] : null,
                    'guests' => array_values(array_filter(array_map(fn ($p) => ['name' => $p['displayName'] ?? null, 'email' => mb_strtolower($p['email'] ?? '')], $x['attendees'] ?? []),
                        fn ($g) => $g['email'] !== '' && $g['email'] !== mb_strtolower($a->email))),
                ]);
            }
            $q = array_merge(array_diff_key($q, ['pageToken' => 1]), isset($j['nextPageToken']) ? ['pageToken' => $j['nextPageToken']] : []);
            if (isset($j['nextSyncToken'])) {
                $a->putState(['cal_sync_token' => $j['nextSyncToken']]);
            }
        } while (isset($j['nextPageToken']) && $pages++ < 20);
        $a->saveQuietly();

        return $n;
    }

    protected function upsertExternal(Account $a, string $extId, ?string $etag, array $data): int
    {
        $link = $this->link($a, $extId);
        $e = $link ? Event::withTrashed()->find($link->event_id) : null;
        if ($link && $e && $link->etag === $etag && $etag !== null) {
            return 0;
        }
        if ($e && $e->updated_at && $link->synced_at && $e->updated_at->gt(Carbon::parse($link->synced_at)->addSecond())) {
            return 0; // modifié dans Yomara depuis le dernier échange : notre version partira à l'envoi
        }
        $creating = ! $e;
        $e ??= new Event;
        $guests = $data['guests'];
        unset($data['guests']);
        $e->forceFill($data + ($creating ? ['category' => $guests ? 'meeting' : 'other', 'owner_id' => $a->user_id, 'attendee_ids' => [$a->user_id]] : []));
        if ($creating || ! $e->guests) {
            $e->guests = $guests ?: null;
        }
        if ($e->trashed()) {
            $e->deleted_at = null;
        }
        $e->save();
        $e->refresh();
        DB::table('ag_event_links')->updateOrInsert(['account_id' => $a->id, 'external_id' => $extId],
            ['event_id' => $e->id, 'etag' => $etag, 'synced_at' => $e->updated_at, 'updated_at' => now(), 'created_at' => $link->created_at ?? now()]);

        return 1;
    }

    protected function removeExternal(Account $a, string $extId): int
    {
        $link = $this->link($a, $extId);
        if (! $link) {
            return 0;
        }
        DB::table('ag_event_links')->where('id', $link->id)->delete();
        $e = Event::find($link->event_id);
        if ($e && (int) $e->owner_id === (int) $a->user_id) {
            $e->delete();

            return 1;
        }

        return 0;
    }

    // ------------------------------------------------------------------ envoi

    protected function push(Account $a): int
    {
        $n = 0;
        $links = DB::table('ag_event_links')->where('account_id', $a->id)->get()->keyBy('event_id');
        // suppressions faites dans Yomara
        $deleted = Event::onlyTrashed()->whereIn('id', $links->keys())->get();
        foreach ($deleted as $e) {
            $l = $links[$e->id];
            $a->provider === 'microsoft' ? $this->graph($a, 'DELETE', '/events/'.$l->external_id) : $this->google($a, 'DELETE', '/'.$l->external_id);
            DB::table('ag_event_links')->where('id', $l->id)->delete();
            $n++;
        }
        $events = Event::where('owner_id', $a->user_id)->where('ends_at', '>=', now()->subDays(30))->where('starts_at', '<=', now()->addDays(365))
            ->orderBy('starts_at')->limit(500)->get();
        foreach ($events as $e) {
            $l = $links[$e->id] ?? null;
            if ($l && $l->synced_at && ! $e->updated_at->gt(Carbon::parse($l->synced_at)->addSecond())) {
                continue;
            }
            if (! $l && $e->status === 'cancelled') {
                continue;
            }
            try {
                if ($l && $e->status === 'cancelled') {
                    $a->provider === 'microsoft' ? $this->graph($a, 'DELETE', '/events/'.$l->external_id) : $this->google($a, 'DELETE', '/'.$l->external_id);
                    DB::table('ag_event_links')->where('id', $l->id)->delete();
                    $n++;

                    continue;
                }
                [$extId, $etag, $join] = $a->provider === 'microsoft' ? $this->pushGraph($a, $e, $l) : $this->pushGoogle($a, $e, $l);
                if ($join && in_array(mb_strtolower((string) $e->online_link), ['teams', 'meet'], true)) {
                    $e->online_link = $join;
                    $e->save();
                    $e->refresh();
                }
                DB::table('ag_event_links')->updateOrInsert(['account_id' => $a->id, 'external_id' => $extId],
                    ['event_id' => $e->id, 'etag' => $etag, 'synced_at' => $e->updated_at, 'updated_at' => now(), 'created_at' => $l->created_at ?? now()]);
                $n++;
            } catch (MailProtocolException $ex) {
                if ($ex->getCode() === 401) {
                    throw $ex;
                }
            }
        }

        return $n;
    }

    protected function pushGraph(Account $a, Event $e, $link): array
    {
        $tz = config('app.timezone');
        $start = Carbon::parse($e->starts_at, $tz);
        $end = Carbon::parse($e->ends_at, $tz);
        $body = [
            'subject' => $e->title,
            'body' => ['contentType' => 'text', 'content' => (string) $e->description],
            'start' => $e->all_day ? ['dateTime' => $start->format('Y-m-d').'T00:00:00', 'timeZone' => 'UTC'] : ['dateTime' => $start->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end' => $e->all_day ? ['dateTime' => $end->copy()->addDay()->format('Y-m-d').'T00:00:00', 'timeZone' => 'UTC'] : ['dateTime' => $end->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'isAllDay' => (bool) $e->all_day,
            'location' => ['displayName' => (string) $e->location],
            'sensitivity' => $e->visibility === 'private' ? 'private' : 'normal',
            'isReminderOn' => $e->reminder_minutes !== null,
            'reminderMinutesBeforeStart' => (int) ($e->reminder_minutes ?? 15),
        ];
        if ($e->guests && ! $link) { // invités : Outlook leur envoie l'invitation (avec le lien Teams)
            $body['attendees'] = array_values(array_map(fn ($g) => ['emailAddress' => ['address' => $g['email'], 'name' => $g['name'] ?? $g['email']], 'type' => 'required'],
                array_filter((array) $e->guests, fn ($g) => ! empty($g['email']))));
        }
        $online = mb_strtolower((string) $e->online_link);
        if ($online === 'teams') {
            $body['isOnlineMeeting'] = true;
            $body['onlineMeetingProvider'] = 'teamsForBusiness';
        }
        if (! $link && ($pattern = self::graphRecurrence($e))) {
            $body['recurrence'] = $pattern;
        }
        $r = $link ? $this->graph($a, 'PATCH', '/events/'.$link->external_id, $body) : $this->graph($a, 'POST', '/events', $body);
        if ($r->status() === 404 && $link) { // supprimé côté Outlook entre-temps : on le recrée
            DB::table('ag_event_links')->where('id', $link->id)->delete();
            $r = $this->graph($a, 'POST', '/events', $body);
        }
        $j = $r->json();

        return [$j['id'], $j['changeKey'] ?? null, $j['onlineMeeting']['joinUrl'] ?? null];
    }

    public static function graphRecurrence(Event $e): ?array
    {
        $rule = $e->recurrence;
        if (! $rule || empty($rule['freq'])) {
            return null;
        }
        $start = Carbon::parse($e->starts_at);
        $days = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
        $pattern = ['interval' => max(1, (int) ($rule['interval'] ?? 1))];
        switch ($rule['freq']) {
            case 'daily': $pattern['type'] = 'daily';
                break;
            case 'weekly': $pattern['type'] = 'weekly';
                $pattern['daysOfWeek'] = array_map(fn ($d) => $days[(int) $d] ?? 'monday', (array) ($rule['byday'] ?? [$start->isoWeekday()]) ?: [$start->isoWeekday()]);
                $pattern['firstDayOfWeek'] = 'monday';
                break;
            case 'monthly': $pattern['type'] = 'absoluteMonthly';
                $pattern['dayOfMonth'] = $start->day;
                break;
            case 'yearly': $pattern['type'] = 'absoluteYearly';
                $pattern['dayOfMonth'] = $start->day;
                $pattern['month'] = $start->month;
                break;
            default: return null;
        }
        $range = ['startDate' => $start->format('Y-m-d'), 'type' => 'noEnd'];
        if (! empty($rule['until'])) {
            $range = ['startDate' => $start->format('Y-m-d'), 'type' => 'endDate', 'endDate' => Carbon::parse($rule['until'])->format('Y-m-d')];
        } elseif (! empty($rule['count'])) {
            $range = ['startDate' => $start->format('Y-m-d'), 'type' => 'numbered', 'numberOfOccurrences' => (int) $rule['count']];
        }

        return ['pattern' => $pattern, 'range' => $range];
    }

    protected function pushGoogle(Account $a, Event $e, $link): array
    {
        $tz = config('app.timezone');
        $start = Carbon::parse($e->starts_at, $tz);
        $end = Carbon::parse($e->ends_at, $tz);
        $body = [
            'summary' => $e->title,
            'description' => (string) $e->description,
            'location' => (string) $e->location,
            'start' => $e->all_day ? ['date' => $start->format('Y-m-d')] : ['dateTime' => $start->toRfc3339String()],
            'end' => $e->all_day ? ['date' => $end->copy()->addDay()->format('Y-m-d')] : ['dateTime' => $end->toRfc3339String()],
            'visibility' => $e->visibility === 'private' ? 'private' : 'default',
            'reminders' => $e->reminder_minutes !== null ? ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => (int) $e->reminder_minutes]]] : ['useDefault' => true],
        ];
        if (mb_strtolower((string) $e->online_link) === 'meet') {
            $body['conferenceData'] = ['createRequest' => ['requestId' => (string) Str::uuid(), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
        }
        if (! $link && ($rr = Recurrence::rrule($e->recurrence))) {
            $body['recurrence'] = ['RRULE:'.$rr];
        }
        if ($e->guests && ! $link) { // invités : Google leur envoie l'invitation (avec le lien Meet)
            $body['attendees'] = array_values(array_map(fn ($g) => ['email' => $g['email'], 'displayName' => $g['name'] ?? null], array_filter((array) $e->guests, fn ($g) => ! empty($g['email']))));
        }
        $q = ['conferenceDataVersion' => 1, 'sendUpdates' => $e->guests && ! $link ? 'all' : 'none'];
        $r = $link ? $this->google($a, 'PATCH', '/'.$link->external_id, $body, $q) : $this->google($a, 'POST', '', $body, $q);
        if (in_array($r->status(), [404, 410], true) && $link) {
            DB::table('ag_event_links')->where('id', $link->id)->delete();
            $r = $this->google($a, 'POST', '', $body, $q);
        }
        $j = $r->json();

        return [$j['id'], $j['etag'] ?? null, $j['hangoutLink'] ?? null];
    }
}
