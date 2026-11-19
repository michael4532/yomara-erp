<?php

namespace App\Modules\Billetterie\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Billetterie\TicketService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Passage au contrôle d'entrée (enregistré même hors ligne, réévalué par le serveur). */
class Checkin extends Model
{
    use Syncable;

    protected $table = 'ev_checkins';

    public static string $syncEntity = 'ev_checkins';

    public static string $viewPermission = 'billetterie.scan|billetterie.manage';

    public static string $managePermission = 'billetterie.scan|billetterie.manage';

    protected $fillable = ['event_id', 'code', 'scanned_at', 'gate'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'event_id' => ['required', 'uuid', Rule::exists('ev_events', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'code' => 'required|string|max:80',
            'scanned_at' => 'nullable|date',
            'gate' => 'nullable|string|max:60',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            throw new \DomainException('Un passage enregistré ne se modifie pas.');
        }
        $at = ! empty($data['scanned_at']) ? \Illuminate\Support\Carbon::parse($data['scanned_at']) : now();
        $data['scanned_at'] = ($at->gt(now()->addMinutes(5)) ? now() : $at)->toDateTimeString();
        $this->user_id = auth()->id();
        [$ticket, $result, $reason] = app(TicketService::class)->evaluate($data['event_id'], $data['code'], \Illuminate\Support\Carbon::parse($data['scanned_at']));
        $this->ticket_id = $ticket?->id;
        $this->result = $result;
        $this->reason = $reason;
        if ($ticket && $result === 'ok') {
            $ticket->checked_in_at ??= \Illuminate\Support\Carbon::parse($data['scanned_at']);
            $ticket->last_checkin_at = \Illuminate\Support\Carbon::parse($data['scanned_at']);
            $ticket->checkins_count++;
            $ticket->save();
        }
    }

    /** Visiteur revenu un autre jour que celui de son inscription : e-mail de bienvenue du jour (après la réponse). */
    public function afterEntityWrite(array $data, bool $creating): void
    {
        if (! $creating || $this->result !== 'ok' || ! $this->ticket_id) {
            return;
        }
        $ticket = Ticket::find($this->ticket_id);
        if (! $ticket || ! in_array($ticket->kind, ['visitor', 'vip', 'press'], true)) {
            return;
        }
        $at = \Illuminate\Support\Carbon::parse($this->scanned_at);
        if ($at->lt(now()->subHours(12))) {
            return; // borne restée hors ligne longtemps : pas d'e-mail « bienvenue aujourd'hui » à contretemps
        }
        $tz = config('app.timezone');
        $day = $at->copy()->timezone($tz)->toDateString();
        $order = $ticket->order_id ? TicketOrder::find($ticket->order_id) : null;
        $registered = $order ? \Illuminate\Support\Carbon::parse($order->registered_at ?? $order->created_at)->timezone($tz)->toDateString() : null;
        $before = self::where('ticket_id', $ticket->id)->where('result', 'ok')->where('id', '!=', $this->id)->get(['scanned_at'])
            ->map(fn ($c) => $c->scanned_at->copy()->timezone($tz)->toDateString());
        if ($before->contains($day)) {
            return; // déjà accueilli aujourd'hui
        }
        if (($registered === null || $registered >= $day) && $before->filter(fn ($d) => $d < $day)->isEmpty()) {
            return; // jour de l'inscription : l'e-mail de confirmation suffit
        }
        $company = app('tenant');
        $ticketId = $ticket->id;
        dispatch(function () use ($company, $ticketId, $day) {
            // une seule fois par visiteur et par jour, même si plusieurs appareils l'accueillent en même temps
            if (! \Illuminate\Support\Facades\Cache::add('ev-welcome:'.$ticketId.':'.$day, 1, now()->addDays(2))) {
                return;
            }
            app()->instance('tenant', $company);
            $t = Ticket::withoutGlobalScopes()->find($ticketId);
            if ($t) {
                app(\App\Modules\Billetterie\RegistrationService::class)->welcomeBack($company, $t, $day);
            }
        })->afterResponse();
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Un passage enregistré ne se supprime pas.');
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['ticket_id' => $this->ticket_id, 'result' => $this->result, 'reason' => $this->reason, 'user_id' => $this->user_id];
    }
}
