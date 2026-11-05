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

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Un passage enregistré ne se supprime pas.');
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['ticket_id' => $this->ticket_id, 'result' => $this->result, 'reason' => $this->reason, 'user_id' => $this->user_id];
    }
}
