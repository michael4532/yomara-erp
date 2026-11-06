<?php

namespace App\Modules\Agenda\Models;

use App\Models\AppNotification;
use App\Models\Concerns\Syncable;
use App\Modules\Agenda\AgendaService;
use App\Modules\Agenda\Recurrence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Événement d'agenda : réunion, rendez-vous, déplacement… (synchronisé hors ligne). */
class Event extends Model
{
    use Syncable;

    protected $table = 'ag_events';

    public static string $syncEntity = 'ag_events';

    public static string $viewPermission = 'agenda.use|agenda.manage';

    public static string $managePermission = 'agenda.use|agenda.manage';

    protected $fillable = ['title', 'description', 'location', 'online_link', 'starts_at', 'ends_at', 'all_day', 'category', 'color', 'visibility',
        'attendee_ids', 'responses', 'guests', 'resource_id', 'recurrence', 'exceptions', 'reminder_minutes', 'status', 'yomeet'];

    public const CATEGORIES = ['meeting' => 'Réunion', 'appointment' => 'Rendez-vous', 'travel' => 'Déplacement', 'training' => 'Formation', 'personal' => 'Personnel', 'other' => 'Autre'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime:Y-m-d H:i:s', 'ends_at' => 'datetime:Y-m-d H:i:s', 'all_day' => 'boolean', 'attendee_ids' => 'array', 'responses' => 'array', 'guests' => 'array',
            'recurrence' => 'array', 'exceptions' => 'array', 'yomeet' => 'array', 'reminder_minutes' => 'integer', 'owner_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:10000',
            'location' => 'nullable|string|max:255',
            'online_link' => 'nullable|string|max:500',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date',
            'all_day' => 'nullable|boolean',
            'category' => 'nullable|in:'.implode(',', array_keys(self::CATEGORIES)),
            'color' => 'nullable|string|max:9',
            'visibility' => 'nullable|in:public,private',
            'attendee_ids' => 'nullable|array|max:100',
            'attendee_ids.*' => 'integer',
            'responses' => 'nullable|array',
            'guests' => 'nullable|array|max:30',
            'guests.*.name' => 'nullable|string|max:120',
            'guests.*.email' => 'nullable|email|max:255',
            'guests.*.phone' => 'nullable|string|max:40',
            'resource_id' => ['nullable', 'uuid', Rule::exists('ag_resources', 'id')->where('company_id', app('tenant')->id)->whereNull('deleted_at')],
            'recurrence' => 'nullable|array',
            'recurrence.freq' => 'required_with:recurrence|in:'.implode(',', Recurrence::FREQS),
            'recurrence.interval' => 'nullable|integer|min:1|max:99',
            'recurrence.byday' => 'nullable|array',
            'recurrence.byday.*' => 'integer|min:1|max:7',
            'recurrence.until' => 'nullable|date',
            'recurrence.count' => 'nullable|integer|min:1|max:500',
            'exceptions' => 'nullable|array|max:500',
            'exceptions.*' => 'date_format:Y-m-d',
            'reminder_minutes' => 'nullable|integer|min:0|max:20160',
            'status' => 'nullable|in:confirmed,cancelled',
            'yomeet' => 'nullable|array',
            'yomeet.enabled' => 'nullable|boolean',
            'yomeet.password' => 'nullable|string|max:40',
            'yomeet.lobby' => 'nullable|boolean', 'yomeet.mute_on_entry' => 'nullable|boolean', 'yomeet.guests' => 'nullable|boolean',
            'yomeet.record_auto' => 'nullable|boolean', 'yomeet.ai_report' => 'nullable|boolean',
        ];
    }

    public function isAttendee(?int $userId): bool
    {
        return $userId && in_array($userId, array_map('intval', (array) $this->attendee_ids), true);
    }

    public function canSeeDetails($user): bool
    {
        return $this->visibility !== 'private' || (int) $this->owner_id === (int) $user?->id || $this->isAttendee($user?->id);
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $manager = $user?->hasPermission('agenda.manage');
        if (! $creating && $user && (int) $this->owner_id !== (int) $user->id && ! $manager) {
            // Invité : il ne peut que répondre (accepter, peut-être, refuser)
            if (! $this->isAttendee($user->id)) {
                throw new \DomainException('Seul l\'organisateur peut modifier cet événement.');
            }
            $resp = (array) ($data['responses'] ?? []);
            $status = $resp[(string) $user->id] ?? null;
            if (! in_array($status, ['accepted', 'tentative', 'declined'], true)) {
                throw new \DomainException('Seul l\'organisateur peut modifier cet événement.');
            }
            $data = ['responses' => array_replace((array) $this->responses, [(string) $user->id => $status])];

            return;
        }
        if ($creating) {
            $this->owner_id = $user?->id;
            $data['status'] ??= 'confirmed';
            $data['visibility'] ??= 'public';
            $data['category'] ??= 'meeting';
            $data['all_day'] ??= false;
        }
        if (($data['visibility'] ?? null) === 'private' && ! $creating && (int) $this->owner_id !== (int) $user?->id) {
            throw new \DomainException('Seul l\'organisateur peut rendre un événement privé.');
        }
        $allDay = (bool) ($data['all_day'] ?? $this->all_day);
        $start = Carbon::parse($data['starts_at'] ?? $this->starts_at);
        $end = Carbon::parse($data['ends_at'] ?? $this->ends_at);
        if ($allDay) {
            $start = $start->copy()->startOfDay();
            $end = $end->copy()->setTime(23, 59, 59);
        }
        if ($end->lt($start)) {
            throw new \DomainException('La fin doit être après le début.');
        }
        if ($end->diffInDays($start, true) > 62) {
            throw new \DomainException('Un événement ne peut pas durer plus de deux mois (utilisez la récurrence).');
        }
        if (array_key_exists('starts_at', $data) || array_key_exists('all_day', $data)) {
            $data['starts_at'] = $start->format('Y-m-d H:i:s');
        }
        if (array_key_exists('ends_at', $data) || array_key_exists('all_day', $data)) {
            $data['ends_at'] = $end->format('Y-m-d H:i:s');
        }
        if (! $creating && isset($data['responses']) && ! array_key_exists('attendee_ids', $data)) {
            $data['responses'] = array_replace((array) $this->responses, (array) $data['responses']);
        }
        if (array_key_exists('attendee_ids', $data)) {
            $ids = array_values(array_unique(array_map('intval', (array) $data['attendee_ids'])));
            $ids = array_values(array_diff($ids, [(int) ($this->owner_id ?? $user?->id)]));
            // seuls les utilisateurs de la société peuvent être invités (pas de notification vers une autre société)
            $ids = $ids ? array_values(array_intersect($ids, \App\Models\User::where('company_id', app('tenant')->id)->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all())) : [];
            $data['attendee_ids'] = $ids;
            $data['responses'] = array_intersect_key((array) ($data['responses'] ?? $this->responses), array_flip(array_map('strval', $ids)));
        }
        if (isset($data['guests'])) {
            $data['guests'] = array_values(array_filter(array_map(fn ($g) => array_filter(['name' => trim((string) ($g['name'] ?? '')), 'email' => trim((string) ($g['email'] ?? '')), 'phone' => trim((string) ($g['phone'] ?? ''))]), (array) $data['guests'])));
        }
        $resource = array_key_exists('resource_id', $data) ? $data['resource_id'] : $this->resource_id;
        $status = $data['status'] ?? $this->status ?? 'confirmed';
        if ($resource && $status === 'confirmed') {
            app(AgendaService::class)->assertResourceFree($resource, $this->id, $start, $end, array_key_exists('recurrence', $data) ? $data['recurrence'] : $this->recurrence, (array) ($data['exceptions'] ?? $this->exceptions));
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        $user = auth()->user();
        $prev = $creating ? [] : $this->getPrevious();
        $ids = array_map('intval', (array) $this->attendee_ids);
        $when = app(AgendaService::class)->when($this);
        if (array_keys($data) === ['responses'] && $user && (int) $this->owner_id !== (int) $user->id) {
            $st = ((array) $this->responses)[(string) $user->id] ?? null;
            $label = ['accepted' => 'accepte', 'tentative' => 'participera peut-être à', 'declined' => 'décline'][$st] ?? 'répond à';
            AppNotification::send($this->company_id, [$this->owner_id], 'agenda', $user->name.' '.$label.' « '.$this->title.' »', $when, 'agenda', $this->id);

            return;
        }
        $raw = $creating ? [] : (array_key_exists('attendee_ids', $prev) ? $prev['attendee_ids'] : $ids);
        $before = array_map('intval', (array) (is_array($raw) ? $raw : json_decode((string) $raw, true)));
        $new = array_values(array_diff($ids, $before));
        if ($new && $this->status === 'confirmed') {
            AppNotification::send($this->company_id, $new, 'agenda', 'Invitation : '.$this->title, $when, 'agenda', $this->id, $user?->id);
        }
        $old = array_values(array_intersect($ids, $before));
        if (! $creating && $old) {
            if (($prev['status'] ?? $this->status) === 'confirmed' && $this->status === 'cancelled') {
                AppNotification::send($this->company_id, $old, 'agenda', 'Annulé : '.$this->title, $when, 'agenda', $this->id, $user?->id);
            } elseif ($this->wasChanged(['starts_at', 'ends_at', 'location', 'recurrence'])) {
                AppNotification::send($this->company_id, $old, 'agenda', 'Modifié : '.$this->title, $when, 'agenda', $this->id, $user?->id);
            }
        }
        // Yomeet : salle de visio intégrée créée ou mise à jour avec l'événement
        if (array_key_exists('yomeet', $data) || ! empty($this->yomeet['enabled'])) {
            if (array_key_exists('yomeet', $data) && is_array($data['yomeet'])) {
                // On garde la salle déjà créée (identifiant, lien) quand l'application renvoie seulement les options
                $prevY = $prev['yomeet'] ?? null;
                $prevY = is_array($prevY) ? $prevY : (json_decode((string) $prevY, true) ?: []);
                $this->yomeet = array_merge($prevY, $data['yomeet']);
            }
            rescue(fn () => \App\Modules\Visio\YomeetBridge::sync(app('tenant'), $this), null, true);
        }
        if (! empty($data['notify_guests']) && $this->guests && $this->status === 'confirmed') {
            app(AgendaService::class)->inviteGuests($this);
        }
    }

    public function beforeEntityDelete(): void
    {
        if (! empty($this->yomeet['meeting_id'])) {
            rescue(fn () => \App\Modules\Visio\YomeetBridge::cancel(app('tenant'), $this), null, false);
        }
        $user = auth()->user();
        if ($user && (int) $this->owner_id !== (int) $user->id && ! $user->hasPermission('agenda.manage')) {
            throw new \DomainException('Seul l\'organisateur peut supprimer cet événement.');
        }
        if ($this->attendee_ids && $this->ends_at->isFuture()) {
            AppNotification::send($this->company_id, array_map('intval', $this->attendee_ids), 'agenda', 'Annulé : '.$this->title, app(AgendaService::class)->when($this), 'agenda', $this->id, $user?->id);
        }
    }

    /** Événement privé d'un collègue : seulement « Occupé ». */
    public function toSyncArray(): array
    {
        $a = $this->toArray();
        if (! $this->canSeeDetails(auth()->user())) {
            $a = array_merge($a, ['title' => 'Occupé', 'description' => null, 'location' => null, 'online_link' => null, 'guests' => [], 'attendee_ids' => [], 'responses' => [], 'masked' => true]);
        }

        return $a;
    }
}
