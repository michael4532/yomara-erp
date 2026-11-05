<?php

namespace App\Modules\Helpdesk\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Helpdesk\HelpdeskService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Temps passé sur un ticket (décompté du forfait du client si le ticket en a un). */
class TimeEntry extends Model
{
    use Syncable;

    protected $table = 'hd_times';

    public static string $syncEntity = 'hd_times';

    public static string $viewPermission = 'helpdesk.view|helpdesk.agent|helpdesk.manage';

    public static string $managePermission = 'helpdesk.agent|helpdesk.manage';

    protected $fillable = ['ticket_id', 'date', 'minutes', 'note', 'billable'];

    protected $attributes = ['billable' => true];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'minutes' => 'integer', 'billable' => 'boolean', 'user_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'ticket_id' => ['required', 'uuid', Rule::exists('hd_tickets', 'id')->where('company_id', app('tenant')->id)->whereNull('deleted_at')],
            'date' => 'required|date',
            'minutes' => 'required|integer|min:1|max:1440',
            'note' => 'nullable|string|max:255',
            'billable' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $u = auth()->user();
        if ($creating) {
            $this->user_id = $u?->id;
            $this->user_name = $u?->name;
        } elseif ($u && $this->user_id !== $u->id && ! $u->hasPermission('helpdesk.manage')) {
            throw new \DomainException('Seul l\'auteur ou un responsable modifie ce temps.');
        }
        if (! $creating && isset($data['ticket_id']) && $data['ticket_id'] !== $this->ticket_id) {
            throw new \DomainException('Le temps ne change pas de ticket.');
        }
        if (($data['date'] ?? $this->date?->toDateString()) > now()->addDay()->toDateString()) {
            throw new \DomainException('Date future refusée.');
        }
        HelpdeskService::weekLock($this->user_id, $data['date'] ?? $this->date?->toDateString(), $creating ? null : $this->date?->toDateString());
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        app(HelpdeskService::class)->syncTime($this);
    }

    public function beforeEntityDelete(): void
    {
        $u = auth()->user();
        if ($u && $this->user_id !== $u->id && ! $u->hasPermission('helpdesk.manage')) {
            throw new \DomainException('Seul l\'auteur ou un responsable supprime ce temps.');
        }
        HelpdeskService::weekLock($this->user_id, $this->date?->toDateString());
    }

    protected static function booted(): void
    {
        static::deleted(fn (self $t) => app(HelpdeskService::class)->unlinkTime($t));
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['user_id' => $this->user_id, 'user_name' => $this->user_name];
    }
}
